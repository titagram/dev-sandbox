import json
import stat
from pathlib import Path

import pytest

from devboard_plugin.client import DevBoardApiError
from devboard_plugin.config import (
    Credentials,
    credentials_from_options,
    credentials_path,
    load_credentials,
    normalize_server_url,
    save_credentials,
)
from devboard_plugin.state import load_base_snapshot_data, read_repo_state, resolve_repo_path, write_repo_state


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


def test_load_credentials_reports_missing_file_without_traceback():
    missing = Path("/nonexistent/credentials.json")

    with pytest.raises(DevBoardApiError) as exc_info:
        load_credentials(missing)

    assert "Credentials file not found" in str(exc_info.value)


def test_server_url_is_normalized_to_origin_and_requires_https_off_loopback():
    assert normalize_server_url("HTTPS://DevBoard.Example:443/path/") == "https://devboard.example"
    assert normalize_server_url("http://127.0.0.1:8080/api") == "http://127.0.0.1:8080"

    with pytest.raises(DevBoardApiError, match="HTTPS"):
        normalize_server_url("http://devboard.example")


def test_server_override_does_not_reuse_credentials_from_another_origin(monkeypatch, tmp_path):
    path = tmp_path / "credentials.json"
    save_credentials(
        Credentials(
            server_url="https://one.example",
            token="token-one",
            device_id="device-one",
            device_secret="secret-one",
        ),
        path,
    )
    monkeypatch.setenv("DEVBOARD_CREDENTIALS_PATH", str(path))

    with pytest.raises(DevBoardApiError) as exc_info:
        credentials_from_options("https://two.example", None)

    assert exc_info.value.code == "credentials_origin_mismatch"

    explicit = credentials_from_options("https://two.example", "token-two")
    assert explicit == Credentials(server_url="https://two.example", token="token-two")

    same_origin = credentials_from_options("https://one.example/path", "replacement-token")
    assert same_origin.device_id is None
    assert same_origin.device_secret is None

    same_token = credentials_from_options("https://one.example/path", "token-one")
    assert same_token.device_id == "device-one"
    assert same_token.device_secret == "secret-one"

    token_only_different = credentials_from_options(None, "replacement-token")
    assert token_only_different.device_id is None
    assert token_only_different.device_secret is None

    token_only_same = credentials_from_options(None, "token-one")
    assert token_only_same.device_id == "device-one"
    assert token_only_same.device_secret == "secret-one"


def test_credentials_are_written_atomically_with_private_permissions(tmp_path):
    path = save_credentials(Credentials("https://devboard.test", "token"), tmp_path / "config" / "credentials.json")

    assert stat.S_IMODE(path.stat().st_mode) == 0o600
    assert stat.S_IMODE(path.parent.stat().st_mode) & 0o077 == 0
    assert not list(path.parent.glob(".credentials.json.*.tmp"))


def test_state_write_rejects_symlink_and_sanitizes_nested_secrets(tmp_path):
    outside = tmp_path / "outside"
    outside.mkdir()
    (tmp_path / ".devboard").symlink_to(outside, target_is_directory=True)

    with pytest.raises(ValueError, match="symlink"):
        write_repo_state(tmp_path, {"nested": {"token": "secret"}})

    (tmp_path / ".devboard").unlink()
    write_repo_state(tmp_path, {"nested": {"token": "secret", "safe": "value"}})
    assert read_repo_state(tmp_path) == {"nested": {"safe": "value"}}


def test_base_hashes_are_loaded_only_for_the_bound_snapshot(tmp_path):
    bundle = tmp_path / ".devboard" / "artifacts" / "delta" / "run-1"
    bundle.mkdir(parents=True)
    (bundle / "file-hashes.json").write_text(
        json.dumps({"hashes": [{"path": "app.py", "sha256": "sha256:current"}]})
    )
    (bundle / "symbol-index.json").write_text(
        json.dumps(
            {
                "baseline_complete": True,
                "symbols": [{"symbol_id": "symbol:app.py:main", "file_path": "app.py"}],
            }
        )
    )
    (bundle / "relation-index.json").write_text(
        json.dumps(
            {
                "baseline_complete": True,
                "relations": [{"relation_id": "rel:main", "properties": {"path": "app.py"}}],
            }
        )
    )
    state = {
        "base_artifact_snapshot_id": "snap-current",
        "base_artifact_bundle_path": str(bundle),
    }

    assert load_base_snapshot_data(tmp_path, state, "snap-other") == ({}, [], False, [], False)
    hashes, symbols, complete, relations, relations_complete = load_base_snapshot_data(
        tmp_path, state, "snap-current"
    )
    assert hashes == {"app.py": "sha256:current"}
    assert symbols[0]["symbol_id"] == "symbol:app.py:main"
    assert complete is True
    assert relations[0]["relation_id"] == "rel:main"
    assert relations_complete is True

    (bundle / "symbol-index.json").write_text(json.dumps({"baseline_complete": False, "symbols": symbols}))
    _, partial_symbols, partial_complete, _, _ = load_base_snapshot_data(tmp_path, state, "snap-current")
    assert partial_symbols == []
    assert partial_complete is False


def test_repository_path_rejects_traversal_and_symlink(tmp_path):
    repo = tmp_path / "repo"
    repo.mkdir()
    link = tmp_path / "repo-link"
    link.symlink_to(repo, target_is_directory=True)

    with pytest.raises(ValueError, match="traversal"):
        resolve_repo_path(repo / "..")
    with pytest.raises(ValueError, match="symlink"):
        resolve_repo_path(link)
