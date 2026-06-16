from __future__ import annotations

import json
from pathlib import Path
from typing import Any

STATE_DIR = ".devboard"
STATE_FILE = "state.json"
SECRET_KEYS = {
    "authorization",
    "api_key",
    "api_token",
    "credentials",
    "password",
    "plain_token",
    "secret",
    "token",
}


def state_path(repo_path: Path | str = ".") -> Path:
    return Path(repo_path) / STATE_DIR / STATE_FILE


def write_repo_state(repo_path: Path | str, data: dict[str, Any]) -> Path:
    path = state_path(repo_path)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(public_state(data), indent=2, sort_keys=True) + "\n")

    return path


def write_repo_link_state(
    repo_path: Path | str,
    *,
    project_id: str,
    repository_id: str,
    local_workspace_id: str,
) -> Path:
    return write_repo_state(
        repo_path,
        {
            "project_id": project_id,
            "repository_id": repository_id,
            "local_workspace_id": local_workspace_id,
        },
    )


def read_repo_state(repo_path: Path | str = ".") -> dict[str, Any]:
    path = state_path(repo_path)
    if not path.exists():
        return {}

    return json.loads(path.read_text())


def public_state(data: dict[str, Any]) -> dict[str, Any]:
    return {key: value for key, value in data.items() if key.lower() not in SECRET_KEYS}
