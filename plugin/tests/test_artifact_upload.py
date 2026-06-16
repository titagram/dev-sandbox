import json
from pathlib import Path

import httpx

from devboard_plugin.artifacts import upload_genesis_bundle
from devboard_plugin.client import DevBoardClient


class FakeArtifactClient:
    def __init__(self):
        self.started = None
        self.chunks = []
        self.finalized = None
        self.finished = None

    def start_genesis_import(self, repository_id, manifest, run_id, local_workspace_id):
        self.started = (repository_id, manifest, run_id, local_workspace_id)
        return {"import_id": "gen_123", "artifacts": manifest["artifacts"]}

    def upload_genesis_chunk(self, import_id, artifact_id, chunk_index, content):
        self.chunks.append((import_id, artifact_id, chunk_index, content))
        return {"status": "received"}

    def finalize_genesis_import(self, import_id):
        self.finalized = import_id
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
