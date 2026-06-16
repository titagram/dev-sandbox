from __future__ import annotations

import json
from pathlib import Path
from typing import Any, Protocol


class GenesisUploadClient(Protocol):
    def start_genesis_import(
        self,
        repository_id: str,
        manifest: dict[str, Any],
        run_id: str,
        local_workspace_id: str,
    ) -> dict[str, Any]:
        ...

    def upload_genesis_chunk(self, import_id: str, artifact_id: str, chunk_index: int, content: bytes) -> dict[str, Any]:
        ...

    def finalize_genesis_import(self, import_id: str) -> dict[str, Any]:
        ...

    def finish_run(self, run_id: str, payload: dict[str, Any]) -> dict[str, Any]:
        ...


class DeltaUploadClient(Protocol):
    def start_delta_sync(
        self,
        run_id: str,
        manifest: dict[str, Any],
        local_workspace_id: str,
        base_snapshot_id: str,
    ) -> dict[str, Any]:
        ...

    def upload_delta_chunk(self, delta_id: str, artifact_id: str, chunk_index: int, content: bytes) -> dict[str, Any]:
        ...

    def finalize_delta_sync(self, delta_id: str) -> dict[str, Any]:
        ...

    def finish_run(self, run_id: str, payload: dict[str, Any]) -> dict[str, Any]:
        ...


def upload_genesis_bundle(
    client: GenesisUploadClient,
    *,
    repository_id: str,
    run_id: str,
    local_workspace_id: str,
    bundle_path: Path | str,
    chunk_size: int = 5_242_880,
) -> dict[str, Any]:
    bundle = Path(bundle_path)
    manifest = json.loads((bundle / "genesis-manifest.json").read_text())
    start = client.start_genesis_import(repository_id, manifest, run_id, local_workspace_id)
    import_id = start["import_id"]

    for artifact in manifest["artifacts"]:
        artifact_path = bundle / artifact["filename"]
        content = artifact_path.read_bytes()

        for chunk_index, offset in enumerate(range(0, len(content), chunk_size)):
            chunk = content[offset : offset + chunk_size]
            client.upload_genesis_chunk(import_id, artifact["artifact_id"], chunk_index, chunk)

    finalized = client.finalize_genesis_import(import_id)
    client.finish_run(run_id, {"status": "finished", "summary": "Genesis import completed."})

    return finalized


def upload_delta_bundle(
    client: DeltaUploadClient,
    *,
    run_id: str,
    local_workspace_id: str,
    base_snapshot_id: str,
    bundle_path: Path | str,
    chunk_size: int = 5_242_880,
) -> dict[str, Any]:
    bundle = Path(bundle_path)
    manifest = json.loads((bundle / "delta-manifest.json").read_text())
    start = client.start_delta_sync(run_id, manifest, local_workspace_id, base_snapshot_id)
    delta_id = start["delta_id"]

    for artifact in manifest["artifacts"]:
        artifact_path = bundle / artifact["filename"]
        content = artifact_path.read_bytes()

        for chunk_index, offset in enumerate(range(0, len(content), chunk_size)):
            chunk = content[offset : offset + chunk_size]
            client.upload_delta_chunk(delta_id, artifact["artifact_id"], chunk_index, chunk)

    finalized = client.finalize_delta_sync(delta_id)
    client.finish_run(run_id, {"status": "finished", "summary": "Delta sync completed."})

    return finalized
