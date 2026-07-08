import json
from pathlib import Path

from typer.testing import CliRunner

from devboard_plugin import cli
from devboard_plugin.cli import app


class FakeDeltaClient:
    def __init__(self):
        self.started_payload = None
        self.policy_requested = False

    def start_run(self, payload):
        self.started_payload = payload
        return {"run_id": "run_delta", "status": "started"}

    def repository_policy(self, repository_id):
        self.policy_requested = True
        return {
            "repository_id": repository_id,
            "code_exposure": "full_code_artifacts",
            "graph_required": True,
        }


def test_delta_run_builds_uploads_and_records_delta_state(monkeypatch, tmp_path):
    fake = FakeDeltaClient()
    uploads = []
    monkeypatch.setattr(cli, "client_from_options", lambda server_url, token: fake)
    monkeypatch.setattr(cli, "build_delta_bundle", fake_build_delta_bundle)
    monkeypatch.setattr(
        cli,
        "upload_delta_bundle",
        lambda client, **kwargs: uploads.append(kwargs) or {"status": "active", "snapshot_id": "snap_new"},
    )

    result = CliRunner().invoke(
        app,
        [
            "delta",
            "run",
            "--project-id",
            "proj_123",
            "--repository-id",
            "repo_123",
            "--local-workspace-id",
            "lw_123",
            "--base-snapshot-id",
            "snap_base",
            "--repo-path",
            str(tmp_path),
            "--server-url",
            "https://devboard.test",
            "--token",
            "devb_live_token|secret",
        ],
    )

    assert result.exit_code == 0
    assert fake.started_payload["run_type"] == "delta_sync"
    assert fake.policy_requested is True
    assert uploads[0]["run_id"] == "run_delta"
    assert uploads[0]["base_snapshot_id"] == "snap_base"

    state = json.loads((tmp_path / ".devboard" / "state.json").read_text())
    assert state["run_id"] == "run_delta"
    assert state["base_snapshot_id"] == "snap_base"
    assert state["snapshot_id"] == "snap_new"
    assert state["delta_bundle_path"].endswith(".devboard/artifacts/delta/run_delta")
    assert "devb_live_" not in json.dumps(state)


def test_delta_run_forwards_explicit_security_approval(monkeypatch, tmp_path):
    fake = FakeDeltaClient()
    uploads = []
    monkeypatch.setattr(cli, "client_from_options", lambda server_url, token: fake)
    monkeypatch.setattr(cli, "build_delta_bundle", fake_build_delta_bundle)
    monkeypatch.setattr(
        cli,
        "upload_delta_bundle",
        lambda client, **kwargs: uploads.append(kwargs) or {"status": "active", "snapshot_id": "snap_new"},
    )

    result = CliRunner().invoke(
        app,
        [
            "delta",
            "run",
            "--project-id",
            "proj_123",
            "--repository-id",
            "repo_123",
            "--local-workspace-id",
            "lw_123",
            "--base-snapshot-id",
            "snap_base",
            "--repo-path",
            str(tmp_path),
            "--server-url",
            "https://devboard.test",
            "--token",
            "devb_live_token|secret",
            "--allow-blocked-security-findings",
        ],
    )

    assert result.exit_code == 0
    assert uploads[0]["allow_blocked_security_findings"] is True


def fake_build_delta_bundle(repo_path: Path, output_dir: Path, context: dict):
    output_dir.mkdir(parents=True, exist_ok=True)
    (output_dir / "delta-manifest.json").write_text("{}")
    return {"output_dir": str(output_dir), "context": context, "artifacts": []}
