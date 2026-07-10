from __future__ import annotations

import os
from pathlib import Path
import tempfile


def reject_symlink_components(path: Path, *, start: Path | None = None) -> None:
    candidate = path.absolute()
    boundary = start.absolute() if start is not None else Path(candidate.anchor)
    try:
        relative = candidate.relative_to(boundary)
    except ValueError:
        raise ValueError(f"Path {candidate} is outside {boundary}.") from None

    current = boundary
    if current.is_symlink():
        raise ValueError(f"Path contains a symlink: {current}")
    for part in relative.parts:
        current = current / part
        if current.is_symlink():
            raise ValueError(f"Path contains a symlink: {current}")


def atomic_write_text(path: Path, content: str, *, file_mode: int = 0o600, directory_mode: int = 0o700) -> None:
    reject_symlink_components(path.parent)
    path.parent.mkdir(parents=True, exist_ok=True, mode=directory_mode)
    reject_symlink_components(path)
    os.chmod(path.parent, directory_mode)

    descriptor, temporary_name = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=path.parent)
    temporary_path = Path(temporary_name)
    try:
        os.fchmod(descriptor, file_mode)
        with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
            handle.write(content)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary_path, path)
        os.chmod(path, file_mode)
        directory_fd = os.open(path.parent, os.O_RDONLY)
        try:
            os.fsync(directory_fd)
        finally:
            os.close(directory_fd)
    finally:
        if temporary_path.exists():
            temporary_path.unlink()
