import json
from pathlib import Path

from devboard_plugin.config import credentials_path
from devboard_plugin.state import write_repo_state


def test_credentials_path_resolves_under_user_config_home(monkeypatch, tmp_path):
    monkeypatch.setenv("HOME", str(tmp_path))

    assert credentials_path() == tmp_path / ".config" / "devboard" / "credentials.json"


def test_repo_state_never_serializes_credentials(tmp_path):
    state_path = write_repo_state(
        tmp_path,
        {
            "project_id": "proj_123",
            "repository_id": "repo_123",
            "local_workspace_id": "lw_123",
            "token": "devb_live_token|secret",
            "secret": "secret",
        },
    )

    state = json.loads(state_path.read_text())

    assert state == {
        "project_id": "proj_123",
        "repository_id": "repo_123",
        "local_workspace_id": "lw_123",
    }
    assert "devb_live_" not in state_path.read_text()
