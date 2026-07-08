import json
from pathlib import Path

from typer.testing import CliRunner

from devboard_plugin import cli
from devboard_plugin.cli import app


class FakeGenesisClient:
    def __init__(self):
        self.started = False
        self.policy_requested = False

    def start_run(self, payload):
        self.started = True
        return {"run_id": "run_123", "status": "started"}

    def repository_policy(self, repository_id):
        self.policy_requested = True
        return {
            "repository_id": repository_id,
            "code_exposure": "full_code_artifacts",
            "graph_required": True,
        }


def test_genesis_run_builds_bundle_and_records_bundle_path(monkeypatch, tmp_path):
    fake = FakeGenesisClient()
    monkeypatch.setattr(cli, "client_from_options", lambda server_url, token: fake)
    monkeypatch.setattr(
        cli,
        "build_genesis_bundle",
        lambda repo_path, output_dir, context: _write_fake_bundle(Path(output_dir), context),
    )

    result = CliRunner().invoke(
        app,
        [
            "genesis",
            "run",
            "--project-id",
            "proj_123",
            "--repository-id",
            "repo_123",
            "--local-workspace-id",
            "lw_123",
            "--repo-path",
            str(tmp_path),
            "--server-url",
            "https://devboard.test",
            "--token",
            "devb_live_token|secret",
        ],
    )

    assert result.exit_code == 0
    assert fake.started
    assert fake.policy_requested

    state = json.loads((tmp_path / ".devboard" / "state.json").read_text())

    assert state["run_id"] == "run_123"
    assert state["genesis_bundle_path"].endswith(".devboard/artifacts/genesis/run_123")
    assert "devb_live_" not in json.dumps(state)


def _write_fake_bundle(output_dir: Path, context: dict):
    output_dir.mkdir(parents=True, exist_ok=True)
    (output_dir / "genesis-manifest.json").write_text("{}")
    return {"output_dir": str(output_dir), "context": context, "artifacts": []}
