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


def test_code_graph_resolves_calls_to_internal_symbols(tmp_path):
    repo = tmp_path / "repo"
    repo.mkdir()
    source = repo / "app.py"
    source.write_text(
        "def helper():\n"
        "    return 1\n"
        "\n"
        "def caller():\n"
        "    return helper()\n"
    )

    graph = build_code_graph(repo, [source], {"repository_id": "repo_123"})

    helper_node = next(
        node for node in graph["nodes"]
        if node["properties"].get("name") == "helper" and node["properties"].get("kind") == "function"
    )
    caller_node = next(
        node for node in graph["nodes"]
        if node["properties"].get("name") == "caller" and node["properties"].get("kind") == "function"
    )
    calls_rels = [
        rel for rel in graph["relationships"]
        if rel["type"] == "CALLS" and rel["source_id"] == caller_node["id"]
    ]
    assert len(calls_rels) >= 1
    assert calls_rels[0]["target_id"] == helper_node["id"]
    assert not calls_rels[0]["target_id"].startswith("external:")


def test_code_graph_external_calls_remain_external(tmp_path):
    repo = tmp_path / "repo"
    repo.mkdir()
    source = repo / "app.py"
    source.write_text(
        "import json\n"
        "\n"
        "def caller():\n"
        "    return json.dumps({})\n"
    )

    graph = build_code_graph(repo, [source], {"repository_id": "repo_123"})

    caller_node = next(
        node for node in graph["nodes"]
        if node["properties"].get("name") == "caller" and node["properties"].get("kind") == "function"
    )
    calls_rels = [
        rel for rel in graph["relationships"]
        if rel["type"] == "CALLS" and rel["source_id"] == caller_node["id"]
    ]
    assert len(calls_rels) >= 1
    assert calls_rels[0]["target_id"].startswith("external:")


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


def test_ambiguous_simple_name_remains_external(tmp_path):
    source = (
        "class ServiceA:\n"
        "    def run(self):\n"
        "        return 1\n\n"
        "class ServiceB:\n"
        "    def run(self):\n"
        "        return 2\n\n"
        "def start():\n"
        "    return ServiceA().run()\n"
    )
    repo = tmp_path / "repo"
    repo.mkdir()
    app = repo / "app.py"
    app.write_text(source)

    graph = build_code_graph(repo, [app], {"repository_id": "repo_123"})

    service_a_run = next(
        (n for n in graph["nodes"] if n["properties"].get("name") == "run" and n["id"].endswith(":ServiceA.run")),
        None,
    )
    service_b_run = next(
        (n for n in graph["nodes"] if n["properties"].get("name") == "run" and n["id"].endswith(":ServiceB.run")),
        None,
    )
    start_node = next(
        n for n in graph["nodes"] if n["properties"].get("name") == "start" and n["properties"].get("kind") == "function"
    )

    calls_rels = [r for r in graph["relationships"] if r["type"] == "CALLS" and r["source_id"] == start_node["id"]]

    for rel in calls_rels:
        assert rel["target_id"] != service_b_run["id"], "start() call should not resolve to ServiceB.run"


def test_unique_simple_name_still_resolves_internally(tmp_path):
    source = (
        "class Service:\n"
        "    def unique_helper(self):\n"
        "        return 1\n\n"
        "def caller():\n"
        "    return Service().unique_helper()\n"
    )
    repo = tmp_path / "repo"
    repo.mkdir()
    app = repo / "app.py"
    app.write_text(source)

    graph = build_code_graph(repo, [app], {"repository_id": "repo_123"})

    helper_node = next(
        n for n in graph["nodes"]
        if n["properties"].get("name") == "unique_helper"
    )
    caller_node = next(
        n for n in graph["nodes"]
        if n["properties"].get("name") == "caller" and n["properties"].get("kind") == "function"
    )
    calls_rels = [r for r in graph["relationships"] if r["type"] == "CALLS" and r["source_id"] == caller_node["id"]]

    assert len(calls_rels) >= 1
    assert calls_rels[0]["target_id"] == helper_node["id"]
    assert not calls_rels[0]["target_id"].startswith("external:")


def _calls_targets(graph, symbol_id):
    return {
        relation["target_id"]
        for relation in graph["relationships"]
        if relation["type"] == "CALLS" and relation["source_id"] == symbol_id
    }


def test_code_graph_resolves_cross_file_from_import_independent_of_traversal_order(tmp_path):
    repo = tmp_path / "repo"
    repo.mkdir()
    caller = repo / "app.py"
    helper = repo / "helpers.py"
    caller.write_text("from helpers import helper\n\ndef caller():\n    return helper()\n")
    helper.write_text("def helper():\n    return 1\n")

    graph = build_code_graph(repo, [caller, helper], {"repository_id": "repo_123"})

    assert "symbol:helpers.py:helper" in _calls_targets(graph, "symbol:app.py:caller")


def test_code_graph_disambiguates_duplicate_helpers_with_explicit_import(tmp_path):
    repo = tmp_path / "repo"
    repo.mkdir()
    caller = repo / "app.py"
    helpers = repo / "helpers.py"
    other_helpers = repo / "other_helpers.py"
    caller.write_text("from other_helpers import helper\n\ndef caller():\n    return helper()\n")
    helpers.write_text("def helper():\n    return 'wrong'\n")
    other_helpers.write_text("def helper():\n    return 'right'\n")

    graph = build_code_graph(repo, [caller, helpers, other_helpers], {"repository_id": "repo_123"})

    targets = _calls_targets(graph, "symbol:app.py:caller")
    assert "symbol:other_helpers.py:helper" in targets
    assert "symbol:helpers.py:helper" not in targets


def test_code_graph_resolves_from_import_alias(tmp_path):
    repo = tmp_path / "repo"
    repo.mkdir()
    caller = repo / "app.py"
    helper = repo / "helpers.py"
    caller.write_text("from helpers import helper as imported_helper\n\ndef caller():\n    return imported_helper()\n")
    helper.write_text("def helper():\n    return 1\n")

    graph = build_code_graph(repo, [caller, helper], {"repository_id": "repo_123"})

    assert "symbol:helpers.py:helper" in _calls_targets(graph, "symbol:app.py:caller")


def test_code_graph_resolves_module_import_alias_attribute_call(tmp_path):
    repo = tmp_path / "repo"
    repo.mkdir()
    caller = repo / "app.py"
    helper = repo / "helpers.py"
    caller.write_text("import helpers as h\n\ndef caller():\n    return h.helper()\n")
    helper.write_text("def helper():\n    return 1\n")

    graph = build_code_graph(repo, [caller, helper], {"repository_id": "repo_123"})

    assert "symbol:helpers.py:helper" in _calls_targets(graph, "symbol:app.py:caller")


def test_code_graph_does_not_guess_globally_unique_unimported_name(tmp_path):
    repo = tmp_path / "repo"
    repo.mkdir()
    caller = repo / "app.py"
    helper = repo / "helpers.py"
    caller.write_text("def caller():\n    return helper()\n")
    helper.write_text("def helper():\n    return 1\n")

    graph = build_code_graph(repo, [caller, helper], {"repository_id": "repo_123"})

    targets = _calls_targets(graph, "symbol:app.py:caller")
    assert "external:helper" in targets
    assert "symbol:helpers.py:helper" not in targets
