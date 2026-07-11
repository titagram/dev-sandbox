from __future__ import annotations

import fnmatch
import os
from pathlib import Path
from pathlib import PurePosixPath
from typing import Iterator

EXCLUDED_DIRS = {".git", ".devboard", ".pytest_cache", ".venv", "__pycache__", "node_modules", "vendor"}


def iter_repository_files(root: Path | str, excluded_paths: list[str] | None = None) -> Iterator[Path]:
    base = Path(root).resolve(strict=True)
    gitignore_patterns = _gitignore_patterns(base)
    policy_patterns = [path.strip() for path in (excluded_paths or []) if path.strip()]
    files: list[Path] = []

    for current_root, directories, filenames in os.walk(base, topdown=True, followlinks=False):
        current = Path(current_root)
        kept_directories = []
        for name in directories:
            path = current / name
            relative = path.relative_to(base).as_posix()
            if path.is_symlink() or name in EXCLUDED_DIRS:
                continue
            git_ignored = _matches_exclusion(relative, gitignore_patterns, is_dir=True)
            policy_ignored = _matches_exclusion(relative, policy_patterns, is_dir=True)
            if policy_ignored and not _negation_may_reinclude_descendant(relative, policy_patterns):
                continue
            if git_ignored and not _negation_may_reinclude_descendant(relative, gitignore_patterns):
                continue
            kept_directories.append(name)
        directories[:] = kept_directories

        for name in filenames:
            path = current / name
            if path.is_symlink() or not path.is_file():
                continue
            relative = path.relative_to(base).as_posix()
            if _matches_exclusion(relative, gitignore_patterns, is_dir=False) or _matches_exclusion(
                relative, policy_patterns, is_dir=False
            ):
                continue
            files.append(path)

    yield from sorted(files)


def _gitignore_patterns(base: Path) -> list[str]:
    gitignore = base / ".gitignore"
    if not gitignore.is_file() or gitignore.is_symlink():
        return []
    try:
        lines = gitignore.read_text(errors="ignore").splitlines()
    except OSError:
        return []
    return [line.strip() for line in lines if line.strip() and not line.lstrip().startswith("#")]


def _matches_exclusion(relative: str, patterns: list[str], *, is_dir: bool) -> bool:
    path = PurePosixPath(relative)
    excluded = False
    for raw_pattern in patterns:
        negated = raw_pattern.startswith("!")
        pattern = raw_pattern[1:] if negated else raw_pattern
        pattern = pattern.replace("\\", "/")
        if pattern.startswith("/"):
            pattern = pattern[1:]
        elif pattern.startswith("./"):
            pattern = pattern[2:]
        directory_pattern = pattern.endswith("/")
        pattern = pattern.rstrip("/")
        if not pattern:
            continue
        matched = False
        if directory_pattern:
            if relative == pattern or relative.startswith(pattern + "/"):
                matched = True
            elif "/" not in pattern and pattern in path.parts:
                matched = True
        elif fnmatch.fnmatch(relative, pattern) or path.match(pattern):
            matched = True
        elif is_dir and path.match(pattern):
            matched = True
        if matched:
            excluded = not negated
    return excluded


def _negation_may_reinclude_descendant(relative_directory: str, patterns: list[str]) -> bool:
    prefix = relative_directory.rstrip("/") + "/"
    for raw_pattern in patterns:
        if not raw_pattern.startswith("!"):
            continue
        pattern = raw_pattern[1:].replace("\\", "/")
        if pattern.startswith("/"):
            pattern = pattern[1:]
        elif pattern.startswith("./"):
            pattern = pattern[2:]
        pattern = pattern.rstrip("/")
        if not pattern:
            continue
        if "/" not in pattern or pattern.startswith(prefix):
            return True
        parent_pattern = pattern.rsplit("/", 1)[0]
        if fnmatch.fnmatch(relative_directory, parent_pattern):
            return True
    return False
