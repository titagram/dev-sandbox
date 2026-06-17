from __future__ import annotations

import json
import math
from pathlib import Path
import secrets
import time
from typing import Any

from devboard_analyzer.code_graph import build_code_graph, relation_index_from_graph, symbol_index_from_graph
from devboard_analyzer.file_hashes import hash_file
from devboard_analyzer.file_inventory import iter_repository_files
from devboard_analyzer.safety import scan_safety

DEFAULT_CHUNK_SIZE = 5_242_880
_CROCKFORD = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"


def build_genesis_bundle(root: Path | str, output_dir: Path | str, context: dict[str, Any] | None = None) -> dict[str, Any]:
    repo = Path(root)
    output = Path(output_dir)
    output.mkdir(parents=True, exist_ok=True)
    context = context or {}
    files = list(iter_repository_files(repo))
    safety_report = scan_safety(repo, files)
    graph = build_code_graph(repo, files, context, graph_mode="full_snapshot")

    _write_json(output / "file-inventory.json", _file_inventory(repo, files))
    _write_json(output / "file-hashes.json", _file_hashes(repo, files))
    _write_json(output / "symbol-index.json", symbol_index_from_graph(graph))
    _write_json(output / "relation-index.json", relation_index_from_graph(graph))
    _write_json(output / "graph-snapshot.json", graph)
    _write_json(output / "wiki-pages.json", _wiki_pages(context))
    _write_json(output / "analysis-quality-report.json", _quality_report(files, safety_report))
    _write_json(output / "security-report.json", _security_report(safety_report))

    artifact_filenames = [
        "file-inventory.json",
        "file-hashes.json",
        "symbol-index.json",
        "relation-index.json",
        "graph-snapshot.json",
        "wiki-pages.json",
        "analysis-quality-report.json",
        "security-report.json",
    ]
    artifact_records = [
        _artifact_record(output / filename, _graph_artifact_metadata(graph) if filename == "graph-snapshot.json" else None)
        for filename in artifact_filenames
    ]
    manifest = {
        "protocol_version": "v1",
        "bundle_type": "genesis",
        "schema_version": "v1",
        "code_exposure": context.get("code_exposure", "full_code_artifacts"),
        "project_id": context.get("project_id"),
        "repository_id": context.get("repository_id"),
        "run_id": context.get("run_id"),
        "artifacts": artifact_records,
    }
    _write_json(output / "genesis-manifest.json", manifest)

    return {
        "output_dir": str(output),
        "artifacts": [_artifact_record(output / "genesis-manifest.json"), *artifact_records],
        "manifest_path": str(output / "genesis-manifest.json"),
    }


def _file_inventory(repo: Path, files: list[Path]) -> dict[str, Any]:
    return {
        "protocol_version": "v1",
        "files": [
            {
                "path": path.relative_to(repo).as_posix(),
                "size_bytes": path.stat().st_size,
            }
            for path in files
        ],
    }


def _file_hashes(repo: Path, files: list[Path]) -> dict[str, Any]:
    return {
        "protocol_version": "v1",
        "hashes": [
            {
                "path": path.relative_to(repo).as_posix(),
                "sha256": hash_file(path),
            }
            for path in files
        ],
    }


def _wiki_pages(context: dict[str, Any]) -> dict[str, Any]:
    return {
        "protocol_version": "v1",
        "pages": [
            {
                "slug": "technical/genesis-import",
                "title": "Genesis Import",
                "page_type": "technical",
                "producer": "analyzer",
                "source_type": "local_analyzer",
                "source_status": "verified_from_code",
                "content_markdown": "# Genesis Import\n\nInitial analyzer snapshot generated from local code.",
                "evidence_refs": [
                    {
                        "type": "artifact",
                        "path": "file-inventory.json",
                        "description": "Generated file inventory for local snapshot.",
                    }
                ],
                "repository_id": context.get("repository_id"),
            }
        ],
    }


def _quality_report(files: list[Path], safety_report: Any) -> dict[str, Any]:
    return {
        "protocol_version": "v1",
        "file_count": len(files),
        "blocked_count": len(safety_report.blocked),
        "warning_count": len(safety_report.warnings),
    }


def _security_report(safety_report: Any) -> dict[str, Any]:
    return {
        "protocol_version": "v1",
        "blocked": safety_report.blocked,
        "warnings": safety_report.warnings,
    }


def _artifact_record(path: Path, extra: dict[str, Any] | None = None) -> dict[str, Any]:
    size_bytes = path.stat().st_size

    record = {
        "artifact_id": _ulid(),
        "filename": path.name,
        "artifact_type": path.stem.replace("-", "_"),
        "schema_version": "v1",
        "mime_type": "application/json",
        "sha256": hash_file(path),
        "size_bytes": size_bytes,
        "chunk_count": max(1, math.ceil(size_bytes / DEFAULT_CHUNK_SIZE)),
        "producer": "devboard-python-plugin",
        "source_type": "local_analyzer",
        "source_status": "verified_from_code",
    }
    if extra:
        record.update(extra)

    return record


def _graph_artifact_metadata(graph: dict[str, Any]) -> dict[str, Any]:
    return {
        "graph_extraction_mode": graph.get("graph_extraction_mode", "file_only"),
        "graph_parser": graph.get("parser", "none"),
        "graph_analyzer": graph.get("analyzer", "file_inventory"),
    }


def _write_json(path: Path, data: dict[str, Any]) -> None:
    path.write_text(json.dumps(data, indent=2, sort_keys=True) + "\n")


def _ulid() -> str:
    timestamp_ms = int(time.time() * 1000)
    value = (timestamp_ms << 80) | secrets.randbits(80)

    return "".join(_CROCKFORD[(value >> shift) & 31] for shift in range(125, -1, -5))
