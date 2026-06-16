from __future__ import annotations

from pathlib import Path
from typing import Any, Callable

from devboard_plugin.artifacts import upload_genesis_bundle
from devboard_plugin.client import DevBoardClient
from devboard_plugin.config import Credentials, credentials_from_options
from devboard_plugin.git_local import (
    current_branch as git_current_branch,
    dirty_status as git_dirty_status,
    head_sha as git_head_sha,
)
from devboard_plugin.state import read_repo_state, write_repo_state


def client_from_credentials(credentials: Credentials) -> DevBoardClient:
    return DevBoardClient(
        base_url=credentials.server_url,
        token=credentials.token,
        device_id=credentials.device_id,
    )


def client_from_options(server_url: str | None = None) -> DevBoardClient:
    return client_from_credentials(credentials_from_options(server_url, None))


def devboard_auth_check(server_url: str | None = None) -> dict[str, Any]:
    """Check the configured DevBoard plugin credentials."""
    return client_from_options(server_url).auth_check()


def devboard_get_context(repository_id: str, server_url: str | None = None) -> dict[str, Any]:
    """Fetch repository instructions/context for a linked DevBoard repository."""
    return client_from_options(server_url).repository_instructions(repository_id)


def devboard_start_run(
    project_id: str,
    repository_id: str,
    local_workspace_id: str,
    base_sha: str,
    run_type: str = "genesis_import",
    runtime_profile: str = "agent_plugin",
    branch: str = "main",
    base_branch: str = "main",
    head_sha: str | None = None,
    dirty_status: str = "clean",
    task_id: str | None = None,
    server_url: str | None = None,
) -> dict[str, Any]:
    """Start a DevBoard run from configured local credentials."""
    return client_from_options(server_url).start_run(
        {
            "project_id": project_id,
            "repository_id": repository_id,
            "local_workspace_id": local_workspace_id,
            "task_id": task_id,
            "run_type": run_type,
            "runtime_profile": runtime_profile,
            "branch": branch,
            "base_branch": base_branch,
            "base_sha": base_sha,
            "head_sha": head_sha,
            "dirty_status": dirty_status,
        }
    )


def devboard_heartbeat_run(
    run_id: str,
    message: str | None = None,
    server_url: str | None = None,
) -> dict[str, Any]:
    """Send a heartbeat for an active DevBoard run."""
    payload: dict[str, Any] = {}
    if message is not None:
        payload["message"] = message

    return client_from_options(server_url).heartbeat_run(run_id, payload)


def devboard_finish_run(
    run_id: str,
    status: str = "finished",
    summary: str | None = None,
    risk_level: str | None = None,
    server_url: str | None = None,
) -> dict[str, Any]:
    """Finish a DevBoard run."""
    payload: dict[str, Any] = {"status": status, "summary": summary}
    if risk_level is not None:
        payload["risk_report"] = {"risk_level": risk_level}

    return client_from_options(server_url).finish_run(run_id, payload)


def devboard_genesis_import(
    project_id: str,
    repository_id: str,
    local_workspace_id: str,
    repo_path: str = ".",
    output_path: str | None = None,
    run_id: str | None = None,
    server_url: str | None = None,
) -> dict[str, Any]:
    """Build a local Genesis bundle and record its state for upload."""
    repo = Path(repo_path)
    client = client_from_options(server_url)

    if run_id is None:
        start = client.start_run(
            {
                "project_id": project_id,
                "repository_id": repository_id,
                "local_workspace_id": local_workspace_id,
                "task_id": None,
                "run_type": "genesis_import",
                "runtime_profile": "agent_plugin",
                "branch": git_current_branch(repo),
                "base_branch": git_current_branch(repo),
                "base_sha": git_head_sha(repo) or "unknown",
                "head_sha": git_head_sha(repo),
                "dirty_status": git_dirty_status(repo),
            }
        )
        run_id = start["run_id"]

    policy = client.repository_policy(repository_id)
    output_dir = Path(output_path) if output_path else repo / ".devboard" / "artifacts" / "genesis" / run_id
    bundle = build_genesis_bundle(
        repo,
        output_dir,
        {
            "project_id": project_id,
            "repository_id": repository_id,
            "local_workspace_id": local_workspace_id,
            "run_id": run_id,
            "code_exposure": policy.get("code_exposure", "full_code_artifacts"),
        },
    )
    write_repo_state(
        repo,
        {
            "project_id": project_id,
            "repository_id": repository_id,
            "local_workspace_id": local_workspace_id,
            "run_id": run_id,
            "genesis_bundle_path": str(output_dir),
        },
    )

    return {"run_id": run_id, "status": "bundle_built", "bundle": bundle}


def devboard_delta_sync(repository_id: str, server_url: str | None = None) -> dict[str, Any]:
    """Report Delta Sync availability for the current implementation slice."""
    return {
        "status": "not_implemented",
        "message": "Delta Sync is specified for V1 but not implemented in the current slice.",
    }


def devboard_upload_artifact(
    repo_path: str = ".",
    repository_id: str | None = None,
    run_id: str | None = None,
    local_workspace_id: str | None = None,
    bundle_path: str | None = None,
    server_url: str | None = None,
) -> dict[str, Any]:
    """Upload a Genesis artifact bundle using manifest/chunk/finalize."""
    repo = Path(repo_path)
    state = read_repo_state(repo)
    resolved_repository_id = repository_id or state.get("repository_id")
    resolved_run_id = run_id or state.get("run_id")
    resolved_workspace_id = local_workspace_id or state.get("local_workspace_id")
    resolved_bundle_path = bundle_path or state.get("genesis_bundle_path")

    if not resolved_repository_id or not resolved_run_id or not resolved_workspace_id or not resolved_bundle_path:
        return {
            "status": "error",
            "message": "repository_id, run_id, local_workspace_id, and bundle_path are required.",
        }

    return upload_genesis_bundle(
        client_from_options(server_url),
        repository_id=resolved_repository_id,
        run_id=resolved_run_id,
        local_workspace_id=resolved_workspace_id,
        bundle_path=resolved_bundle_path,
    )


def devboard_write_wiki_revision(
    run_id: str,
    project_id: str,
    repository_id: str,
    slug: str,
    title: str,
    content_markdown: str,
    page_type: str = "technical",
    producer: str = "plugin",
    source_type: str = "local_analyzer",
    source_status: str = "needs_verification",
    evidence_refs: list[dict[str, Any]] | None = None,
    server_url: str | None = None,
) -> dict[str, Any]:
    """Write a wiki revision with explicit source status and evidence."""
    return client_from_options(server_url).post(
        f"/api/plugin/v1/runs/{run_id}/wiki/revisions",
        {
            "project_id": project_id,
            "repository_id": repository_id,
            "slug": slug,
            "title": title,
            "page_type": page_type,
            "producer": producer,
            "source_type": source_type,
            "source_status": source_status,
            "content_markdown": content_markdown,
            "evidence_refs": evidence_refs or [],
        },
    )


def build_genesis_bundle(repo_path: Path, output_dir: Path, context: dict[str, Any]) -> dict[str, Any]:
    from devboard_analyzer.genesis_bundle import build_genesis_bundle as build

    return build(repo_path, output_dir, context)


TOOL_REGISTRY: dict[str, Callable[..., dict[str, Any]]] = {
    "devboard_auth_check": devboard_auth_check,
    "devboard_get_context": devboard_get_context,
    "devboard_start_run": devboard_start_run,
    "devboard_heartbeat_run": devboard_heartbeat_run,
    "devboard_finish_run": devboard_finish_run,
    "devboard_genesis_import": devboard_genesis_import,
    "devboard_delta_sync": devboard_delta_sync,
    "devboard_upload_artifact": devboard_upload_artifact,
    "devboard_write_wiki_revision": devboard_write_wiki_revision,
}
