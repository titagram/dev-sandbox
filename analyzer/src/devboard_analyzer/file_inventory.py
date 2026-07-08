from __future__ import annotations

from pathlib import Path
from typing import Iterator

EXCLUDED_DIRS = {".git", ".devboard", "__pycache__", "node_modules", "vendor"}


def iter_repository_files(root: Path | str) -> Iterator[Path]:
    base = Path(root)

    for path in sorted(base.rglob("*")):
        if not path.is_file():
            continue

        if any(part in EXCLUDED_DIRS for part in path.relative_to(base).parts):
            continue

        yield path
