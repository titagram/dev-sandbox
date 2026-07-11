from __future__ import annotations

import json
from pathlib import Path
from typing import Any

from devboard_analyzer.code_graph import build_code_graph, relation_index_from_graph, symbol_index_from_graph
from devboard_analyzer.file_hashes import hash_file
from devboard_analyzer.file_inventory import iter_repository_files
from devboard_analyzer.genesis_bundle import _artifact_record, _graph_artifact_metadata, _write_json
from devboard_analyzer.safety import scan_safety


def build_delta_bundle(root: Path | str, output_dir: Path | str, context: dict[str, Any] | None = None) -> dict[str, Any]:
    repo = Path(root)
    output = Path(output_dir)
    output.mkdir(parents=True, exist_ok=True)
    context = context or {}
    files = list(iter_repository_files(repo, excluded_paths=context.get("excluded_paths", [])))
    base_hashes = context.get("base_file_hashes", {})
    current_hashes = _current_hashes(repo, files)
    changed_files = _changed_files(base_hashes, current_hashes)
    safety_report = scan_safety(repo, files)
    graph_files = _graph_files(repo, changed_files)
    graph = build_code_graph(repo, graph_files, context, graph_mode="affected_subgraph", resolution_files=files)
    affected_relations = relation_index_from_graph(graph)["relations"]
    node_tombstones = _node_tombstones(changed_files, context.get("base_symbols", []), graph)
    relationship_tombstones = _relationship_tombstones(
        changed_files,
        context.get("base_relations", []),
        affected_relations,
    )
    graph["nodes_deleted"] = node_tombstones
    graph["nodes_deleted_count"] = len(node_tombstones)
    graph["relationships_deleted"] = relationship_tombstones
    graph["relationships_deleted_count"] = len(relationship_tombstones)
    graph["affected_file_paths"] = sorted(item["path"] for item in changed_files)
    graph["affected_symbol_ids"] = sorted(
        {
            *graph.get("affected_symbol_ids", []),
            *(item["id"] for item in node_tombstones if item["kind"] == "symbol"),
        }
    )
    risk_report = _risk_report(changed_files, safety_report)
    payload = _delta_payload(context, changed_files, graph, risk_report)
    symbol_baseline = _updated_symbol_baseline(
        context.get("base_symbols", []),
        symbol_index_from_graph(graph)["symbols"],
        changed_files,
    )
    symbol_baseline_complete = context.get("base_symbols_complete") is True
    relation_baseline = _updated_relation_baseline(
        context.get("base_relations", []),
        affected_relations,
        changed_files,
    )
    relation_baseline_complete = context.get("base_relations_complete") is True

    _write_json(output / "file-hashes.json", {"protocol_version": "v1", "hashes": _hash_rows(current_hashes)})
    _write_json(output / "diff-summary.json", _diff_summary(changed_files))
    _write_json(output / "delta-payload.json", payload)
    _write_json(
        output / "symbol-index.json",
        {
            "protocol_version": "v1",
            "baseline_complete": symbol_baseline_complete,
            "symbols": symbol_baseline,
        },
    )
    _write_json(
        output / "relation-index.json",
        {
            "protocol_version": "v1",
            "baseline_complete": relation_baseline_complete,
            "relations": relation_baseline,
        },
    )
    _write_json(output / "graph-snapshot.json", graph)
    _write_json(output / "wiki-pages.json", _wiki_pages(context, changed_files))
    _write_json(output / "risk-report.json", risk_report)
    _write_json(output / "analysis-quality-report.json", _quality_report(changed_files, safety_report))
    _write_json(output / "security-report.json", _security_report(safety_report))

    artifact_filenames = [
        "delta-payload.json",
        "file-hashes.json",
        "diff-summary.json",
        "symbol-index.json",
        "relation-index.json",
        "graph-snapshot.json",
        "wiki-pages.json",
        "risk-report.json",
        "analysis-quality-report.json",
        "security-report.json",
    ]
    artifact_records = [
        _artifact_record(output / filename, _graph_artifact_metadata(graph) if filename == "graph-snapshot.json" else None)
        for filename in artifact_filenames
    ]
    artifact_records[0]["artifact_type"] = "delta_manifest"
    manifest = {**payload, "artifacts": artifact_records}
    _write_json(output / "delta-manifest.json", manifest)

    return {
        "output_dir": str(output),
        "artifacts": manifest["artifacts"],
        "manifest_path": str(output / "delta-manifest.json"),
    }


def _current_hashes(repo: Path, files: list[Path]) -> dict[str, str]:
    return {path.relative_to(repo).as_posix(): hash_file(path) for path in files}


def _hash_rows(hashes: dict[str, str]) -> list[dict[str, str]]:
    return [{"path": path, "sha256": sha256} for path, sha256 in sorted(hashes.items())]


def _changed_files(base_hashes: dict[str, str], current_hashes: dict[str, str]) -> list[dict[str, Any]]:
    changed: list[dict[str, Any]] = []

    for path, new_sha256 in sorted(current_hashes.items()):
        old_sha256 = base_hashes.get(path)
        if old_sha256 == new_sha256:
            continue

        changed.append(
            {
                "path": path,
                "change_type": "added" if old_sha256 is None else "modified",
                "old_sha256": old_sha256,
                "new_sha256": new_sha256,
                "safety_status": "allowed",
                "evidence_refs": [{"type": "artifact", "path": "file-hashes.json"}],
            }
        )

    for path, old_sha256 in sorted(base_hashes.items()):
        if path not in current_hashes:
            changed.append(
                {
                    "path": path,
                    "change_type": "deleted",
                    "old_sha256": old_sha256,
                    "new_sha256": None,
                    "safety_status": "allowed",
                    "evidence_refs": [{"type": "artifact", "path": "diff-summary.json"}],
                }
            )

    return changed


def _diff_summary(changed_files: list[dict[str, Any]]) -> dict[str, Any]:
    return {
        "protocol_version": "v1",
        "changed_file_count": len(changed_files),
        "added_file_count": _count_changes(changed_files, "added"),
        "modified_file_count": _count_changes(changed_files, "modified"),
        "deleted_file_count": _count_changes(changed_files, "deleted"),
        "changed_files": changed_files,
    }


def _count_changes(changed_files: list[dict[str, Any]], change_type: str) -> int:
    return sum(1 for file in changed_files if file["change_type"] == change_type)


def _graph_files(repo: Path, changed_files: list[dict[str, Any]]) -> list[Path]:
    return [
        repo / file["path"]
        for file in changed_files
        if file["change_type"] != "deleted" and (repo / file["path"]).exists()
    ]


def _delta_payload(
    context: dict[str, Any],
    changed_files: list[dict[str, Any]],
    graph: dict[str, Any],
    risk_report: dict[str, Any],
) -> dict[str, Any]:
    return {
        "protocol_version": "v1",
        "schema_version": "v1",
        "bundle_type": "delta_sync",
        "project_id": context.get("project_id"),
        "repository_id": context.get("repository_id"),
        "local_workspace_id": context.get("local_workspace_id"),
        "run_id": context.get("run_id"),
        "base_snapshot_id": context.get("base_snapshot_id"),
        "branch": context.get("branch"),
        "base_branch": context.get("base_branch"),
        "base_sha": context.get("base_sha"),
        "head_sha": context.get("head_sha"),
        "dirty_status": context.get("dirty_status", "unknown"),
        "changed_files": changed_files,
        "changed_file_count": len(changed_files),
        "deleted_file_tombstones": [
            {"path": item["path"]} for item in changed_files if item["change_type"] == "deleted"
        ],
        "graph_mode": graph.get("graph_mode", "affected_subgraph"),
        "affected_symbol_ids": graph.get("affected_symbol_ids", []),
        "risk_report": risk_report,
    }


def _node_tombstones(
    changed_files: list[dict[str, Any]],
    base_symbols: Any,
    graph: dict[str, Any],
) -> list[dict[str, str]]:
    deleted_paths = {item["path"] for item in changed_files if item["change_type"] == "deleted"}
    changed_paths = {item["path"] for item in changed_files if item["change_type"] in {"deleted", "modified"}}
    current_symbol_ids = {
        node.get("id")
        for node in graph.get("nodes", [])
        if isinstance(node, dict) and "Symbol" in node.get("labels", [])
    }
    tombstones = [
        {"id": f"file:{path}", "kind": "file", "path": path}
        for path in sorted(deleted_paths)
    ]
    if not isinstance(base_symbols, list):
        return tombstones
    for symbol in base_symbols:
        if not isinstance(symbol, dict):
            continue
        symbol_id = symbol.get("symbol_id")
        file_path = symbol.get("file_path")
        if (
            isinstance(symbol_id, str)
            and isinstance(file_path, str)
            and file_path in changed_paths
            and symbol_id not in current_symbol_ids
        ):
            tombstones.append({"id": symbol_id, "kind": "symbol", "path": file_path})
    return tombstones


def _updated_symbol_baseline(
    base_symbols: Any,
    affected_symbols: list[dict[str, Any]],
    changed_files: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    changed_paths = {item["path"] for item in changed_files}
    symbols_by_id = (
        {
            item["symbol_id"]: item
            for item in base_symbols
            if isinstance(item, dict)
            and isinstance(item.get("symbol_id"), str)
            and item.get("file_path") not in changed_paths
        }
        if isinstance(base_symbols, list)
        else {}
    )
    for symbol in affected_symbols:
        symbol_id = symbol.get("symbol_id")
        if isinstance(symbol_id, str):
            symbols_by_id[symbol_id] = symbol
    return [symbols_by_id[symbol_id] for symbol_id in sorted(symbols_by_id)]


def _relationship_tombstones(
    changed_files: list[dict[str, Any]],
    base_relations: Any,
    affected_relations: list[dict[str, Any]],
) -> list[dict[str, str]]:
    if not isinstance(base_relations, list):
        return []
    changed_paths = {item["path"] for item in changed_files}
    current_ids = {
        relation.get("relation_id")
        for relation in affected_relations
        if isinstance(relation.get("relation_id"), str)
    }
    tombstones = []
    for relation in base_relations:
        if not isinstance(relation, dict):
            continue
        relation_id = relation.get("relation_id")
        if (
            isinstance(relation_id, str)
            and relation_id not in current_ids
            and _relation_affects_paths(relation, changed_paths)
        ):
            tombstones.append(
                {
                    "id": relation_id,
                    "kind": "relationship",
                    "path": _relation_path(relation) or "unknown",
                }
            )
    return sorted(tombstones, key=lambda item: item["id"])


def _updated_relation_baseline(
    base_relations: Any,
    affected_relations: list[dict[str, Any]],
    changed_files: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    changed_paths = {item["path"] for item in changed_files}
    relations_by_id = (
        {
            item["relation_id"]: item
            for item in base_relations
            if isinstance(item, dict)
            and isinstance(item.get("relation_id"), str)
            and not _relation_affects_paths(item, changed_paths)
        }
        if isinstance(base_relations, list)
        else {}
    )
    for relation in affected_relations:
        relation_id = relation.get("relation_id")
        if isinstance(relation_id, str):
            relations_by_id[relation_id] = relation
    return [relations_by_id[relation_id] for relation_id in sorted(relations_by_id)]


def _relation_affects_paths(relation: dict[str, Any], changed_paths: set[str]) -> bool:
    path = _relation_path(relation)
    if path in changed_paths:
        return True
    endpoints = (relation.get("source_id"), relation.get("target_id"))
    return any(
        isinstance(endpoint, str)
        and any(endpoint == f"file:{path}" or endpoint.startswith(f"symbol:{path}:") for path in changed_paths)
        for endpoint in endpoints
    )


def _relation_path(relation: dict[str, Any]) -> str | None:
    properties = relation.get("properties")
    if not isinstance(properties, dict):
        return None
    for key in ("path", "source_file"):
        value = properties.get(key)
        if isinstance(value, str) and value:
            return value
    return None


def _wiki_pages(context: dict[str, Any], changed_files: list[dict[str, Any]]) -> dict[str, Any]:
    return {
        "protocol_version": "v1",
        "pages": [
            {
                "slug": "technical/delta-sync",
                "title": "Delta Sync",
                "page_type": "technical",
                "producer": "analyzer",
                "source_type": "local_analyzer",
                "source_status": "verified_from_code",
                "content_markdown": f"# Delta Sync\n\nChanged files in this snapshot: {len(changed_files)}.",
                "evidence_refs": [{"type": "artifact", "path": "diff-summary.json"}],
                "repository_id": context.get("repository_id"),
            }
        ],
    }


def _risk_report(changed_files: list[dict[str, Any]], safety_report: Any) -> dict[str, Any]:
    risk_level = "high" if safety_report.blocked else "medium" if safety_report.warnings else "low"

    return {
        "protocol_version": "v1",
        "risk_level": risk_level,
        "changed_file_count": len(changed_files),
        "blocked_count": len(safety_report.blocked),
        "warning_count": len(safety_report.warnings),
    }


def _quality_report(changed_files: list[dict[str, Any]], safety_report: Any) -> dict[str, Any]:
    return {
        "protocol_version": "v1",
        "changed_file_count": len(changed_files),
        "blocked_count": len(safety_report.blocked),
        "warning_count": len(safety_report.warnings),
    }


def _security_report(safety_report: Any) -> dict[str, Any]:
    return {
        "protocol_version": "v1",
        "blocked": safety_report.blocked,
        "warnings": safety_report.warnings,
    }
