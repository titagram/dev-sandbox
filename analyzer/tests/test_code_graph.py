import sys
import types

from devboard_analyzer.code_graph import build_code_graph, relation_index_from_graph, symbol_index_from_graph


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
    assert graph["nodes"][0]["properties"]["extractor"] == "graphify"
