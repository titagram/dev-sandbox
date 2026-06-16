from typer.testing import CliRunner

from devboard_plugin import cli
from devboard_plugin.cli import app


class FakeRunClient:
    def __init__(self):
        self.calls = []

    def start_run(self, payload):
        self.calls.append(("start_run", payload))
        return {"run_id": "run_123", "status": "started"}

    def heartbeat_run(self, run_id, payload):
        self.calls.append(("heartbeat_run", run_id, payload))
        return {"run_id": run_id, "status": "heartbeat"}

    def finish_run(self, run_id, payload):
        self.calls.append(("finish_run", run_id, payload))
        return {"run_id": run_id, "status": payload["status"]}


def test_devboard_runs_start_sends_run_start_payload(monkeypatch):
    fake = FakeRunClient()
    monkeypatch.setattr(cli, "client_from_options", lambda server_url, token: fake)

    result = CliRunner().invoke(
        app,
        [
            "runs",
            "start",
            "--project-id",
            "proj_123",
            "--repository-id",
            "repo_123",
            "--local-workspace-id",
            "lw_123",
            "--branch",
            "main",
            "--base-sha",
            "abc123",
            "--head-sha",
            "abc123",
            "--server-url",
            "https://devboard.test",
            "--token",
            "devb_live_token|secret",
        ],
    )

    assert result.exit_code == 0
    assert fake.calls[0][0] == "start_run"
    assert fake.calls[0][1]["runtime_profile"] == "agent_plugin"
    assert fake.calls[0][1]["repository_id"] == "repo_123"


def test_devboard_runs_heartbeat_sends_run_id(monkeypatch):
    fake = FakeRunClient()
    monkeypatch.setattr(cli, "client_from_options", lambda server_url, token: fake)

    result = CliRunner().invoke(
        app,
        [
            "runs",
            "heartbeat",
            "run_123",
            "--message",
            "still working",
            "--server-url",
            "https://devboard.test",
            "--token",
            "devb_live_token|secret",
        ],
    )

    assert result.exit_code == 0
    assert fake.calls == [("heartbeat_run", "run_123", {"message": "still working"})]


def test_devboard_runs_finish_sends_status_and_summary(monkeypatch):
    fake = FakeRunClient()
    monkeypatch.setattr(cli, "client_from_options", lambda server_url, token: fake)

    result = CliRunner().invoke(
        app,
        [
            "runs",
            "finish",
            "run_123",
            "--status",
            "failed",
            "--summary",
            "Genesis failed",
            "--risk-level",
            "high",
            "--server-url",
            "https://devboard.test",
            "--token",
            "devb_live_token|secret",
        ],
    )

    assert result.exit_code == 0
    assert fake.calls == [
        (
            "finish_run",
            "run_123",
            {"status": "failed", "summary": "Genesis failed", "risk_report": {"risk_level": "high"}},
        )
    ]
