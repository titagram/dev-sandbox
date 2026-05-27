#!/usr/bin/env python3

from __future__ import annotations

import json
import re
from collections import defaultdict
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_GRAPH = ROOT / "graph" / "graph.json"
DEFAULT_SIDECAR = ROOT / "graph" / "sidecar.json"
DEFAULT_OUT = ROOT / "graph" / "neo4j.cypher"


def safe_rel_type(value: str | None) -> str:
    cleaned = re.sub(r"[^A-Za-z0-9_]+", "_", value or "").strip("_").upper()
    if not cleaned:
        return "RELATED"
    if cleaned[0].isdigit():
        cleaned = f"R_{cleaned}"
    return cleaned


def cypher_value(value: Any) -> str:
    if value is None:
        return "null"
    if isinstance(value, bool):
        return "true" if value else "false"
    if isinstance(value, (int, float)):
        return str(value)
    if isinstance(value, list):
        scalar_items = [item for item in value if isinstance(item, (str, int, float, bool)) or item is None]
        return "[" + ", ".join(cypher_value(item) for item in scalar_items) + "]"
    return json.dumps(str(value), ensure_ascii=False)


def cypher_map(properties: dict[str, Any]) -> str:
    items = []
    for key, value in sorted(properties.items()):
        if isinstance(value, (dict, tuple, set)):
            continue
        clean_key = re.sub(r"[^A-Za-z0-9_]+", "_", key).strip("_") or "property"
        if clean_key[0].isdigit():
            clean_key = f"p_{clean_key}"
        items.append(f"{clean_key}: {cypher_value(value)}")
    return "{" + ", ".join(items) + "}"


def scalar_props(data: dict[str, Any]) -> dict[str, Any]:
    return {
        key: value
        for key, value in data.items()
        if isinstance(value, (str, int, float, bool, list)) or value is None
    }


def build_rows(graph: dict[str, Any], sidecar: dict[str, Any] | None = None) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    sidecar = sidecar or {"nodes": [], "edges": []}
    nodes = []
    edges = []
    graph_file_nodes: dict[str, list[str]] = defaultdict(list)
    sidecar_file_nodes: dict[str, str] = {}

    for node in graph.get("nodes", []):
        if not isinstance(node, dict):
            continue
        original_id = str(node.get("id") or node.get("label") or "")
        row = scalar_props(node)
        row.update({"id": f"graphify:{original_id}", "original_id": original_id, "source_system": "graphify"})
        nodes.append(row)
        source_file = node.get("source_file")
        if isinstance(source_file, str):
            graph_file_nodes[source_file].append(row["id"])

    for link in graph.get("links", []):
        if not isinstance(link, dict):
            continue
        source = link.get("source")
        target = link.get("target")
        if source is None or target is None:
            continue
        relation = str(link.get("relation") or "related")
        edges.append(
            {
                "source": f"graphify:{source}",
                "target": f"graphify:{target}",
                "relation": safe_rel_type(relation),
                "props": {**scalar_props(link), "source_system": "graphify"},
            }
        )

    for node in sidecar.get("nodes", []):
        if not isinstance(node, dict):
            continue
        original_id = str(node.get("id") or node.get("label") or "")
        row = scalar_props(node)
        row.update({"id": f"sidecar:{original_id}", "original_id": original_id, "source_system": "sidecar"})
        nodes.append(row)
        if node.get("type") == "file" and isinstance(node.get("path"), str):
            sidecar_file_nodes[node["path"]] = row["id"]

    for edge in sidecar.get("edges", []):
        if not isinstance(edge, dict):
            continue
        source = edge.get("source")
        target = edge.get("target")
        if source is None or target is None:
            continue
        relation = str(edge.get("type") or "related")
        edges.append(
            {
                "source": f"sidecar:{source}",
                "target": f"sidecar:{target}",
                "relation": safe_rel_type(relation),
                "props": {**scalar_props(edge), "source_system": "sidecar"},
            }
        )

    for path, sidecar_id in sorted(sidecar_file_nodes.items()):
        for graph_id in sorted(graph_file_nodes.get(path, [])):
            edges.append(
                {
                    "source": sidecar_id,
                    "target": graph_id,
                    "relation": "SAME_FILE",
                    "props": {"source_system": "bridge", "relation": "same_file"},
                }
            )

    return nodes, edges


def write_cypher(nodes: list[dict[str, Any]], edges: list[dict[str, Any]], path: Path = DEFAULT_OUT) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    lines = [
        "CREATE CONSTRAINT sandbox_graph_node_id IF NOT EXISTS FOR (n:SandboxGraphNode) REQUIRE n.id IS UNIQUE;",
        "MATCH (n:SandboxGraphNode) DETACH DELETE n;",
    ]
    if nodes:
        node_payload = "[" + ", ".join(cypher_map(node) for node in nodes) + "]"
        lines.append(f"UNWIND {node_payload} AS row\nMERGE (n:SandboxGraphNode {{id: row.id}})\nSET n += row;")
    for index, edge in enumerate(edges):
        props = cypher_map(edge["props"])
        lines.append(
            "\n".join(
                [
                    f"MATCH (source:SandboxGraphNode {{id: {cypher_value(edge['source'])}}})",
                    f"MATCH (target:SandboxGraphNode {{id: {cypher_value(edge['target'])}}})",
                    f"MERGE (source)-[r:{edge['relation']} {{edge_id: {cypher_value(str(index) + ':' + edge['source'] + '->' + edge['target'])}}}]->(target)",
                    f"SET r += {props};",
                ]
            )
        )
    path.write_text("\n\n".join(lines) + "\n")


def load_json(path: Path) -> dict[str, Any]:
    if not path.exists():
        return {"nodes": [], "links": [], "edges": []}
    return json.loads(path.read_text())


def main() -> int:
    graph = load_json(DEFAULT_GRAPH)
    sidecar = load_json(DEFAULT_SIDECAR)
    nodes, edges = build_rows(graph, sidecar)
    write_cypher(nodes, edges)
    print(f"Wrote {DEFAULT_OUT} with {len(nodes)} nodes and {len(edges)} relationships.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
