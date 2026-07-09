import json
from pathlib import Path
import hashlib

import httpx

from devboard_plugin.artifacts import upload_delta_bundle, upload_genesis_bundle
from devboard_plugin.client import DevBoardClient


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
    bundle = _write_bundle(tmp_path, artifact_content)
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
    assert [chunk[2] for chunk in fake.chunks] == [0, 1, 2]
    assert fake.finalized == "gen_123"
    assert result["status"] == "active"


def test_upload_genesis_bundle_streams_large_file_in_chunks(tmp_path):
    payload = b"x" * 100_000
    bundle = _write_bundle(tmp_path, payload)
    fake = FakeArtifactClient()

    upload_genesis_bundle(
        fake,
        repository_id="repo_123",
        run_id="run_123",
        local_workspace_id="lw_123",
        bundle_path=bundle,
        chunk_size=8192,
    )

    uploaded = b"".join(chunk[3] for chunk in fake.chunks)
    assert uploaded == payload
    assert len(fake.chunks) == 13
    assert [chunk[2] for chunk in fake.chunks] == list(range(13))


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
    assert fake.delta_chunks == [("delta_123", "art_delta", 0, artifact_content)]
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


def _write_bundle(tmp_path: Path, artifact_content: bytes) -> Path:
    bundle = tmp_path / "bundle"
    bundle.mkdir()
    (bundle / "file-inventory.json").write_bytes(artifact_content)
    manifest = {
        "protocol_version": "v1",
        "artifacts": [
            {
                "artifact_id": "art_123",
                "artifact_type": "file_inventory",
                "filename": "file-inventory.json",
                "sha256": "unused",
                "chunk_count": 3,
            }
        ],
    }
    (bundle / "genesis-manifest.json").write_text(json.dumps(manifest))

    return bundle


def _write_delta_bundle(tmp_path: Path, artifact_content: bytes) -> Path:
    bundle = tmp_path / "delta-bundle"
    bundle.mkdir()
    (bundle / "diff-summary.json").write_bytes(artifact_content)
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
            }
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
    manifest["artifacts"].append(
        {
            "artifact_id": artifact_id,
            "artifact_type": "security_report",
            "filename": "security-report.json",
            "sha256": "sha256:" + hashlib.sha256(report_content).hexdigest(),
            "size_bytes": len(report_content),
            "chunk_count": 1,
        }
    )
    manifest_path.write_text(json.dumps(manifest))
