#!/usr/bin/env python3

from __future__ import annotations

import json
import os
import sys
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
if str(SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPT_DIR))

from refresh_graph import read_config_value


ROOT = Path(__file__).resolve().parents[1]
PROJECT_CONFIG = ROOT / "config" / "project.yaml"
OUT_DIR = ROOT / "docs"
IGNORED_DIRS = {"vendor", "node_modules", ".git", "var", "cache", "dist", "build", "coverage", "__pycache__"}


def detect_stack(project_root: Path) -> list[str]:
    stack = []
    composer = project_root / "composer.json"
    if composer.exists() and "symfony" in composer.read_text(errors="replace").lower():
        stack.append("symfony")
    if (project_root / "package.json").exists():
        stack.append("node")
    if (project_root / "pyproject.toml").exists() or (project_root / "requirements.txt").exists():
        stack.append("python")
    return stack or ["generic"]


def iter_source_files(project_root: Path) -> list[Path]:
    files = []
    for current_root, dirnames, filenames in os.walk(project_root):
        current = Path(current_root)
        dirnames[:] = [name for name in dirnames if name not in IGNORED_DIRS]
        for filename in filenames:
            rel = (current / filename).relative_to(project_root)
            if rel.parts and rel.parts[0] in IGNORED_DIRS:
                continue
            files.append(rel)
    return sorted(files, key=lambda path: path.as_posix())


def configured_project_path(config_path: Path = PROJECT_CONFIG, workspace: Path = ROOT.parent) -> Path:
    configured_root = read_config_value(config_path, "project", "root", "project")
    candidate = Path(configured_root)
    return candidate if candidate.is_absolute() else workspace / candidate


def build_discovery(project_root: Path | None = None) -> dict:
    project_root = project_root or configured_project_path()
    files = iter_source_files(project_root) if project_root.exists() else []
    return {
        "project_root": project_root.as_posix(),
        "stack": detect_stack(project_root) if project_root.exists() else ["missing_project_root"],
        "file_count": len(files),
        "sample_files": [path.as_posix() for path in files[:200]],
    }


def write_outputs(data: dict, out_dir: Path = OUT_DIR) -> None:
    out_dir.mkdir(parents=True, exist_ok=True)
    (out_dir / "discovery.json").write_text(json.dumps(data, indent=2) + "\n")
    lines = [
        "# Project Discovery",
        "",
        f"- Project root: `{data['project_root']}`",
        f"- Stack: `{', '.join(data['stack'])}`",
        f"- File count: `{data['file_count']}`",
        "",
        "## Sample Files",
        "",
    ]
    lines.extend(f"- `{path}`" for path in data["sample_files"])
    (out_dir / "discovery.md").write_text("\n".join(lines) + "\n")


def main() -> int:
    data = build_discovery()
    write_outputs(data)
    print(f"Wrote {OUT_DIR / 'discovery.json'}")
    print(f"Wrote {OUT_DIR / 'discovery.md'}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
