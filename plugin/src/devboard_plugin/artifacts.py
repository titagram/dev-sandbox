from __future__ import annotations

from contextlib import contextmanager
from dataclasses import dataclass
import hashlib
import hmac
import json
import math
import os
from pathlib import Path
from pathlib import PurePosixPath
import stat
import tempfile
from typing import Any, BinaryIO, Iterator, Protocol

from devboard_plugin.client import DevBoardApiError
from devboard_plugin.secure_io import reject_symlink_components


@dataclass
class ArtifactSnapshot:
    artifact: dict[str, Any]
    file: BinaryIO


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
    bundle, manifest, artifacts = prepare_bundle(bundle_path, "genesis-manifest.json", chunk_size)
    with secure_bundle_directory(bundle) as bundle_fd:
        with snapshot_artifacts(bundle_fd, artifacts, chunk_size) as snapshots:
            blocked = blocked_security_findings(security_snapshot(snapshots).file)
            if blocked and not allow_blocked_security_findings:
                return security_approval_required_response(bundle, blocked)

            start = client.start_genesis_import(repository_id, manifest, run_id, local_workspace_id)
            import_id = start["import_id"]

            for snapshot in snapshots:
                snapshot.file.seek(0)
                for chunk_index, chunk in iter_artifact_chunks(snapshot.file, chunk_size):
                    client.upload_genesis_chunk(
                        import_id, snapshot.artifact["artifact_id"], chunk_index, chunk
                    )

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
    bundle, manifest, artifacts = prepare_bundle(bundle_path, "delta-manifest.json", chunk_size)
    with secure_bundle_directory(bundle) as bundle_fd:
        with snapshot_artifacts(bundle_fd, artifacts, chunk_size) as snapshots:
            blocked = blocked_security_findings(security_snapshot(snapshots).file)
            if blocked and not allow_blocked_security_findings:
                return security_approval_required_response(bundle, blocked)

            start = client.start_delta_sync(run_id, manifest, local_workspace_id, base_snapshot_id)
            delta_id = start["delta_id"]

            for snapshot in snapshots:
                snapshot.file.seek(0)
                for chunk_index, chunk in iter_artifact_chunks(snapshot.file, chunk_size):
                    client.upload_delta_chunk(
                        delta_id, snapshot.artifact["artifact_id"], chunk_index, chunk
                    )

    finalized = client.finalize_delta_sync(delta_id, allow_blocked_security_findings)
    client.finish_run(run_id, {"status": "finished", "summary": "Delta sync completed."})

    return finalized


def prepare_bundle(
    bundle_path: Path | str,
    manifest_filename: str,
    chunk_size: int,
) -> tuple[Path, dict[str, Any], list[dict[str, Any]]]:
    if chunk_size <= 0:
        raise _invalid_bundle("chunk_size must be positive")

    supplied_bundle = Path(bundle_path).expanduser().absolute()
    try:
        reject_symlink_components(supplied_bundle)
        bundle = supplied_bundle.resolve(strict=True)
    except (OSError, ValueError) as error:
        raise _invalid_bundle(str(error)) from None
    if not bundle.is_dir():
        raise _invalid_bundle("bundle path must be a directory")

    manifest_path = safe_bundle_file(bundle, manifest_filename)
    try:
        manifest = json.loads(manifest_path.read_text())
    except (OSError, ValueError) as error:
        raise _invalid_bundle(f"manifest is invalid: {error}") from None
    if not isinstance(manifest, dict) or not isinstance(manifest.get("artifacts"), list):
        raise _invalid_bundle("manifest artifacts must be a list")

    prepared: list[dict[str, Any]] = []
    artifact_ids: set[str] = set()
    filenames: set[str] = set()
    for artifact in manifest["artifacts"]:
        if not isinstance(artifact, dict):
            raise _invalid_bundle("artifact entry must be an object")
        artifact_id = artifact.get("artifact_id")
        filename = artifact.get("filename")
        if not isinstance(artifact_id, str) or not artifact_id or artifact_id in artifact_ids:
            raise _invalid_bundle("artifact_id is missing or duplicated")
        if not isinstance(filename, str) or not filename or filename in filenames:
            raise _invalid_bundle("artifact filename is missing or duplicated")
        artifact_ids.add(artifact_id)
        filenames.add(filename)

        safe_bundle_file(bundle, filename)
        prepared.append(artifact)

    security_artifacts = [item for item in prepared if item.get("artifact_type") == "security_report"]
    if len(security_artifacts) != 1:
        raise _invalid_bundle("exactly one security report artifact is required")
    return bundle, manifest, prepared


def safe_bundle_file(bundle: Path, filename: str) -> Path:
    if "\\" in filename:
        raise _invalid_bundle("artifact filename must use relative POSIX path syntax")
    relative = PurePosixPath(filename)
    if relative.is_absolute() or any(part in {"", ".", ".."} for part in relative.parts):
        raise _invalid_bundle("artifact filename must remain inside the bundle")
    candidate = bundle.joinpath(*relative.parts)
    try:
        reject_symlink_components(candidate, start=bundle)
        resolved = candidate.resolve(strict=True)
        resolved.relative_to(bundle)
    except (OSError, ValueError) as error:
        raise _invalid_bundle(f"artifact path is invalid or contains a symlink: {error}") from None
    if not resolved.is_file():
        raise _invalid_bundle("artifact path must be a regular file")
    return resolved


@contextmanager
def secure_bundle_directory(bundle: Path) -> Iterator[int]:
    if not hasattr(os, "O_NOFOLLOW") or not hasattr(os, "O_DIRECTORY"):
        raise _invalid_bundle("secure no-follow file opening is not supported on this platform")
    try:
        descriptor = os.open(bundle, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
    except OSError as error:
        raise _invalid_bundle(f"bundle secure open failed: {error}") from None
    try:
        yield descriptor
    finally:
        os.close(descriptor)


@contextmanager
def open_artifact_source(bundle_fd: int, artifact: dict[str, Any]) -> Iterator[BinaryIO]:
    filename = artifact["filename"]
    parts = PurePosixPath(filename).parts
    parent_fd = os.dup(bundle_fd)
    descriptor: int | None = None
    try:
        for part in parts[:-1]:
            next_fd = os.open(
                part,
                os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC,
                dir_fd=parent_fd,
            )
            os.close(parent_fd)
            parent_fd = next_fd
        descriptor = os.open(
            parts[-1],
            os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC,
            dir_fd=parent_fd,
        )
    except OSError as error:
        if descriptor is not None:
            os.close(descriptor)
        raise _invalid_bundle(
            f"artifact secure open rejected {filename}; path changed or contains a symlink: {error}"
        ) from None
    finally:
        os.close(parent_fd)

    with os.fdopen(descriptor, "rb") as artifact_file:
        yield artifact_file


@contextmanager
def snapshot_artifacts(
    bundle_fd: int,
    artifacts: list[dict[str, Any]],
    chunk_size: int,
) -> Iterator[list[ArtifactSnapshot]]:
    snapshots: list[ArtifactSnapshot] = []
    try:
        for artifact in artifacts:
            snapshot_file = create_verified_snapshot(bundle_fd, artifact, chunk_size)
            snapshots.append(ArtifactSnapshot(artifact=artifact, file=snapshot_file))
        yield snapshots
    finally:
        for snapshot in snapshots:
            snapshot.file.close()


def create_verified_snapshot(bundle_fd: int, artifact: dict[str, Any], chunk_size: int) -> BinaryIO:
    snapshot_writer = tempfile.NamedTemporaryFile(mode="w+b", delete=False)
    snapshot_path = Path(snapshot_writer.name)
    snapshot_reader: BinaryIO | None = None
    reader_fd: int | None = None
    try:
        with open_artifact_source(bundle_fd, artifact) as source_file:
            copy_verified_snapshot(artifact, source_file, snapshot_writer, chunk_size)
        writer_stat = os.fstat(snapshot_writer.fileno())
        reader_fd = os.open(snapshot_path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
        reader_stat = os.fstat(reader_fd)
        if (writer_stat.st_dev, writer_stat.st_ino, writer_stat.st_size) != (
            reader_stat.st_dev,
            reader_stat.st_ino,
            reader_stat.st_size,
        ):
            os.close(reader_fd)
            reader_fd = None
            raise _invalid_bundle(f"artifact snapshot changed before sealing: {artifact.get('filename')}")
        snapshot_reader = os.fdopen(reader_fd, "rb")
        reader_fd = None
        snapshot_writer.close()
        snapshot_path.unlink()
        return snapshot_reader
    except Exception:
        snapshot_writer.close()
        if snapshot_reader is not None:
            snapshot_reader.close()
        if reader_fd is not None:
            os.close(reader_fd)
        snapshot_path.unlink(missing_ok=True)
        raise


def copy_verified_snapshot(
    artifact: dict[str, Any],
    source_file: BinaryIO,
    snapshot_file: BinaryIO,
    chunk_size: int,
) -> None:
    before = os.fstat(source_file.fileno())
    if not stat.S_ISREG(before.st_mode):
        raise _invalid_bundle(f"artifact is not a regular file: {artifact.get('filename')}")

    digest = hashlib.sha256()
    copied_size = 0
    for chunk in iter(lambda: source_file.read(1024 * 1024), b""):
        digest.update(chunk)
        snapshot_file.write(chunk)
        copied_size += len(chunk)
    after = os.fstat(source_file.fileno())
    if (before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns) != (
        after.st_dev,
        after.st_ino,
        after.st_size,
        after.st_mtime_ns,
    ):
        raise _invalid_bundle(f"artifact changed while being verified: {artifact.get('filename')}")

    expected_size = artifact.get("size_bytes")
    if not isinstance(expected_size, int) or isinstance(expected_size, bool) or expected_size != copied_size:
        raise _invalid_bundle(f"size_bytes mismatch for {artifact.get('filename')}")

    expected_hash = artifact.get("sha256")
    normalized_expected_hash = expected_hash.lower().removeprefix("sha256:") if isinstance(expected_hash, str) else ""
    if not hmac.compare_digest(normalized_expected_hash, digest.hexdigest()):
        raise _invalid_bundle(f"sha256 mismatch for {artifact.get('filename')}")

    expected_chunks = artifact.get("chunk_count")
    actual_chunks = max(1, math.ceil(copied_size / chunk_size))
    if not isinstance(expected_chunks, int) or isinstance(expected_chunks, bool) or expected_chunks != actual_chunks:
        raise _invalid_bundle(f"chunk_count mismatch for {artifact.get('filename')}")
    snapshot_file.flush()
    os.fsync(snapshot_file.fileno())
    os.fchmod(snapshot_file.fileno(), 0o400)


def security_snapshot(snapshots: list[ArtifactSnapshot]) -> ArtifactSnapshot:
    return next(snapshot for snapshot in snapshots if snapshot.artifact.get("artifact_type") == "security_report")


def blocked_security_findings(report_file: BinaryIO) -> list[dict[str, str]]:
    try:
        report_file.seek(0)
        report = json.load(report_file)
    except (OSError, ValueError) as error:
        raise _invalid_bundle(f"security report is invalid: {error}") from None
    finally:
        report_file.seek(0)
    if not isinstance(report, dict) or not isinstance(report.get("blocked"), list) or not isinstance(report.get("warnings"), list):
        raise _invalid_bundle("security report must contain blocked and warnings lists")
    blocked = report["blocked"]

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


def iter_artifact_chunks(artifact_file: BinaryIO, chunk_size: int) -> Iterator[tuple[int, bytes]]:
    chunk_index = 0
    while chunk := artifact_file.read(chunk_size):
        yield chunk_index, chunk
        chunk_index += 1
    if chunk_index == 0:
        yield 0, b""


def _invalid_bundle(message: str) -> DevBoardApiError:
    return DevBoardApiError(code="invalid_bundle", message=f"Invalid artifact bundle: {message}")


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
