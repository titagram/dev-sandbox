from __future__ import annotations

import json
from pathlib import Path
import re
from typing import Any

from devboard_plugin.secure_io import atomic_write_text, reject_symlink_components

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
    return resolve_repo_path(repo_path) / STATE_DIR / STATE_FILE


def resolve_repo_path(repo_path: Path | str = ".") -> Path:
    supplied = Path(repo_path).expanduser()
    if ".." in supplied.parts:
        raise ValueError("Repository path traversal is not allowed.")
    candidate = supplied.absolute()
    reject_symlink_components(candidate)
    try:
        resolved = candidate.resolve(strict=True)
    except OSError as error:
        raise ValueError(f"Repository path does not exist: {candidate}") from error
    if not resolved.is_dir():
        raise ValueError(f"Repository path is not a directory: {resolved}")
    return resolved


def validate_path_component(value: str, name: str) -> str:
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]*", value) or value in {".", ".."}:
        raise ValueError(f"{name} must be a single safe path component.")
    return value


def resolve_repo_relative_path(repo_path: Path | str, value: Path | str) -> Path:
    repo = resolve_repo_path(repo_path)
    supplied = Path(value).expanduser()
    if not supplied.is_absolute():
        if ".." in supplied.parts:
            raise ValueError("Path traversal is not allowed.")
        supplied = repo / supplied
    candidate = supplied.absolute()
    try:
        candidate.relative_to(repo)
    except ValueError:
        raise ValueError(f"Path must remain inside repository {repo}.") from None
    reject_symlink_components(candidate, start=repo)
    resolved = candidate.resolve(strict=False)
    try:
        resolved.relative_to(repo)
    except ValueError:
        raise ValueError(f"Path must remain inside repository {repo}.") from None
    return resolved


def write_repo_state(repo_path: Path | str, data: dict[str, Any]) -> Path:
    path = state_path(repo_path)
    reject_symlink_components(path, start=resolve_repo_path(repo_path))
    atomic_write_text(path, json.dumps(public_state(data), indent=2, sort_keys=True) + "\n")

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
    reject_symlink_components(path, start=resolve_repo_path(repo_path))
    if not path.exists():
        return {}

    return json.loads(path.read_text())


def load_base_snapshot_data(
    repo_path: Path | str,
    state: dict[str, Any],
    base_snapshot_id: str,
) -> tuple[dict[str, str], list[dict[str, Any]], bool, list[dict[str, Any]], bool]:
    if state.get("base_artifact_snapshot_id") != base_snapshot_id:
        return {}, [], False, [], False
    bundle_value = state.get("base_artifact_bundle_path")
    if not isinstance(bundle_value, str) or not bundle_value:
        return {}, [], False, [], False

    bundle = resolve_repo_relative_path(repo_path, bundle_value)
    hashes_document = _read_bundle_json(bundle, "file-hashes.json")
    symbols_document = _read_bundle_json(bundle, "symbol-index.json")
    relations_document = _read_bundle_json(bundle, "relation-index.json")
    hashes = {
        row["path"]: row["sha256"]
        for row in hashes_document.get("hashes", [])
        if isinstance(row, dict) and isinstance(row.get("path"), str) and isinstance(row.get("sha256"), str)
    }
    symbols_complete = symbols_document.get("baseline_complete") is True
    symbols = [row for row in symbols_document.get("symbols", []) if isinstance(row, dict)] if symbols_complete else []
    relations_complete = relations_document.get("baseline_complete") is True
    relations = (
        [row for row in relations_document.get("relations", []) if isinstance(row, dict)]
        if relations_complete
        else []
    )
    return hashes, symbols, symbols_complete, relations, relations_complete


def base_snapshot_state(bundle_path: Path, snapshot_id: str) -> dict[str, Any]:
    try:
        symbols_document = _read_bundle_json(bundle_path, "symbol-index.json")
        relations_document = _read_bundle_json(bundle_path, "relation-index.json")
    except ValueError:
        symbols_document = {}
        relations_document = {}
    return {
        "base_snapshot_id": snapshot_id,
        "base_artifact_snapshot_id": snapshot_id,
        "base_artifact_bundle_path": str(bundle_path),
        "base_symbols_complete": symbols_document.get("baseline_complete") is True,
        "base_relations_complete": relations_document.get("baseline_complete") is True,
    }


def _read_bundle_json(bundle: Path, filename: str) -> dict[str, Any]:
    path = resolve_repo_relative_path(bundle, filename)
    reject_symlink_components(path, start=bundle)
    try:
        document = json.loads(path.read_text())
    except (OSError, ValueError):
        return {}
    return document if isinstance(document, dict) else {}


def public_state(data: dict[str, Any]) -> dict[str, Any]:
    return {
        key: _public_value(value)
        for key, value in data.items()
        if key.lower() not in SECRET_KEYS
    }


def _public_value(value: Any) -> Any:
    if isinstance(value, dict):
        return public_state(value)
    if isinstance(value, list):
        return [_public_value(item) for item in value]
    return value
