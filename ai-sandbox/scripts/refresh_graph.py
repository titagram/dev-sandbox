#!/usr/bin/env python3

from __future__ import annotations

import subprocess
import shutil
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
WORKSPACE = ROOT.parent
GRAPH_DIR = ROOT / "graph"
PROJECT_CONFIG = ROOT / "config" / "project.yaml"
GRAPHIFY_DIR = WORKSPACE / "graphify-out"
SANDBOX_GRAPH = GRAPH_DIR / "graph.json"
SANDBOX_REPORT = GRAPH_DIR / "GRAPH_REPORT.md"


def graphify_command(root: Path = ROOT) -> str:
    local = root / ".venv" / "bin" / "graphify"
    if local.exists():
        return str(local)
    windows = root / ".venv" / "Scripts" / "graphify.exe"
    if windows.exists():
        return str(windows)
    return shutil.which("graphify") or "graphify"


def run(command: list[str]) -> None:
    subprocess.run(command, cwd=WORKSPACE, check=True)


def parse_scalar(value: str) -> str:
    value = value.split("#", 1)[0].strip()
    if len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
        return value[1:-1]
    return value


def read_config_value(config_path: Path, section: str, key: str, default: str) -> str:
    if not config_path.exists():
        return default

    current_section = ""
    for raw_line in config_path.read_text().splitlines():
        if not raw_line.strip() or raw_line.lstrip().startswith("#"):
            continue
        indent = len(raw_line) - len(raw_line.lstrip())
        stripped = raw_line.strip()

        if indent == 0 and stripped.endswith(":"):
            current_section = stripped[:-1]
            continue

        if current_section == section and indent > 0 and stripped.startswith(f"{key}:"):
            value = parse_scalar(stripped.split(":", 1)[1])
            return value or default

    return default


def configured_ast_root(config_path: Path = PROJECT_CONFIG, workspace: Path = WORKSPACE) -> str:
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


def copy_graphify_outputs(graphify_dir: Path = GRAPHIFY_DIR, graph_dir: Path = GRAPH_DIR) -> list[Path]:
    source_graph = graphify_dir / "graph.json"
    if not source_graph.exists():
        raise FileNotFoundError(f"Graphify output not found: {source_graph}")

    graph_dir.mkdir(parents=True, exist_ok=True)
    copied = []
    target_graph = graph_dir / "graph.json"
    shutil.copy2(source_graph, target_graph)
    copied.append(target_graph)

    source_report = graphify_dir / "GRAPH_REPORT.md"
    if source_report.exists():
        target_report = graph_dir / "GRAPH_REPORT.md"
        shutil.copy2(source_report, target_report)
        copied.append(target_report)

    return copied


def main() -> int:
    GRAPH_DIR.mkdir(parents=True, exist_ok=True)
    ast_root = configured_ast_root()
    graphify = graphify_command()
    run([graphify, "update", ast_root, "--no-cluster"])
    run([graphify, "cluster-only", ast_root, "--no-viz"])
    copy_graphify_outputs()
    run(
        [
            graphify,
            "tree",
            "--graph",
            "ai-sandbox/graph/graph.json",
            "--output",
            "ai-sandbox/graph/GRAPH_TREE.html",
            "--root",
            ast_root,
            "--label",
            ast_root,
        ]
    )
    print(f"Graph refresh completed for {ast_root}.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
