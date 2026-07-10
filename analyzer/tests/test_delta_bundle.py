import json

from devboard_analyzer.delta_bundle import build_delta_bundle
from devboard_analyzer.file_hashes import hash_file


def test_delta_bundle_records_changed_files_against_base_hashes(tmp_path):
    repo = tmp_path / "repo"
    repo.mkdir()
    source = repo / "app.py"
    source.write_text("print('old')\n")
    old_hash = hash_file(source)
    source.write_text("print('new')\n")

    output = tmp_path / "delta"
    build_delta_bundle(
        repo,
        output,
        {
            "project_id": "proj_123",
            "repository_id": "repo_123",
            "local_workspace_id": "lw_123",
            "run_id": "run_123",
            "base_snapshot_id": "snap_base",
            "base_file_hashes": {"app.py": old_hash},
        },
    )

    manifest = json.loads((output / "delta-manifest.json").read_text())
    diff_summary = json.loads((output / "diff-summary.json").read_text())

    assert manifest["bundle_type"] == "delta_sync"
    assert manifest["base_snapshot_id"] == "snap_base"
    assert {artifact["artifact_type"] for artifact in manifest["artifacts"]} >= {
        "delta_manifest",
        "file_hashes",
        "diff_summary",
        "symbol_index",
        "relation_index",
        "graph_snapshot",
        "wiki_pages",
        "risk_report",
        "analysis_quality_report",
        "security_report",
    }
    assert diff_summary["changed_file_count"] == 1
    assert diff_summary["modified_file_count"] == 1
    assert diff_summary["changed_files"][0]["path"] == "app.py"
    assert diff_summary["changed_files"][0]["old_sha256"] == old_hash
    assert diff_summary["changed_files"][0]["change_type"] == "modified"


def test_delta_bundle_records_affected_symbols_and_payload(tmp_path):
    repo = tmp_path / "repo"
    repo.mkdir()
    source = repo / "app.py"
    source.write_text("def health():\n    return {'ok': True}\n")
    output = tmp_path / "delta"

    build_delta_bundle(
        repo,
        output,
        {
            "repository_id": "repo_123",
            "base_snapshot_id": "snap_base",
            "base_file_hashes": {},
        },
    )

    payload = json.loads((output / "delta-payload.json").read_text())
    graph = json.loads((output / "graph-snapshot.json").read_text())
    symbols = json.loads((output / "symbol-index.json").read_text())

    assert payload["graph_mode"] == "affected_subgraph"
    assert graph["graph_mode"] == "affected_subgraph"
    assert any(symbol["symbol_name"] == "health" for symbol in symbols["symbols"])
    assert any("app.py" in path for path in graph["affected_file_paths"])


def test_delta_manifest_artifact_hash_matches_payload_file(tmp_path):
    repo = tmp_path / "repo"
    repo.mkdir()
    (repo / "app.py").write_text("print('hello')\n")
    output = tmp_path / "delta"

    build_delta_bundle(repo, output, {"base_snapshot_id": "snap_base"})

    manifest = json.loads((output / "delta-manifest.json").read_text())
    payload_artifact = next(artifact for artifact in manifest["artifacts"] if artifact["artifact_type"] == "delta_manifest")

    assert payload_artifact["sha256"] == hash_file(output / payload_artifact["filename"])


def test_delta_bundle_emits_file_and_symbol_tombstones(tmp_path):
    repo = tmp_path / "repo"
    repo.mkdir()
    output = tmp_path / "delta"

    build_delta_bundle(
        repo,
        output,
        {
            "base_snapshot_id": "snap_base",
            "base_file_hashes": {"deleted.py": "sha256:old"},
            "base_symbols": [
                {"symbol_id": "symbol:deleted.py:gone", "file_path": "deleted.py"},
            ],
            "base_relations": [
                {
                    "relation_id": "declares:file:deleted.py->symbol:deleted.py:gone:1",
                    "relation_type": "DECLARES",
                    "source_id": "file:deleted.py",
                    "target_id": "symbol:deleted.py:gone",
                    "properties": {"path": "deleted.py"},
                }
            ],
        },
    )

    graph = json.loads((output / "graph-snapshot.json").read_text())
    payload = json.loads((output / "delta-payload.json").read_text())

    assert {item["id"] for item in graph["nodes_deleted"]} == {
        "file:deleted.py",
        "symbol:deleted.py:gone",
    }
    assert graph["nodes_deleted_count"] == 2
    assert graph["relationships_deleted"] == [
        {
            "id": "declares:file:deleted.py->symbol:deleted.py:gone:1",
            "kind": "relationship",
            "path": "deleted.py",
        }
    ]
    assert graph["relationships_deleted_count"] == 1
    assert graph["affected_file_paths"] == ["deleted.py"]
    assert "symbol:deleted.py:gone" in graph["affected_symbol_ids"]
    assert payload["deleted_file_tombstones"] == [{"path": "deleted.py"}]


def test_two_consecutive_deltas_keep_complete_symbol_baseline(tmp_path):
    repo = tmp_path / "repo"
    repo.mkdir()
    app = repo / "app.py"
    unchanged = repo / "unchanged.py"
    app.write_text("def old_name():\n    return 1\n")
    unchanged.write_text("def keep_me():\n    return 2\n")
    base_hashes = {"app.py": hash_file(app), "unchanged.py": hash_file(unchanged)}
    base_symbols = [
        {"symbol_id": "symbol:app.py:old_name", "symbol_name": "old_name", "symbol_kind": "function", "file_path": "app.py"},
        {"symbol_id": "symbol:unchanged.py:keep_me", "symbol_name": "keep_me", "symbol_kind": "function", "file_path": "unchanged.py"},
    ]
    base_relations = [
        {
            "relation_id": "declares:file:app.py->symbol:app.py:old_name:1",
            "relation_type": "DECLARES",
            "source_id": "file:app.py",
            "target_id": "symbol:app.py:old_name",
            "properties": {"path": "app.py"},
        },
        {
            "relation_id": "declares:file:unchanged.py->symbol:unchanged.py:keep_me:1",
            "relation_type": "DECLARES",
            "source_id": "file:unchanged.py",
            "target_id": "symbol:unchanged.py:keep_me",
            "properties": {"path": "unchanged.py"},
        },
    ]

    app.write_text("def new_name():\n    return 1\n")
    first_output = tmp_path / "delta-one"
    build_delta_bundle(
        repo,
        first_output,
        {
            "base_snapshot_id": "snap-one",
            "base_file_hashes": base_hashes,
            "base_symbols": base_symbols,
            "base_symbols_complete": True,
            "base_relations": base_relations,
            "base_relations_complete": True,
        },
    )
    first_symbols = json.loads((first_output / "symbol-index.json").read_text())["symbols"]
    first_hashes = {
        row["path"]: row["sha256"]
        for row in json.loads((first_output / "file-hashes.json").read_text())["hashes"]
    }
    first_relations_document = json.loads((first_output / "relation-index.json").read_text())
    first_relations = first_relations_document["relations"]
    first_graph = json.loads((first_output / "graph-snapshot.json").read_text())
    assert {item["symbol_name"] for item in first_symbols} >= {"new_name", "keep_me"}
    assert "old_name" not in {item["symbol_name"] for item in first_symbols}
    assert first_relations_document["baseline_complete"] is True
    assert "declares:file:unchanged.py->symbol:unchanged.py:keep_me:1" in {
        item["relation_id"] for item in first_relations
    }
    assert "declares:file:app.py->symbol:app.py:old_name:1" in {
        item["id"] for item in first_graph["relationships_deleted"]
    }

    unchanged.unlink()
    second_output = tmp_path / "delta-two"
    build_delta_bundle(
        repo,
        second_output,
        {
            "base_snapshot_id": "snap-two",
            "base_file_hashes": first_hashes,
            "base_symbols": first_symbols,
            "base_symbols_complete": True,
            "base_relations": first_relations,
            "base_relations_complete": True,
        },
    )
    second_symbols = json.loads((second_output / "symbol-index.json").read_text())["symbols"]
    second_graph = json.loads((second_output / "graph-snapshot.json").read_text())
    second_relations = json.loads((second_output / "relation-index.json").read_text())["relations"]

    assert {item["symbol_name"] for item in second_symbols} >= {"new_name"}
    assert "keep_me" not in {item["symbol_name"] for item in second_symbols}
    assert "symbol:unchanged.py:keep_me" in {item["id"] for item in second_graph["nodes_deleted"]}
    assert "declares:file:unchanged.py->symbol:unchanged.py:keep_me:1" in {
        item["id"] for item in second_graph["relationships_deleted"]
    }
    assert "declares:file:unchanged.py->symbol:unchanged.py:keep_me:1" not in {
        item["relation_id"] for item in second_relations
    }


def test_delta_changed_caller_resolves_unchanged_imported_callee(tmp_path):
    repo = tmp_path / "repo"
    repo.mkdir()
    caller = repo / "app.py"
    helper = repo / "helpers.py"
    caller.write_text("from helpers import helper\n\ndef caller():\n    return helper()\n")
    helper.write_text("def helper():\n    return 1\n")
    base_hashes = {"app.py": hash_file(caller), "helpers.py": hash_file(helper)}
    caller.write_text("from helpers import helper\n\ndef caller():\n    value = helper()\n    return value\n")

    output = tmp_path / "delta"
    build_delta_bundle(repo, output, {"base_snapshot_id": "snap_base", "base_file_hashes": base_hashes})

    graph = json.loads((output / "graph-snapshot.json").read_text())
    call_targets = {
        relation["target_id"]
        for relation in graph["relationships"]
        if relation["type"] == "CALLS" and relation["source_id"] == "symbol:app.py:caller"
    }

    assert graph["affected_file_paths"] == ["app.py"]
    assert "file:helpers.py" not in {node["id"] for node in graph["nodes"]}
    assert "symbol:helpers.py:helper" in call_targets
