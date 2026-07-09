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

    def finalize_genesis_import(
        self,
        import_id: str,
        allow_blocked_security_findings: bool = False,
    ) -> dict[str, Any]:
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

    def finalize_delta_sync(
        self,
        delta_id: str,
        allow_blocked_security_findings: bool = False,
    ) -> dict[str, Any]:
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
    allow_blocked_security_findings: bool = False,
) -> dict[str, Any]:
    bundle = Path(bundle_path)
    manifest = json.loads((bundle / "genesis-manifest.json").read_text())
    blocked = blocked_security_findings(bundle, manifest)
    if blocked and not allow_blocked_security_findings:
        return security_approval_required_response(bundle, blocked)

    start = client.start_genesis_import(repository_id, manifest, run_id, local_workspace_id)
    import_id = start["import_id"]

    for artifact in manifest["artifacts"]:
        artifact_path = bundle / artifact["filename"]
        with artifact_path.open("rb") as artifact_file:
            chunk_index = 0
            while chunk := artifact_file.read(chunk_size):
                client.upload_genesis_chunk(import_id, artifact["artifact_id"], chunk_index, chunk)
                chunk_index += 1

    finalized = client.finalize_genesis_import(import_id, allow_blocked_security_findings)
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
    allow_blocked_security_findings: bool = False,
) -> dict[str, Any]:
    bundle = Path(bundle_path)
    manifest = json.loads((bundle / "delta-manifest.json").read_text())
    blocked = blocked_security_findings(bundle, manifest)
    if blocked and not allow_blocked_security_findings:
        return security_approval_required_response(bundle, blocked)

    start = client.start_delta_sync(run_id, manifest, local_workspace_id, base_snapshot_id)
    delta_id = start["delta_id"]

    for artifact in manifest["artifacts"]:
        artifact_path = bundle / artifact["filename"]
        with artifact_path.open("rb") as artifact_file:
            chunk_index = 0
            while chunk := artifact_file.read(chunk_size):
                client.upload_delta_chunk(delta_id, artifact["artifact_id"], chunk_index, chunk)
                chunk_index += 1

    finalized = client.finalize_delta_sync(delta_id, allow_blocked_security_findings)
    client.finish_run(run_id, {"status": "finished", "summary": "Delta sync completed."})

    return finalized


def blocked_security_findings(bundle: Path, manifest: dict[str, Any]) -> list[dict[str, str]]:
    security_artifact = next(
        (artifact for artifact in manifest.get("artifacts", []) if artifact.get("artifact_type") == "security_report"),
        None,
    )
    if not security_artifact:
        return []

    filename = security_artifact.get("filename")
    if not isinstance(filename, str) or not filename:
        return []

    report_path = bundle / filename
    if not report_path.exists():
        return []

    report = json.loads(report_path.read_text())
    blocked = report.get("blocked", [])
    if not isinstance(blocked, list):
        return []

    findings: list[dict[str, str]] = []
    for item in blocked:
        if isinstance(item, dict):
            findings.append(
                {
                    "path": str(item.get("path") or "unknown"),
                    "reason": str(item.get("reason") or "unknown"),
                }
            )
        else:
            findings.append({"path": str(item), "reason": "unknown"})

    return findings


def security_approval_required_response(bundle: Path, blocked: list[dict[str, str]]) -> dict[str, Any]:
    return {
        "status": "requires_security_approval",
        "message": (
            "Security report contains blocked findings. Review the local bundle and retry with "
            "allow_blocked_security_findings=true only after explicit user approval."
        ),
        "approval_parameter": "allow_blocked_security_findings",
        "bundle_path": str(bundle),
        "blocked_count": len(blocked),
        "blocked_findings": blocked[:50],
    }
