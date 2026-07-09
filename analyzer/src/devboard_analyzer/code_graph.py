from __future__ import annotations

import ast
import re
from pathlib import Path
from typing import Any

_LIGHTWEIGHT_PATTERNS: dict[str, list[tuple[str, re.Pattern[str]]]] = {
    ".js": [
        ("function", re.compile(r"^\s*(?:export\s+)?function\s+([A-Za-z_]\w*)\s*\(", re.MULTILINE)),
        ("class", re.compile(r"^\s*(?:export\s+)?class\s+([A-Za-z_]\w*)\b", re.MULTILINE)),
        ("const", re.compile(r"^\s*(?:export\s+)?const\s+([A-Za-z_]\w*)\s*=\s*(?:async\s*)?\(", re.MULTILINE)),
    ],
    ".jsx": [
        ("function", re.compile(r"^\s*(?:export\s+)?function\s+([A-Za-z_]\w*)\s*\(", re.MULTILINE)),
        ("class", re.compile(r"^\s*(?:export\s+)?class\s+([A-Za-z_]\w*)\b", re.MULTILINE)),
        ("const", re.compile(r"^\s*(?:export\s+)?const\s+([A-Za-z_]\w*)\s*=\s*(?:async\s*)?\(", re.MULTILINE)),
    ],
    ".ts": [
        ("function", re.compile(r"^\s*(?:export\s+)?function\s+([A-Za-z_]\w*)\s*\(", re.MULTILINE)),
        ("class", re.compile(r"^\s*(?:export\s+)?class\s+([A-Za-z_]\w*)\b", re.MULTILINE)),
        ("interface", re.compile(r"^\s*(?:export\s+)?interface\s+([A-Za-z_]\w*)\b", re.MULTILINE)),
        ("enum", re.compile(r"^\s*(?:export\s+)?enum\s+([A-Za-z_]\w*)\b", re.MULTILINE)),
        ("type", re.compile(r"^\s*(?:export\s+)?type\s+([A-Za-z_]\w*)\s*=", re.MULTILINE)),
    ],
    ".tsx": [
        ("function", re.compile(r"^\s*(?:export\s+)?function\s+([A-Za-z_]\w*)\s*\(", re.MULTILINE)),
        ("class", re.compile(r"^\s*(?:export\s+)?class\s+([A-Za-z_]\w*)\b", re.MULTILINE)),
        ("interface", re.compile(r"^\s*(?:export\s+)?interface\s+([A-Za-z_]\w*)\b", re.MULTILINE)),
        ("enum", re.compile(r"^\s*(?:export\s+)?enum\s+([A-Za-z_]\w*)\b", re.MULTILINE)),
        ("type", re.compile(r"^\s*(?:export\s+)?type\s+([A-Za-z_]\w*)\s*=", re.MULTILINE)),
    ],
    ".php": [
        ("class", re.compile(r"^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_]\w*)\b", re.MULTILINE)),
        ("interface", re.compile(r"^\s*interface\s+([A-Za-z_]\w*)\b", re.MULTILINE)),
        ("trait", re.compile(r"^\s*trait\s+([A-Za-z_]\w*)\b", re.MULTILINE)),
        ("function", re.compile(r"^\s*(?:public|protected|private)?\s*function\s+([A-Za-z_]\w*)\s*\(", re.MULTILINE)),
    ],
    ".go": [
        ("function", re.compile(r"^\s*func\s+(?:\([^)]+\)\s*)?([A-Za-z_]\w*)\s*\(", re.MULTILINE)),
        ("type", re.compile(r"^\s*type\s+([A-Za-z_]\w*)\s+(?:struct|interface)\b", re.MULTILINE)),
    ],
    ".java": [
        ("class", re.compile(r"^\s*(?:public\s+)?class\s+([A-Za-z_]\w*)\b", re.MULTILINE)),
        ("interface", re.compile(r"^\s*(?:public\s+)?interface\s+([A-Za-z_]\w*)\b", re.MULTILINE)),
        ("enum", re.compile(r"^\s*(?:public\s+)?enum\s+([A-Za-z_]\w*)\b", re.MULTILINE)),
    ],
    ".rb": [
        ("class", re.compile(r"^\s*class\s+([A-Za-z_]\w*(?:::[A-Za-z_]\w*)*)", re.MULTILINE)),
        ("module", re.compile(r"^\s*module\s+([A-Za-z_]\w*(?:::[A-Za-z_]\w*)*)", re.MULTILINE)),
        ("function", re.compile(r"^\s*def\s+([A-Za-z_]\w*[!?=]?)", re.MULTILINE)),
    ],
}


def build_code_graph(
    root: Path | str,
    files: list[Path],
    context: dict[str, Any] | None = None,
    graph_mode: str = "full_snapshot",
) -> dict[str, Any]:
    repo = Path(root)
    context = context or {}
    graphify_graph = _build_graphify_graph(repo, files, context, graph_mode)
    if graphify_graph is not None:
        return graphify_graph

    nodes: list[dict[str, Any]] = []
    relationships: list[dict[str, Any]] = []
    python_symbols_found = False
    lightweight_symbols_found = False

    for file_path in files:
        relative_path = file_path.relative_to(repo).as_posix()
        file_id = "file:" + relative_path
        nodes.append(
            {
                "id": file_id,
                "labels": ["File"],
                "properties": {"path": relative_path},
            }
        )

        if file_path.suffix == ".py":
            before_nodes = len(nodes)
            before_relationships = len(relationships)

            try:
                tree = ast.parse(file_path.read_text(), filename=relative_path)
            except SyntaxError:
                continue

            collector = _SymbolCollector(relative_path)
            collector.visit(tree)

            extractor = _PythonGraphExtractor(relative_path, file_id, collector.name_index)
            extractor.visit(tree)
            nodes.extend(extractor.nodes)
            relationships.extend(extractor.relationships)

            if len(nodes) > before_nodes or len(relationships) > before_relationships:
                python_symbols_found = True

            continue

        added_nodes, added_relationships = _lightweight_extract(file_path, relative_path, file_id)
        if added_nodes or added_relationships:
            lightweight_symbols_found = True
            nodes.extend(added_nodes)
            relationships.extend(added_relationships)

    affected_symbol_ids = [
        node["id"]
        for node in nodes
        if "Symbol" in node.get("labels", []) and node["id"].startswith("symbol:")
    ]
    extraction_mode, analyzer_name, parser_name = _graph_extraction_profile(
        python_symbols_found,
        lightweight_symbols_found,
    )

    return {
        "protocol_version": "v1",
        "source_type": "local_analyzer",
        "source_status": "verified_from_code",
        "repository_id": context.get("repository_id"),
        "base_snapshot_id": context.get("base_snapshot_id"),
        "analyzer": analyzer_name,
        "parser": parser_name,
        "graph_extraction_mode": extraction_mode,
        "graph_mode": graph_mode,
        "nodes_upserted": len(nodes),
        "nodes_deleted": [],
        "relationships_upserted": len(relationships),
        "relationships_deleted": [],
        "affected_file_paths": [path.relative_to(repo).as_posix() for path in files],
        "affected_symbol_ids": affected_symbol_ids,
        "nodes": nodes,
        "relationships": relationships,
    }


def _build_graphify_graph(
    repo: Path,
    files: list[Path],
    context: dict[str, Any],
    graph_mode: str,
) -> dict[str, Any] | None:
    try:
        from graphify.extract import extract
    except ImportError:
        return None

    try:
        extracted = extract(
            [str(path) for path in files],
            cache_root=str(repo / ".devboard" / "cache" / "graphify"),
            parallel=True,
        )
    except Exception:
        return None

    nodes = [_graphify_node(repo, node) for node in extracted.get("nodes", [])]
    relationships = [_graphify_relationship(edge) for edge in extracted.get("edges", [])]

    return {
        "protocol_version": "v1",
        "source_type": "local_analyzer",
        "source_status": "verified_from_code",
        "repository_id": context.get("repository_id"),
        "base_snapshot_id": context.get("base_snapshot_id"),
        "analyzer": "graphify",
        "parser": "tree-sitter",
        "graph_extraction_mode": "graphify",
        "graph_mode": graph_mode,
        "nodes_upserted": len(nodes),
        "nodes_deleted": [],
        "relationships_upserted": len(relationships),
        "relationships_deleted": [],
        "affected_file_paths": [path.relative_to(repo).as_posix() for path in files],
        "affected_symbol_ids": [
            node["id"]
            for node in nodes
            if "Symbol" in node.get("labels", []) and node["id"].startswith("symbol:")
        ],
        "nodes": nodes,
        "relationships": relationships,
    }


def _graph_extraction_profile(
    python_symbols_found: bool,
    lightweight_symbols_found: bool,
) -> tuple[str, str, str]:
    if lightweight_symbols_found:
        return (
            "lightweight_fallback",
            "lightweight_fallback",
            "ast+regex" if python_symbols_found else "regex",
        )

    if python_symbols_found:
        return ("python_ast_fallback", "python_ast", "ast")

    return ("file_only", "file_inventory", "none")


def _lightweight_extract(
    file_path: Path,
    relative_path: str,
    file_id: str,
) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    patterns = _LIGHTWEIGHT_PATTERNS.get(file_path.suffix.lower())
    if not patterns:
        return [], []

    try:
        content = file_path.read_text()
    except OSError:
        return [], []

    nodes: list[dict[str, Any]] = []
    relationships: list[dict[str, Any]] = []
    seen_ids: set[str] = set()

    for kind, pattern in patterns:
        for match in pattern.finditer(content):
            name = match.group(1)
            line_start = content.count("\n", 0, match.start()) + 1
            symbol_id = f"symbol:{relative_path}:{name}:{line_start}"

            if symbol_id in seen_ids:
                continue

            seen_ids.add(symbol_id)
            nodes.append(
                {
                    "id": symbol_id,
                    "labels": ["Symbol", kind.title()],
                    "properties": {
                        "name": name,
                        "kind": kind,
                        "path": relative_path,
                        "source_file": relative_path,
                        "source_location": f"L{line_start}",
                        "line_start": line_start,
                        "line_end": line_start,
                        "confidence": "LIGHTWEIGHT",
                        "extractor": "lightweight_fallback",
                    },
                }
            )
            relationships.append(
                {
                    "id": f"declares:{file_id}->{symbol_id}:{line_start}",
                    "source_id": file_id,
                    "target_id": symbol_id,
                    "type": "DECLARES",
                    "properties": {
                        "path": relative_path,
                        "source_file": relative_path,
                        "source_location": f"L{line_start}",
                        "line_start": line_start,
                        "line_end": line_start,
                        "confidence": "LIGHTWEIGHT",
                        "extractor": "lightweight_fallback",
                    },
                }
            )

    return nodes, relationships


def _graphify_node(repo: Path, node: dict[str, Any]) -> dict[str, Any]:
    source_file = node.get("source_file")
    path = _relative_source_path(repo, source_file)
    kind = node.get("file_type") or node.get("type") or "symbol"

    return {
        "id": node["id"],
        "labels": ["Symbol", str(kind).title()],
        "properties": {
            "name": node.get("label") or node.get("name") or node["id"],
            "kind": kind,
            "path": path,
            "source_location": node.get("source_location"),
            "confidence": node.get("confidence"),
            "extractor": "graphify",
        },
    }


def _graphify_relationship(edge: dict[str, Any]) -> dict[str, Any]:
    relation_type = edge.get("relation") or edge.get("type") or "RELATED"

    return {
        "id": edge.get("id") or f"{relation_type}:{edge.get('source')}->{edge.get('target')}",
        "source_id": edge.get("source") or edge.get("source_id"),
        "target_id": edge.get("target") or edge.get("target_id"),
        "type": str(relation_type).upper(),
        "properties": {
            "source_file": edge.get("source_file"),
            "source_location": edge.get("source_location"),
            "confidence": edge.get("confidence", "EXTRACTED"),
            "extractor": "graphify",
        },
    }


def _relative_source_path(repo: Path, source_file: Any) -> str | None:
    if not source_file:
        return None

    path = Path(str(source_file))
    try:
        return path.relative_to(repo).as_posix()
    except ValueError:
        return str(source_file)


def symbol_index_from_graph(graph: dict[str, Any]) -> dict[str, Any]:
    return {
        "protocol_version": "v1",
        "symbols": [
            {
                "symbol_id": node["id"],
                "symbol_name": node["properties"]["name"],
                "symbol_kind": node["properties"]["kind"],
                "file_path": node["properties"]["path"],
                "line_start": node["properties"].get("line_start"),
                "line_end": node["properties"].get("line_end"),
            }
            for node in graph.get("nodes", [])
            if "Symbol" in node.get("labels", [])
        ],
    }


def relation_index_from_graph(graph: dict[str, Any]) -> dict[str, Any]:
    return {
        "protocol_version": "v1",
        "relations": [
            {
                "relation_id": relationship["id"],
                "relation_type": relationship["type"],
                "source_id": relationship["source_id"],
                "target_id": relationship["target_id"],
                "properties": relationship.get("properties", {}),
            }
            for relationship in graph.get("relationships", [])
        ],
    }


class _SymbolCollector(ast.NodeVisitor):
    def __init__(self, relative_path: str):
        self.relative_path = relative_path
        self.name_index: dict[str, str] = {}
        self._seen_names: set[str] = set()
        self.name_stack: list[str] = []

    def visit_ClassDef(self, node: ast.ClassDef) -> Any:
        self._add_symbol(node.name)
        self.name_stack.append(node.name)
        self.generic_visit(node)
        self.name_stack.pop()

    def visit_FunctionDef(self, node: ast.FunctionDef) -> Any:
        self._add_symbol(node.name)
        self.name_stack.append(node.name)
        self.generic_visit(node)
        self.name_stack.pop()

    def visit_AsyncFunctionDef(self, node: ast.AsyncFunctionDef) -> Any:
        self.visit_FunctionDef(node)

    def _add_symbol(self, name: str) -> None:
        qualname = ".".join([*self.name_stack, name])
        symbol_id = f"symbol:{self.relative_path}:{qualname}"
        self.name_index[qualname] = symbol_id
        if name in self._seen_names:
            self.name_index.pop(name, None)
        else:
            self._seen_names.add(name)
            self.name_index[name] = symbol_id


class _PythonGraphExtractor(ast.NodeVisitor):
    def __init__(self, relative_path: str, file_id: str, name_index: dict[str, str] | None = None):
        self.relative_path = relative_path
        self.file_id = file_id
        self._name_index = name_index or {}
        self.nodes: list[dict[str, Any]] = []
        self.relationships: list[dict[str, Any]] = []
        self.scope_stack: list[str] = [file_id]
        self.name_stack: list[str] = []

    def visit_ClassDef(self, node: ast.ClassDef) -> Any:
        symbol_id = self._symbol_id(node.name)
        self._add_symbol(node, symbol_id, node.name, "class")
        self._add_relationship(self.scope_stack[-1], symbol_id, "DECLARES", node)

        self.scope_stack.append(symbol_id)
        self.name_stack.append(node.name)
        self.generic_visit(node)
        self.name_stack.pop()
        self.scope_stack.pop()

    def visit_FunctionDef(self, node: ast.FunctionDef) -> Any:
        symbol_id = self._symbol_id(node.name)
        kind = "method" if self.name_stack else "function"
        self._add_symbol(node, symbol_id, node.name, kind)
        self._add_relationship(self.scope_stack[-1], symbol_id, "DECLARES", node)

        self.scope_stack.append(symbol_id)
        self.name_stack.append(node.name)
        self.generic_visit(node)
        self.name_stack.pop()
        self.scope_stack.pop()

    def visit_AsyncFunctionDef(self, node: ast.AsyncFunctionDef) -> Any:
        self.visit_FunctionDef(node)

    def visit_Import(self, node: ast.Import) -> Any:
        for alias in node.names:
            target_id = "external:" + alias.name
            self._add_external_symbol(target_id, alias.name, node)
            self._add_relationship(self.file_id, target_id, "IMPORTS", node)

    def visit_ImportFrom(self, node: ast.ImportFrom) -> Any:
        module = node.module or ""
        for alias in node.names:
            imported_name = f"{module}.{alias.name}" if module else alias.name
            target_id = "external:" + imported_name
            self._add_external_symbol(target_id, imported_name, node)
            self._add_relationship(self.file_id, target_id, "IMPORTS", node)

    def visit_Call(self, node: ast.Call) -> Any:
        if len(self.scope_stack) > 1:
            target_name = _call_name(node.func)
            if target_name:
                resolved_id = self._name_index.get(target_name)
                if resolved_id:
                    self._add_relationship(self.scope_stack[-1], resolved_id, "CALLS", node)
                else:
                    target_id = "external:" + target_name
                    self._add_external_symbol(target_id, target_name, node)
                    self._add_relationship(self.scope_stack[-1], target_id, "CALLS", node)

        self.generic_visit(node)

    def _symbol_id(self, name: str) -> str:
        qualname = ".".join([*self.name_stack, name])
        return f"symbol:{self.relative_path}:{qualname}"

    def _add_symbol(self, node: ast.AST, symbol_id: str, name: str, kind: str) -> None:
        self.nodes.append(
            {
                "id": symbol_id,
                "labels": ["Symbol", kind.title()],
                "properties": {
                    "name": name,
                    "kind": kind,
                    "path": self.relative_path,
                    "source_file": self.relative_path,
                    "source_location": f"L{getattr(node, 'lineno', 0)}",
                    "line_start": getattr(node, "lineno", None),
                    "line_end": getattr(node, "end_lineno", None),
                    "confidence": "EXTRACTED",
                    "extractor": "python_ast",
                },
            }
        )

    def _add_external_symbol(self, symbol_id: str, name: str, node: ast.AST) -> None:
        if any(existing["id"] == symbol_id for existing in self.nodes):
            return

        self.nodes.append(
            {
                "id": symbol_id,
                "labels": ["Symbol", "External"],
                "properties": {
                    "name": name,
                    "kind": "external",
                    "path": self.relative_path,
                    "source_file": self.relative_path,
                    "source_location": f"L{getattr(node, 'lineno', 0)}",
                    "line_start": getattr(node, "lineno", None),
                    "line_end": getattr(node, "end_lineno", None),
                    "confidence": "EXTRACTED",
                    "extractor": "python_ast",
                },
            }
        )

    def _add_relationship(self, source_id: str, target_id: str, relation_type: str, node: ast.AST) -> None:
        self.relationships.append(
            {
                "id": f"{relation_type.lower()}:{source_id}->{target_id}:{getattr(node, 'lineno', 0)}",
                "source_id": source_id,
                "target_id": target_id,
                "type": relation_type,
                "properties": {
                    "path": self.relative_path,
                    "source_file": self.relative_path,
                    "source_location": f"L{getattr(node, 'lineno', 0)}",
                    "line_start": getattr(node, "lineno", None),
                    "line_end": getattr(node, "end_lineno", None),
                    "confidence": "EXTRACTED",
                    "extractor": "python_ast",
                },
            }
        )


def _call_name(node: ast.AST) -> str | None:
    if isinstance(node, ast.Name):
        return node.id

    if isinstance(node, ast.Attribute):
        parent = _call_name(node.value)
        return f"{parent}.{node.attr}" if parent else node.attr

    if isinstance(node, ast.Call):
        return _call_name(node.func)

    return None
