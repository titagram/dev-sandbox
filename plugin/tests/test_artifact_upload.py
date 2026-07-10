import json
from pathlib import Path
import hashlib
from contextlib import contextmanager

import httpx
import pytest

from devboard_plugin import artifacts as artifacts_module
from devboard_plugin.artifacts import upload_delta_bundle, upload_genesis_bundle
from devboard_plugin.client import DevBoardApiError, DevBoardClient


class FakeArtifactClient:
    def __init__(self):
        self.started = None
        self.started_delta = None
        self.chunks = []
        self.delta_chunks = []
        self.finalized = None
        self.finalized_delta = None
        self.finished = None

    def start_genesis_import(self, repository_id, manifest, run_id, local_workspace_id):
        self.started = (repository_id, manifest, run_id, local_workspace_id)
        return {"import_id": "gen_123", "artifacts": manifest["artifacts"]}

    def upload_genesis_chunk(self, import_id, artifact_id, chunk_index, content):
        self.chunks.append((import_id, artifact_id, chunk_index, content))
        return {"status": "received"}

    def finalize_genesis_import(self, import_id, allow_blocked_security_findings=False):
        self.finalized = import_id
        self.finalize_allow_blocked_security_findings = allow_blocked_security_findings
        return {"status": "active"}

    def start_delta_sync(self, run_id, manifest, local_workspace_id, base_snapshot_id):
        self.started_delta = (
            run_id,
            {
                "manifest": manifest,
                "local_workspace_id": local_workspace_id,
                "base_snapshot_id": base_snapshot_id,
            },
        )
        return {"delta_id": "delta_123", "status": "uploading"}

    def upload_delta_chunk(self, delta_id, artifact_id, chunk_index, content):
        self.delta_chunks.append((delta_id, artifact_id, chunk_index, content))
        return {"status": "received"}

    def finalize_delta_sync(self, delta_id, allow_blocked_security_findings=False):
        self.finalized_delta = delta_id
        self.finalize_delta_allow_blocked_security_findings = allow_blocked_security_findings
        return {"status": "active"}

    def finish_run(self, run_id, payload):
        self.finished = (run_id, payload)
        return {"run_id": run_id, "status": payload["status"]}


def test_upload_genesis_bundle_reads_manifest_uploads_chunks_and_finalizes(tmp_path):
    artifact_content = b'{"files":[]}'
    bundle = _write_bundle(tmp_path, artifact_content, chunk_size=5)
    fake = FakeArtifactClient()

    result = upload_genesis_bundle(
        fake,
        repository_id="repo_123",
        run_id="run_123",
        local_workspace_id="lw_123",
        bundle_path=bundle,
        chunk_size=5,
    )

    assert fake.started[0] == "repo_123"
    assert fake.started[1]["artifacts"][0]["artifact_id"] == "art_123"
    artifact_chunks = [chunk for chunk in fake.chunks if chunk[1] == "art_123"]
    assert [chunk[2] for chunk in artifact_chunks] == [0, 1, 2]
    assert fake.finalized == "gen_123"
    assert result["status"] == "active"


def test_upload_genesis_bundle_streams_large_file_in_chunks(tmp_path):
    payload = b"x" * 100_000
    bundle = _write_bundle(tmp_path, payload, chunk_size=8192)
    fake = FakeArtifactClient()

    upload_genesis_bundle(
        fake,
        repository_id="repo_123",
        run_id="run_123",
        local_workspace_id="lw_123",
        bundle_path=bundle,
        chunk_size=8192,
    )

    artifact_chunks = [chunk for chunk in fake.chunks if chunk[1] == "art_123"]
    uploaded = b"".join(chunk[3] for chunk in artifact_chunks)
    assert uploaded == payload
    assert len(artifact_chunks) == 13
    assert [chunk[2] for chunk in artifact_chunks] == list(range(13))


def test_upload_genesis_bundle_finishes_run_after_successful_finalize(tmp_path):
    artifact_content = b'{"files":[]}'
    bundle = _write_bundle(tmp_path, artifact_content)
    fake = FakeArtifactClient()

    upload_genesis_bundle(
        fake,
        repository_id="repo_123",
        run_id="run_123",
        local_workspace_id="lw_123",
        bundle_path=bundle,
    )

    assert fake.finished == (
        "run_123",
        {"status": "finished", "summary": "Genesis import completed."},
    )


def test_upload_genesis_bundle_requires_approval_before_uploading_blocked_security_report(tmp_path):
    bundle = _write_bundle(tmp_path, b'{"files":[]}')
    _add_security_report(
        bundle,
        "genesis-manifest.json",
        "security_art_123",
        [{"path": ".env", "reason": "env_file", "secret": "not returned"}],
    )
    fake = FakeArtifactClient()

    result = upload_genesis_bundle(
        fake,
        repository_id="repo_123",
        run_id="run_123",
        local_workspace_id="lw_123",
        bundle_path=bundle,
    )

    assert result["status"] == "requires_security_approval"
    assert result["blocked_count"] == 1
    assert result["blocked_findings"] == [{"path": ".env", "reason": "env_file"}]
    assert fake.started is None
    assert fake.finalized is None
    assert fake.finished is None


def test_upload_genesis_bundle_forwards_explicit_security_approval_to_finalize(tmp_path):
    bundle = _write_bundle(tmp_path, b'{"files":[]}')
    _add_security_report(
        bundle,
        "genesis-manifest.json",
        "security_art_123",
        [{"path": ".env", "reason": "env_file"}],
    )
    fake = FakeArtifactClient()

    result = upload_genesis_bundle(
        fake,
        repository_id="repo_123",
        run_id="run_123",
        local_workspace_id="lw_123",
        bundle_path=bundle,
        allow_blocked_security_findings=True,
    )

    assert result["status"] == "active"
    assert fake.finalized == "gen_123"
    assert fake.finalize_allow_blocked_security_findings is True


def test_upload_delta_bundle_uses_delta_endpoints_and_finishes_run(tmp_path):
    artifact_content = b'{"changed_file_count":1}'
    bundle = _write_delta_bundle(tmp_path, artifact_content)
    fake = FakeArtifactClient()

    result = upload_delta_bundle(
        fake,
        run_id="run_123",
        local_workspace_id="lw_123",
        base_snapshot_id="snap_base",
        bundle_path=bundle,
    )

    assert result == {"status": "active"}
    assert fake.started_delta[0] == "run_123"
    assert fake.started_delta[1]["base_snapshot_id"] == "snap_base"
    assert [chunk for chunk in fake.delta_chunks if chunk[1] == "art_delta"] == [
        ("delta_123", "art_delta", 0, artifact_content)
    ]
    assert fake.finalized_delta == "delta_123"
    assert fake.finished == (
        "run_123",
        {"status": "finished", "summary": "Delta sync completed."},
    )


def test_upload_delta_bundle_requires_approval_before_uploading_blocked_security_report(tmp_path):
    bundle = _write_delta_bundle(tmp_path, b'{"changed_file_count":1}')
    _add_security_report(
        bundle,
        "delta-manifest.json",
        "security_delta",
        [{"path": ".env", "reason": "env_file"}],
    )
    fake = FakeArtifactClient()

    result = upload_delta_bundle(
        fake,
        run_id="run_123",
        local_workspace_id="lw_123",
        base_snapshot_id="snap_base",
        bundle_path=bundle,
    )

    assert result["status"] == "requires_security_approval"
    assert result["blocked_findings"] == [{"path": ".env", "reason": "env_file"}]
    assert fake.started_delta is None
    assert fake.finalized_delta is None
    assert fake.finished is None


def test_upload_delta_bundle_forwards_explicit_security_approval_to_finalize(tmp_path):
    bundle = _write_delta_bundle(tmp_path, b'{"changed_file_count":1}')
    _add_security_report(
        bundle,
        "delta-manifest.json",
        "security_delta",
        [{"path": ".env", "reason": "env_file"}],
    )
    fake = FakeArtifactClient()

    result = upload_delta_bundle(
        fake,
        run_id="run_123",
        local_workspace_id="lw_123",
        base_snapshot_id="snap_base",
        bundle_path=bundle,
        allow_blocked_security_findings=True,
    )

    assert result["status"] == "active"
    assert fake.finalized_delta == "delta_123"
    assert fake.finalize_delta_allow_blocked_security_findings is True


@pytest.mark.parametrize("filename", ["../outside.json", "/tmp/outside.json"])
def test_upload_rejects_artifact_paths_outside_bundle_before_network(tmp_path, filename):
    bundle = _write_bundle(tmp_path, b'{"files":[]}')
    manifest_path = bundle / "genesis-manifest.json"
    manifest = json.loads(manifest_path.read_text())
    manifest["artifacts"][0]["filename"] = filename
    manifest_path.write_text(json.dumps(manifest))
    fake = FakeArtifactClient()

    with pytest.raises(DevBoardApiError) as exc_info:
        upload_genesis_bundle(
            fake,
            repository_id="repo_123",
            run_id="run_123",
            local_workspace_id="lw_123",
            bundle_path=bundle,
        )

    assert exc_info.value.code == "invalid_bundle"
    assert fake.started is None


def test_upload_rejects_symlink_artifact_before_network(tmp_path):
    bundle = _write_bundle(tmp_path, b'{"files":[]}')
    target = tmp_path / "outside.json"
    target.write_text("outside")
    (bundle / "file-inventory.json").unlink()
    (bundle / "file-inventory.json").symlink_to(target)
    fake = FakeArtifactClient()

    with pytest.raises(DevBoardApiError, match="symlink"):
        upload_genesis_bundle(
            fake,
            repository_id="repo_123",
            run_id="run_123",
            local_workspace_id="lw_123",
            bundle_path=bundle,
        )

    assert fake.started is None


@pytest.mark.parametrize("field,value", [("sha256", "sha256:" + "0" * 64), ("size_bytes", 999), ("chunk_count", 999)])
def test_upload_verifies_artifact_contract_before_network(tmp_path, field, value):
    bundle = _write_bundle(tmp_path, b'{"files":[]}', chunk_size=5)
    manifest_path = bundle / "genesis-manifest.json"
    manifest = json.loads(manifest_path.read_text())
    manifest["artifacts"][0][field] = value
    manifest_path.write_text(json.dumps(manifest))
    fake = FakeArtifactClient()

    with pytest.raises(DevBoardApiError, match=field):
        upload_genesis_bundle(
            fake,
            repository_id="repo_123",
            run_id="run_123",
            local_workspace_id="lw_123",
            bundle_path=bundle,
            chunk_size=5,
        )

    assert fake.started is None


def test_upload_fails_closed_when_security_report_is_missing_or_invalid(tmp_path):
    bundle = _write_bundle(tmp_path, b'{"files":[]}')
    manifest_path = bundle / "genesis-manifest.json"
    manifest = json.loads(manifest_path.read_text())
    manifest["artifacts"] = [item for item in manifest["artifacts"] if item["artifact_type"] != "security_report"]
    manifest_path.write_text(json.dumps(manifest))
    fake = FakeArtifactClient()

    with pytest.raises(DevBoardApiError, match="security report"):
        upload_genesis_bundle(
            fake,
            repository_id="repo_123",
            run_id="run_123",
            local_workspace_id="lw_123",
            bundle_path=bundle,
        )

    assert fake.started is None


def test_upload_uses_pre_network_snapshot_when_source_path_changes(tmp_path):
    original = b'{"files":["one","two"]}'
    bundle = _write_bundle(tmp_path, original, chunk_size=5)
    outside = tmp_path / "outside.json"
    outside.write_text('{"files":["replaced"]}')

    class SwappingClient(FakeArtifactClient):
        def start_genesis_import(self, repository_id, manifest, run_id, local_workspace_id):
            result = super().start_genesis_import(repository_id, manifest, run_id, local_workspace_id)
            artifact = bundle / "file-inventory.json"
            artifact.unlink()
            artifact.symlink_to(outside)
            return result

        def upload_genesis_chunk(self, import_id, artifact_id, chunk_index, content):
            result = super().upload_genesis_chunk(import_id, artifact_id, chunk_index, content)
            if artifact_id == "art_123" and chunk_index == 0:
                outside.write_text('{"files":["mutated-in-place"]}')
            return result

    fake = SwappingClient()

    upload_genesis_bundle(
        fake,
        repository_id="repo_123",
        run_id="run_123",
        local_workspace_id="lw_123",
        bundle_path=bundle,
        chunk_size=5,
    )

    assert fake.started is not None
    uploaded = b"".join(chunk[3] for chunk in fake.chunks if chunk[1] == "art_123")
    assert uploaded == original
    assert hashlib.sha256(uploaded).hexdigest() == hashlib.sha256(original).hexdigest()
    assert fake.finalized == "gen_123"


def test_upload_snapshot_is_unchanged_by_in_place_source_mutations_after_start_and_first_chunk(tmp_path):
    original = b"abcdefghijklmno"
    bundle = _write_bundle(tmp_path, original, chunk_size=5)
    source = bundle / "file-inventory.json"

    class MutatingClient(FakeArtifactClient):
        def start_genesis_import(self, repository_id, manifest, run_id, local_workspace_id):
            result = super().start_genesis_import(repository_id, manifest, run_id, local_workspace_id)
            source.write_bytes(b"S" * len(original))
            return result

        def upload_genesis_chunk(self, import_id, artifact_id, chunk_index, content):
            result = super().upload_genesis_chunk(import_id, artifact_id, chunk_index, content)
            if artifact_id == "art_123" and chunk_index == 0:
                source.write_bytes(b"C" * len(original))
            return result

    fake = MutatingClient()

    upload_genesis_bundle(
        fake,
        repository_id="repo_123",
        run_id="run_123",
        local_workspace_id="lw_123",
        bundle_path=bundle,
        chunk_size=5,
    )

    uploaded = b"".join(chunk[3] for chunk in fake.chunks if chunk[1] == "art_123")
    assert uploaded == original
    assert hashlib.sha256(uploaded).hexdigest() == hashlib.sha256(original).hexdigest()
    assert source.read_bytes() == b"C" * len(original)


def test_artifact_snapshots_are_closed_when_network_upload_fails(monkeypatch, tmp_path):
    bundle = _write_bundle(tmp_path, b'{"files":[]}')
    snapshots = []
    snapshot_artifacts = artifacts_module.snapshot_artifacts

    @contextmanager
    def tracked_snapshot_artifacts(*args, **kwargs):
        with snapshot_artifacts(*args, **kwargs) as created:
            snapshots.extend(snapshot.file for snapshot in created)
            assert all(not snapshot.file.writable() for snapshot in created)
            yield created

    class FailingClient(FakeArtifactClient):
        def upload_genesis_chunk(self, import_id, artifact_id, chunk_index, content):
            raise RuntimeError("network failed")

    monkeypatch.setattr(artifacts_module, "snapshot_artifacts", tracked_snapshot_artifacts)

    with pytest.raises(RuntimeError, match="network failed"):
        upload_genesis_bundle(
            FailingClient(),
            repository_id="repo_123",
            run_id="run_123",
            local_workspace_id="lw_123",
            bundle_path=bundle,
        )

    assert snapshots
    assert all(snapshot.closed for snapshot in snapshots)


def test_client_upload_chunk_sends_chunk_hash_headers():
    captured = {}

    def handler(request: httpx.Request) -> httpx.Response:
        captured["headers"] = request.headers
        captured["content"] = request.content
        return httpx.Response(200, json={"status": "received"})

    client = DevBoardClient(
        base_url="https://devboard.test",
        token="devb_live_token|secret",
        transport=httpx.MockTransport(handler),
    )

    client.upload_genesis_chunk("gen_123", "art_123", 0, b"hello")

    assert captured["headers"]["x-devboard-chunk-size"] == "5"
    assert captured["headers"]["x-devboard-chunk-sha256"]
    assert captured["content"] == b"hello"


def test_duplicate_chunk_success_is_treated_as_success():
    calls = 0

    def handler(request: httpx.Request) -> httpx.Response:
        nonlocal calls
        calls += 1
        return httpx.Response(200, json={"status": "received"})

    client = DevBoardClient(
        base_url="https://devboard.test",
        token="devb_live_token|secret",
        transport=httpx.MockTransport(handler),
    )

    assert client.upload_genesis_chunk("gen_123", "art_123", 0, b"hello")["status"] == "received"
    assert client.upload_genesis_chunk("gen_123", "art_123", 0, b"hello")["status"] == "received"
    assert calls == 2


def _write_bundle(tmp_path: Path, artifact_content: bytes, chunk_size: int = 5_242_880) -> Path:
    bundle = tmp_path / "bundle"
    bundle.mkdir()
    (bundle / "file-inventory.json").write_bytes(artifact_content)
    report_content = json.dumps({"blocked": [], "warnings": []}).encode()
    (bundle / "security-report.json").write_bytes(report_content)
    manifest = {
        "protocol_version": "v1",
        "artifacts": [
            {
                "artifact_id": "art_123",
                "artifact_type": "file_inventory",
                "filename": "file-inventory.json",
                "sha256": "sha256:" + hashlib.sha256(artifact_content).hexdigest(),
                "size_bytes": len(artifact_content),
                "chunk_count": max(1, (len(artifact_content) + chunk_size - 1) // chunk_size),
            },
            {
                "artifact_id": "security_art_123",
                "artifact_type": "security_report",
                "filename": "security-report.json",
                "sha256": "sha256:" + hashlib.sha256(report_content).hexdigest(),
                "size_bytes": len(report_content),
                "chunk_count": max(1, (len(report_content) + chunk_size - 1) // chunk_size),
            },
        ],
    }
    (bundle / "genesis-manifest.json").write_text(json.dumps(manifest))

    return bundle


def _write_delta_bundle(tmp_path: Path, artifact_content: bytes) -> Path:
    bundle = tmp_path / "delta-bundle"
    bundle.mkdir()
    (bundle / "diff-summary.json").write_bytes(artifact_content)
    report_content = json.dumps({"blocked": [], "warnings": []}).encode()
    (bundle / "security-report.json").write_bytes(report_content)
    manifest = {
        "protocol_version": "v1",
        "bundle_type": "delta_sync",
        "schema_version": "v1",
        "artifacts": [
            {
                "artifact_id": "art_delta",
                "artifact_type": "diff_summary",
                "filename": "diff-summary.json",
                "sha256": "sha256:" + hashlib.sha256(artifact_content).hexdigest(),
                "size_bytes": len(artifact_content),
                "chunk_count": 1,
            },
            {
                "artifact_id": "security_delta",
                "artifact_type": "security_report",
                "filename": "security-report.json",
                "sha256": "sha256:" + hashlib.sha256(report_content).hexdigest(),
                "size_bytes": len(report_content),
                "chunk_count": 1,
            },
        ],
    }
    (bundle / "delta-manifest.json").write_text(json.dumps(manifest))

    return bundle


def _add_security_report(bundle: Path, manifest_filename: str, artifact_id: str, blocked: list[dict]) -> None:
    report = {"blocked": blocked, "warnings": []}
    report_content = json.dumps(report).encode()
    (bundle / "security-report.json").write_bytes(report_content)

    manifest_path = bundle / manifest_filename
    manifest = json.loads(manifest_path.read_text())
    security_artifact = next(
        (item for item in manifest["artifacts"] if item["artifact_type"] == "security_report"),
        None,
    )
    record = {
        "artifact_id": artifact_id,
        "artifact_type": "security_report",
        "filename": "security-report.json",
        "sha256": "sha256:" + hashlib.sha256(report_content).hexdigest(),
        "size_bytes": len(report_content),
        "chunk_count": 1,
    }
    if security_artifact is None:
        manifest["artifacts"].append(record)
    else:
        security_artifact.update(record)
    manifest_path.write_text(json.dumps(manifest))
