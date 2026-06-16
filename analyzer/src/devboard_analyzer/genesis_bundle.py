from __future__ import annotations

import json
from pathlib import Path
from typing import Any

from devboard_analyzer.file_hashes import hash_file
from devboard_analyzer.file_inventory import iter_repository_files
from devboard_analyzer.safety import scan_safety


def build_genesis_bundle(root: Path | str, output_dir: Path | str, context: dict[str, Any] | None = None) -> dict[str, Any]:
    repo = Path(root)
    output = Path(output_dir)
    output.mkdir(parents=True, exist_ok=True)
    context = context or {}
    files = list(iter_repository_files(repo))
    safety_report = scan_safety(repo, files)

    _write_json(output / "file-inventory.json", _file_inventory(repo, files))
    _write_json(output / "file-hashes.json", _file_hashes(repo, files))
    _write_json(output / "graph-snapshot.json", _graph_snapshot(repo, files, context))
    _write_json(output / "wiki-pages.json", _wiki_pages(context))
    _write_json(output / "analysis-quality-report.json", _quality_report(files, safety_report))
    _write_json(output / "security-report.json", _security_report(safety_report))

    artifact_filenames = [
        "file-inventory.json",
        "file-hashes.json",
        "graph-snapshot.json",
        "wiki-pages.json",
        "analysis-quality-report.json",
        "security-report.json",
    ]
    artifact_records = [_artifact_record(output / filename) for filename in artifact_filenames]
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
    manifest["artifacts"] = [_artifact_record(output / "genesis-manifest.json"), *artifact_records]
    _write_json(output / "genesis-manifest.json", manifest)

    return {
        "output_dir": str(output),
        "artifacts": manifest["artifacts"],
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


def _graph_snapshot(repo: Path, files: list[Path], context: dict[str, Any]) -> dict[str, Any]:
    return {
        "protocol_version": "v1",
        "source_type": "local_analyzer",
        "source_status": "verified_from_code",
        "repository_id": context.get("repository_id"),
        "nodes": [
            {
                "id": "file:" + path.relative_to(repo).as_posix(),
                "labels": ["File"],
                "properties": {"path": path.relative_to(repo).as_posix()},
            }
            for path in files
        ],
        "relationships": [],
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


def _artifact_record(path: Path) -> dict[str, Any]:
    return {
        "filename": path.name,
        "artifact_type": path.stem.replace("-", "_"),
        "schema_version": "v1",
        "mime_type": "application/json",
        "sha256": hash_file(path),
        "size_bytes": path.stat().st_size,
        "producer": "devboard-python-plugin",
        "source_type": "local_analyzer",
        "source_status": "verified_from_code",
    }


def _write_json(path: Path, data: dict[str, Any]) -> None:
    path.write_text(json.dumps(data, indent=2, sort_keys=True) + "\n")
