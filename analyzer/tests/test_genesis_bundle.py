import json

from devboard_analyzer.genesis_bundle import build_genesis_bundle


def test_genesis_bundle_contains_required_artifacts(tmp_path):
    repo = tmp_path / "repo"
    repo.mkdir()
    (repo / "app.py").write_text("def health():\n    return {'ok': True}\n")
    output = tmp_path / "bundle"

    bundle = build_genesis_bundle(
        repo,
        output,
        {
            "project_id": "proj_123",
            "repository_id": "repo_123",
            "run_id": "run_123",
            "code_exposure": "full_code_artifacts",
        },
    )

    assert {artifact["filename"] for artifact in bundle["artifacts"]} >= {
        "genesis-manifest.json",
        "file-inventory.json",
        "file-hashes.json",
        "symbol-index.json",
        "relation-index.json",
        "graph-snapshot.json",
        "wiki-pages.json",
        "analysis-quality-report.json",
        "security-report.json",
    }


def test_genesis_manifest_records_protocol_and_code_exposure(tmp_path):
    repo = tmp_path / "repo"
    repo.mkdir()
    (repo / "app.py").write_text("print('ok')\n")
    output = tmp_path / "bundle"

    build_genesis_bundle(repo, output, {"code_exposure": "full_code_artifacts"})
    manifest = json.loads((output / "genesis-manifest.json").read_text())

    assert manifest["protocol_version"] == "v1"
    assert manifest["code_exposure"] == "full_code_artifacts"


def test_genesis_manifest_records_upload_artifact_contract(tmp_path):
    repo = tmp_path / "repo"
    repo.mkdir()
    (repo / "app.py").write_text("print('ok')\n")
    output = tmp_path / "bundle"

    build_genesis_bundle(repo, output, {"code_exposure": "full_code_artifacts"})
    manifest = json.loads((output / "genesis-manifest.json").read_text())

    assert "genesis-manifest.json" not in {artifact["filename"] for artifact in manifest["artifacts"]}
    assert all(len(artifact["artifact_id"]) == 26 for artifact in manifest["artifacts"])
    assert all(artifact["chunk_count"] >= 1 for artifact in manifest["artifacts"])


def test_genesis_bundle_uses_code_graph_for_symbols_and_relations(tmp_path):
    repo = tmp_path / "repo"
    repo.mkdir()
    (repo / "app.py").write_text("def health():\n    return {'ok': True}\n")
    output = tmp_path / "bundle"

    build_genesis_bundle(repo, output, {"repository_id": "repo_123"})

    graph = json.loads((output / "graph-snapshot.json").read_text())
    symbols = json.loads((output / "symbol-index.json").read_text())
    relations = json.loads((output / "relation-index.json").read_text())

    assert graph["graph_mode"] == "full_snapshot"
    assert any(node["properties"].get("name") == "health" for node in graph["nodes"])
    assert any(symbol["symbol_name"] == "health" for symbol in symbols["symbols"])
    assert any(relation["relation_type"] == "DECLARES" for relation in relations["relations"])


def test_wiki_page_artifact_includes_source_metadata(tmp_path):
    repo = tmp_path / "repo"
    repo.mkdir()
    (repo / "app.py").write_text("print('ok')\n")
    output = tmp_path / "bundle"

    build_genesis_bundle(repo, output, {"repository_id": "repo_123"})
    wiki_pages = json.loads((output / "wiki-pages.json").read_text())

    assert wiki_pages["pages"][0]["source_status"] == "verified_from_code"
    assert wiki_pages["pages"][0]["evidence_refs"]
