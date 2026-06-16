from __future__ import annotations

import json
from pathlib import Path

import typer

from devboard_plugin.client import DevBoardApiError, DevBoardClient
from devboard_plugin.config import credentials_from_options, load_credentials, save_credentials, Credentials
from devboard_plugin.git_local import current_branch, dirty_status, ensure_devboard_excluded, head_sha, local_root_hash
from devboard_plugin.state import write_repo_link_state

app = typer.Typer(help="DevBoard local plugin")
auth_app = typer.Typer(help="Authenticate this local plugin")
projects_app = typer.Typer(help="Read DevBoard projects")
repos_app = typer.Typer(help="Link and inspect repositories")
context_app = typer.Typer(help="Pull repository context")

app.add_typer(auth_app, name="auth")
app.add_typer(projects_app, name="projects")
app.add_typer(repos_app, name="repos")
app.add_typer(context_app, name="context")


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
    raise typer.Exit(code=1) from error
