from __future__ import annotations

import subprocess
from pathlib import Path


def ensure_devboard_excluded(repo_path: Path | str = ".") -> Path | None:
    root = Path(repo_path)
    info_dir = _nearest_git_info_dir(root)

    if info_dir is None:
        return None

    info_dir.mkdir(parents=True, exist_ok=True)
    exclude_path = info_dir / "exclude"

    existing = exclude_path.read_text().splitlines() if exclude_path.exists() else []
    if ".devboard/" not in existing:
        with exclude_path.open("a") as handle:
            if existing and existing[-1] != "":
                handle.write("\n")
            handle.write(".devboard/\n")

    return exclude_path


def _nearest_git_info_dir(path: Path) -> Path | None:
    current = path.resolve()
    if current.is_file():
        current = current.parent

    for candidate in (current, *current.parents):
        info_dir = candidate / ".git" / "info"
        if info_dir.exists():
            return info_dir

    return None


def current_branch(repo_path: Path | str = ".") -> str:
    return _git(repo_path, "rev-parse", "--abbrev-ref", "HEAD") or "unknown"


def head_sha(repo_path: Path | str = ".") -> str | None:
    value = _git(repo_path, "rev-parse", "HEAD")

    return value or None


def dirty_status(repo_path: Path | str = ".") -> str:
    return "dirty" if _git(repo_path, "status", "--porcelain") else "clean"


def local_root_hash(repo_path: Path | str = ".") -> str:
    resolved = str(Path(repo_path).resolve())

    import hashlib

    return "sha256:" + hashlib.sha256(resolved.encode("utf-8")).hexdigest()


def _git(repo_path: Path | str, *args: str) -> str:
    try:
        result = subprocess.run(
            ["git", *args],
            cwd=repo_path,
            check=False,
            capture_output=True,
            text=True,
        )
    except FileNotFoundError:
        return ""

    if result.returncode != 0:
        return ""

    return result.stdout.strip()
