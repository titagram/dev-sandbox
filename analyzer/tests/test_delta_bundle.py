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
