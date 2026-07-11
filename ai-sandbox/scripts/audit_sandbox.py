#!/usr/bin/env python3

from __future__ import annotations

import json
import sys
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
if str(SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPT_DIR))

from refresh_graph import read_config_value


ROOT = Path(__file__).resolve().parents[1]
WORKSPACE = ROOT.parent
GRAPH = ROOT / "graph" / "graph.json"
PROJECT_CONFIG = ROOT / "config" / "project.yaml"
PATH_KEYS = ("source_file", "target_file", "path", "file", "source_path", "target_path")
ROOT_EXCLUDED_PREFIXES = (
    ".git/",
    "ai-sandbox/",
    "graphify-out/",
)


def configured_project_root(config_path: Path = PROJECT_CONFIG, workspace: Path = WORKSPACE) -> str:
    ast_root = read_config_value(config_path, "graph", "ast_root", "")
    if not ast_root:
        ast_root = read_config_value(config_path, "project", "root", "project")

    candidate = Path(ast_root)
    resolved = candidate.resolve() if candidate.is_absolute() else (workspace / candidate).resolve()
    workspace_resolved = workspace.resolve()

    try:
        relative = resolved.relative_to(workspace_resolved)
    except ValueError as exc:
        raise ValueError(f"Configured graph root is outside workspace: {ast_root}") from exc

    if not resolved.exists():
        raise FileNotFoundError(f"Configured graph root does not exist: {relative.as_posix()}")

    return relative.as_posix() or "."


def unexpected_graph_sources(graph: dict, project_root: str) -> list[str]:
    prefix = project_root.rstrip("/") + "/"
    unexpected = set()
    for collection in ("nodes", "links", "edges"):
        for item in graph.get(collection, []):
            if not isinstance(item, dict):
                continue
            for key in PATH_KEYS:
                value = item.get(key)
                if not isinstance(value, str) or not value:
                    continue
                if project_root == ".":
                    if value.startswith(ROOT_EXCLUDED_PREFIXES) or value in {".git", "ai-sandbox", "graphify-out"}:
                        unexpected.add(value)
                    continue
                if not value.startswith(prefix):
                    unexpected.add(value)
    return sorted(unexpected)


def main() -> int:
    if not GRAPH.exists():
        print(f"ERROR: missing graph file: {GRAPH}", file=sys.stderr)
        return 1
    graph = json.loads(GRAPH.read_text())
    project_root = configured_project_root()
    problems = unexpected_graph_sources(graph, project_root)
    if problems:
        for problem in problems:
            print(f"ERROR: graph source outside {project_root}: {problem}", file=sys.stderr)
        return 1
    print("Sandbox audit passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
