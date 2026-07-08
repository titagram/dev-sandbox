import sys
import types
import json

from devboard_analyzer.code_graph import build_code_graph, relation_index_from_graph, symbol_index_from_graph
from devboard_analyzer.genesis_bundle import build_genesis_bundle


def test_code_graph_extracts_python_symbols_and_relations(tmp_path):
    repo = tmp_path / "repo"
    package = repo / "src" / "simple_app"
    package.mkdir(parents=True)
    source = package / "routes.py"
    source.write_text(
        "from simple_app.service import Service\n\n"
        "class Router:\n"
        "    def health(self):\n"
        "        return Service().ok()\n\n"
        "def make_router():\n"
        "    return Router()\n"
    )

    graph = build_code_graph(repo, [source], {"repository_id": "repo_123"})
    symbols = symbol_index_from_graph(graph)
    relations = relation_index_from_graph(graph)

    assert graph["source_status"] == "verified_from_code"
    assert graph["repository_id"] == "repo_123"
    assert any(node["properties"].get("name") == "Router" for node in graph["nodes"])
    assert any(node["properties"].get("name") == "health" for node in graph["nodes"])
    assert any(symbol["symbol_name"] == "Router" for symbol in symbols["symbols"])
    assert any(relation["relation_type"] == "DECLARES" for relation in relations["relations"])
    assert any(relation["relation_type"] == "CALLS" for relation in relations["relations"])


def test_code_graph_uses_graphify_when_available(monkeypatch, tmp_path):
    repo = tmp_path / "repo"
    repo.mkdir()
    source = repo / "app.py"
    source.write_text("def health():\n    return True\n")
    graphify_module = types.ModuleType("graphify")
    extract_module = types.ModuleType("graphify.extract")

    def fake_extract(paths, cache_root, parallel):
        return {
            "nodes": [
                {
                    "id": "symbol:health",
                    "label": "health",
                    "file_type": "function",
                    "source_file": str(source),
                    "source_location": "L1",
                    "confidence": "EXTRACTED",
                }
            ],
            "edges": [],
        }

    extract_module.extract = fake_extract
    monkeypatch.setitem(sys.modules, "graphify", graphify_module)
    monkeypatch.setitem(sys.modules, "graphify.extract", extract_module)

    graph = build_code_graph(repo, [source], {"repository_id": "repo_123"})

    assert graph["analyzer"] == "graphify"
    assert graph["parser"] == "tree-sitter"
    assert graph["graph_extraction_mode"] == "graphify"
    assert graph["nodes"][0]["properties"]["extractor"] == "graphify"


def test_code_graph_uses_lightweight_fallback_for_javascript_symbols(tmp_path):
    repo = tmp_path / "repo"
    repo.mkdir()
    source = repo / "app.js"
    source.write_text("export function health() { return true; }\nclass Router {}\n")

    graph = build_code_graph(repo, [source], {"repository_id": "repo_123"})

    assert graph["graph_extraction_mode"] == "lightweight_fallback"
    assert graph["analyzer"] == "lightweight_fallback"
    assert graph["parser"] == "regex"
    assert any(node["properties"].get("name") == "health" for node in graph["nodes"])
    assert any(node["properties"].get("name") == "Router" for node in graph["nodes"])


def test_code_graph_marks_file_only_when_no_symbols_are_extractable(tmp_path):
    repo = tmp_path / "repo"
    repo.mkdir()
    source = repo / "README.md"
    source.write_text("# DevBoard\n")

    graph = build_code_graph(repo, [source], {"repository_id": "repo_123"})

    assert graph["graph_extraction_mode"] == "file_only"
    assert graph["analyzer"] == "file_inventory"
    assert graph["parser"] == "none"
    assert graph["affected_symbol_ids"] == []
    assert graph["relationships_upserted"] == 0


def test_genesis_bundle_records_graph_extraction_metadata_in_manifest(tmp_path):
    repo = tmp_path / "repo"
    output = tmp_path / "bundle"
    repo.mkdir()
    (repo / "app.js").write_text("export function health() { return true; }\n")

    build_genesis_bundle(repo, output, {"repository_id": "repo_123"})
    manifest = json.loads((output / "genesis-manifest.json").read_text())
    graph_artifact = next(artifact for artifact in manifest["artifacts"] if artifact["artifact_type"] == "graph_snapshot")

    assert graph_artifact["graph_extraction_mode"] == "lightweight_fallback"
    assert graph_artifact["graph_parser"] == "regex"
    assert graph_artifact["graph_analyzer"] == "lightweight_fallback"
