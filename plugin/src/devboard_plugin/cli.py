from __future__ import annotations

import json
import sys
from pathlib import Path

import typer

from devboard_plugin.artifacts import upload_delta_bundle, upload_genesis_bundle
from devboard_plugin.client import DevBoardApiError, DevBoardClient
from devboard_plugin.config import credentials_from_options, load_credentials, save_credentials, Credentials
from devboard_plugin.git_local import current_branch, dirty_status, ensure_devboard_excluded, head_sha, local_root_hash
from devboard_plugin.state import read_repo_state, write_repo_link_state, write_repo_state

app = typer.Typer(help="DevBoard local plugin")
auth_app = typer.Typer(help="Authenticate this local plugin")
projects_app = typer.Typer(help="Read DevBoard projects")
repos_app = typer.Typer(help="Link and inspect repositories")
context_app = typer.Typer(help="Pull repository context")
runs_app = typer.Typer(help="Manage plugin run lifecycle")
genesis_app = typer.Typer(help="Build and upload Genesis artifacts")
delta_app = typer.Typer(help="Build and upload Delta Sync artifacts")
artifacts_app = typer.Typer(help="Upload generated artifacts")
work_app = typer.Typer(help="Manage agent work items")

app.add_typer(auth_app, name="auth")
app.add_typer(projects_app, name="projects")
app.add_typer(repos_app, name="repos")
app.add_typer(context_app, name="context")
app.add_typer(runs_app, name="runs")
app.add_typer(genesis_app, name="genesis")
app.add_typer(delta_app, name="delta")
app.add_typer(artifacts_app, name="artifacts")
app.add_typer(work_app, name="work")


@app.callback()
def main() -> None:
    """DevBoard local plugin command group."""


@app.command()
def version() -> None:
    typer.echo("devboard-plugin 0.1.0")


@auth_app.command("check")
def auth_check(
    server_url: str | None = typer.Option(None, "--server-url"),
    token: str | None = typer.Option(None, "--token", hide_input=True),
) -> None:
    client = client_from_options(server_url, token)
    echo_json(client.auth_check())


@auth_app.command("register-device")
def auth_register_device(
    name: str,
    fingerprint_hash: str,
    platform_os: str,
    platform_arch: str,
    server_url: str | None = typer.Option(None, "--server-url"),
    token: str | None = typer.Option(None, "--token", hide_input=True),
) -> None:
    credentials = credentials_from_options(server_url, token)
    client = client_from_credentials(credentials)
    response = client.register_device(
        {
            "name": name,
            "fingerprint_hash": fingerprint_hash,
            "platform_os": platform_os,
            "platform_arch": platform_arch,
            "plugin_version": "0.1.0",
        }
    )
    save_credentials(
        Credentials(
            server_url=credentials.server_url,
            token=credentials.token,
            device_id=response["device_id"],
        )
    )
    echo_json(response)


@projects_app.command("list")
def projects_list(
    server_url: str | None = typer.Option(None, "--server-url"),
    token: str | None = typer.Option(None, "--token", hide_input=True),
) -> None:
    echo_json(client_from_options(server_url, token).list_projects())


@repos_app.command("link")
def repos_link(
    project_id: str,
    repository_id: str,
    repo_path: Path = typer.Option(Path("."), "--repo-path"),
    server_url: str | None = typer.Option(None, "--server-url"),
    token: str | None = typer.Option(None, "--token", hide_input=True),
) -> None:
    client = client_from_options(server_url, token)
    response = client.register_local_workspace(
        repository_id,
        {
            "local_root_hash": local_root_hash(repo_path),
            "display_path": str(repo_path.resolve()),
            "current_branch": current_branch(repo_path),
            "last_head_sha": head_sha(repo_path),
            "dirty_status": dirty_status(repo_path),
        },
    )
    ensure_devboard_excluded(repo_path)
    write_repo_link_state(
        repo_path,
        project_id=project_id,
        repository_id=repository_id,
        local_workspace_id=response["local_workspace_id"],
    )
    echo_json(response)


@repos_app.command("policy")
def repos_policy(
    repository_id: str,
    server_url: str | None = typer.Option(None, "--server-url"),
    token: str | None = typer.Option(None, "--token", hide_input=True),
) -> None:
    echo_json(client_from_options(server_url, token).repository_policy(repository_id))


@context_app.command("pull")
def context_pull(
    repository_id: str,
    server_url: str | None = typer.Option(None, "--server-url"),
    token: str | None = typer.Option(None, "--token", hide_input=True),
) -> None:
    echo_json(client_from_options(server_url, token).repository_instructions(repository_id))


@runs_app.command("start")
def runs_start(
    project_id: str = typer.Option(..., "--project-id"),
    repository_id: str = typer.Option(..., "--repository-id"),
    local_workspace_id: str = typer.Option(..., "--local-workspace-id"),
    task_id: str | None = typer.Option(None, "--task-id"),
    run_type: str = typer.Option("genesis_import", "--run-type"),
    runtime_profile: str = typer.Option("agent_plugin", "--runtime-profile"),
    branch: str = typer.Option("main", "--branch"),
    base_branch: str = typer.Option("main", "--base-branch"),
    base_sha: str = typer.Option(..., "--base-sha"),
    head_sha: str | None = typer.Option(None, "--head-sha"),
    dirty_status: str = typer.Option("clean", "--dirty-status"),
    server_url: str | None = typer.Option(None, "--server-url"),
    token: str | None = typer.Option(None, "--token", hide_input=True),
) -> None:
    payload = {
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
    echo_json(client_from_options(server_url, token).start_run(payload))


@runs_app.command("heartbeat")
def runs_heartbeat(
    run_id: str,
    message: str | None = typer.Option(None, "--message"),
    server_url: str | None = typer.Option(None, "--server-url"),
    token: str | None = typer.Option(None, "--token", hide_input=True),
) -> None:
    payload = {}
    if message is not None:
        payload["message"] = message

    echo_json(client_from_options(server_url, token).heartbeat_run(run_id, payload))


@runs_app.command("finish")
def runs_finish(
    run_id: str,
    status: str = typer.Option("finished", "--status"),
    summary: str | None = typer.Option(None, "--summary"),
    risk_level: str | None = typer.Option(None, "--risk-level"),
    server_url: str | None = typer.Option(None, "--server-url"),
    token: str | None = typer.Option(None, "--token", hide_input=True),
) -> None:
    payload = {
        "status": status,
        "summary": summary,
    }
    if risk_level is not None:
        payload["risk_report"] = {"risk_level": risk_level}

    echo_json(client_from_options(server_url, token).finish_run(run_id, payload))


@work_app.command("list")
def work_list(
    project_id: str | None = typer.Option(None, "--project-id"),
    repository_id: str | None = typer.Option(None, "--repository-id"),
    server_url: str | None = typer.Option(None, "--server-url"),
    token: str | None = typer.Option(None, "--token", hide_input=True),
) -> None:
    echo_json(client_from_options(server_url, token).list_work_items(project_id, repository_id))


@work_app.command("memory-pack")
def work_memory_pack(
    project_id: str,
    repository_id: str | None = typer.Option(None, "--repository-id"),
    server_url: str | None = typer.Option(None, "--server-url"),
    token: str | None = typer.Option(None, "--token", hide_input=True),
) -> None:
    echo_json(client_from_options(server_url, token).shared_memory_pack(project_id, repository_id))


@work_app.command("claim")
def work_claim(
    work_item_id: str,
    local_workspace_id: str,
    server_url: str | None = typer.Option(None, "--server-url"),
    token: str | None = typer.Option(None, "--token", hide_input=True),
) -> None:
    echo_json(client_from_options(server_url, token).claim_work_item(work_item_id, local_workspace_id))


@work_app.command("heartbeat")
def work_heartbeat(
    work_item_id: str,
    lease_token: str,
    server_url: str | None = typer.Option(None, "--server-url"),
    token: str | None = typer.Option(None, "--token", hide_input=True),
) -> None:
    echo_json(client_from_options(server_url, token).heartbeat_work_item(work_item_id, lease_token))


@work_app.command("complete")
def work_complete(
    work_item_id: str,
    lease_token: str,
    summary: str = typer.Option(..., "--summary"),
    kind: str = typer.Option("implementation", "--kind"),
    server_url: str | None = typer.Option(None, "--server-url"),
    token: str | None = typer.Option(None, "--token", hide_input=True),
) -> None:
    memory_entry = {
        "kind": kind,
        "summary": summary,
        "payload": {
            "why": summary,
            "changed": [],
            "tests": [],
            "skipped_checks": [],
            "risks": [],
        },
    }
    echo_json(client_from_options(server_url, token).complete_work_item(work_item_id, lease_token, memory_entry))


@work_app.command("fail")
def work_fail(
    work_item_id: str,
    lease_token: str,
    failure_reason: str = typer.Option(..., "--failure-reason"),
    server_url: str | None = typer.Option(None, "--server-url"),
    token: str | None = typer.Option(None, "--token", hide_input=True),
) -> None:
    echo_json(client_from_options(server_url, token).fail_work_item(work_item_id, lease_token, failure_reason))


@genesis_app.command("run")
def genesis_run(
    project_id: str = typer.Option(..., "--project-id"),
    repository_id: str = typer.Option(..., "--repository-id"),
    local_workspace_id: str = typer.Option(..., "--local-workspace-id"),
    repo_path: Path = typer.Option(Path("."), "--repo-path"),
    run_id: str | None = typer.Option(None, "--run-id"),
    output: Path | None = typer.Option(None, "--output"),
    server_url: str | None = typer.Option(None, "--server-url"),
    token: str | None = typer.Option(None, "--token", hide_input=True),
) -> None:
    client = client_from_options(server_url, token)

    if run_id is None:
        start_response = client.start_run(
            {
                "project_id": project_id,
                "repository_id": repository_id,
                "local_workspace_id": local_workspace_id,
                "task_id": None,
                "run_type": "genesis_import",
                "runtime_profile": "agent_plugin",
                "branch": current_branch(repo_path),
                "base_branch": current_branch(repo_path),
                "base_sha": head_sha(repo_path) or "unknown",
                "head_sha": head_sha(repo_path),
                "dirty_status": dirty_status(repo_path),
            }
        )
        run_id = start_response["run_id"]

    policy = client.repository_policy(repository_id)
    output_dir = output or repo_path / ".devboard" / "artifacts" / "genesis" / run_id
    bundle = build_genesis_bundle(
        repo_path,
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
        repo_path,
        {
            "project_id": project_id,
            "repository_id": repository_id,
            "local_workspace_id": local_workspace_id,
            "run_id": run_id,
            "genesis_bundle_path": str(output_dir),
        },
    )
    echo_json({"run_id": run_id, "status": "bundle_built", "bundle": bundle})


@artifacts_app.command("upload")
def artifacts_upload(
    genesis: bool = typer.Option(False, "--genesis"),
    delta: bool = typer.Option(False, "--delta"),
    repository_id: str | None = typer.Option(None, "--repository-id"),
    run_id: str | None = typer.Option(None, "--run-id"),
    local_workspace_id: str | None = typer.Option(None, "--local-workspace-id"),
    base_snapshot_id: str | None = typer.Option(None, "--base-snapshot-id"),
    bundle_path: Path | None = typer.Option(None, "--bundle-path"),
    repo_path: Path = typer.Option(Path("."), "--repo-path"),
    allow_blocked_security_findings: bool = typer.Option(False, "--allow-blocked-security-findings"),
    server_url: str | None = typer.Option(None, "--server-url"),
    token: str | None = typer.Option(None, "--token", hide_input=True),
) -> None:
    if genesis == delta:
        raise typer.BadParameter("Choose exactly one upload type: --genesis or --delta.")

    state = read_repo_state(repo_path)
    run_id = run_id or state.get("run_id")
    local_workspace_id = local_workspace_id or state.get("local_workspace_id")

    if genesis:
        repository_id = repository_id or state.get("repository_id")
        bundle_path = bundle_path or resolve_state_path(repo_path, state["genesis_bundle_path"])

        if not repository_id or not run_id or not local_workspace_id:
            raise typer.BadParameter("repository_id, run_id, and local_workspace_id are required.")

        response = upload_genesis_bundle(
            client_from_options(server_url, token),
            repository_id=repository_id,
            run_id=run_id,
            local_workspace_id=local_workspace_id,
            bundle_path=bundle_path,
            allow_blocked_security_findings=allow_blocked_security_findings,
        )
    else:
        base_snapshot_id = base_snapshot_id or state.get("base_snapshot_id") or state.get("snapshot_id")
        bundle_path = bundle_path or resolve_state_path(repo_path, state["delta_bundle_path"])

        if not run_id or not local_workspace_id or not base_snapshot_id:
            raise typer.BadParameter("run_id, local_workspace_id, and base_snapshot_id are required.")

        response = upload_delta_bundle(
            client_from_options(server_url, token),
            run_id=run_id,
            local_workspace_id=local_workspace_id,
            base_snapshot_id=base_snapshot_id,
            bundle_path=bundle_path,
            allow_blocked_security_findings=allow_blocked_security_findings,
        )

    echo_json(response)


@delta_app.command("run")
def delta_run(
    project_id: str = typer.Option(..., "--project-id"),
    repository_id: str = typer.Option(..., "--repository-id"),
    local_workspace_id: str = typer.Option(..., "--local-workspace-id"),
    base_snapshot_id: str = typer.Option(..., "--base-snapshot-id"),
    repo_path: Path = typer.Option(Path("."), "--repo-path"),
    run_id: str | None = typer.Option(None, "--run-id"),
    output: Path | None = typer.Option(None, "--output"),
    allow_blocked_security_findings: bool = typer.Option(False, "--allow-blocked-security-findings"),
    server_url: str | None = typer.Option(None, "--server-url"),
    token: str | None = typer.Option(None, "--token", hide_input=True),
) -> None:
    client = client_from_options(server_url, token)

    if run_id is None:
        start_response = client.start_run(
            {
                "project_id": project_id,
                "repository_id": repository_id,
                "local_workspace_id": local_workspace_id,
                "task_id": None,
                "run_type": "delta_sync",
                "runtime_profile": "agent_plugin",
                "branch": current_branch(repo_path),
                "base_branch": current_branch(repo_path),
                "base_sha": head_sha(repo_path) or "unknown",
                "head_sha": head_sha(repo_path),
                "dirty_status": dirty_status(repo_path),
            }
        )
        run_id = start_response["run_id"]

    state = read_repo_state(repo_path)
    policy = client.repository_policy(repository_id)
    output_dir = output or repo_path / ".devboard" / "artifacts" / "delta" / run_id
    bundle = build_delta_bundle(
        repo_path,
        output_dir,
        {
            "project_id": project_id,
            "repository_id": repository_id,
            "local_workspace_id": local_workspace_id,
            "run_id": run_id,
            "base_snapshot_id": base_snapshot_id,
            "base_file_hashes": load_base_file_hashes(repo_path, state),
            "code_exposure": policy.get("code_exposure", "full_code_artifacts"),
            "branch": current_branch(repo_path),
            "base_sha": head_sha(repo_path) or "unknown",
            "head_sha": head_sha(repo_path),
            "dirty_status": dirty_status(repo_path),
        },
    )
    upload = upload_delta_bundle(
        client,
        run_id=run_id,
        local_workspace_id=local_workspace_id,
        base_snapshot_id=base_snapshot_id,
        bundle_path=output_dir,
        allow_blocked_security_findings=allow_blocked_security_findings,
    )
    write_repo_state(
        repo_path,
        {
            "project_id": project_id,
            "repository_id": repository_id,
            "local_workspace_id": local_workspace_id,
            "run_id": run_id,
            "base_snapshot_id": base_snapshot_id,
            "snapshot_id": upload.get("snapshot_id"),
            "delta_bundle_path": str(output_dir),
        },
    )
    echo_json({"run_id": run_id, "status": upload.get("status"), "bundle": bundle, "upload": upload})


def client_from_options(server_url: str | None, token: str | None) -> DevBoardClient:
    return client_from_credentials(credentials_from_options(server_url, token))


def client_from_credentials(credentials: Credentials) -> DevBoardClient:
    return DevBoardClient(
        base_url=credentials.server_url,
        token=credentials.token,
        device_id=credentials.device_id,
    )


def echo_json(payload: dict) -> None:
    typer.echo(json.dumps(payload, indent=2, sort_keys=True))


def handle_api_error(error: DevBoardApiError) -> None:
    typer.echo(f"Error: {error}", err=True)
    raise typer.Exit(code=1)


def main_cli() -> None:
    try:
        app(standalone_mode=False)
    except DevBoardApiError as error:
        handle_api_error(error)


def build_genesis_bundle(repo_path: Path, output_dir: Path, context: dict) -> dict:
    from devboard_analyzer.genesis_bundle import build_genesis_bundle as build

    return build(repo_path, output_dir, context)


def build_delta_bundle(repo_path: Path, output_dir: Path, context: dict) -> dict:
    from devboard_analyzer.delta_bundle import build_delta_bundle as build

    return build(repo_path, output_dir, context)


def load_base_file_hashes(repo_path: Path, state: dict) -> dict[str, str]:
    for key in ("delta_bundle_path", "genesis_bundle_path"):
        bundle_path = state.get(key)
        if not bundle_path:
            continue

        hashes_path = resolve_state_path(repo_path, bundle_path) / "file-hashes.json"
        if not hashes_path.exists():
            continue

        document = json.loads(hashes_path.read_text())
        return {row["path"]: row["sha256"] for row in document.get("hashes", [])}

    return {}


def resolve_state_path(repo_path: Path, value: str) -> Path:
    path = Path(value)
    if path.is_absolute():
        return path

    return repo_path / path
