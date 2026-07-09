from __future__ import annotations

import inspect
from pathlib import Path

from devboard_plugin import mcp_tools
from devboard_plugin.state import write_repo_state


def test_mcp_tool_names_match_v1_contract():
    assert set(mcp_tools.TOOL_REGISTRY) == {
        "devboard_auth_check",
        "devboard_get_context",
        "devboard_shared_memory_pack",
        "devboard_list_work_items",
        "devboard_claim_work_item",
        "devboard_heartbeat_work_item",
        "devboard_complete_work_item",
        "devboard_fail_work_item",
        "devboard_start_run",
        "devboard_heartbeat_run",
        "devboard_finish_run",
        "devboard_genesis_import",
        "devboard_delta_sync",
        "devboard_upload_artifact",
        "devboard_write_wiki_revision",
        "devboard_query_graph",
    }


def test_mcp_tools_do_not_accept_raw_token_parameters():
    for tool in mcp_tools.TOOL_REGISTRY.values():
        assert "token" not in inspect.signature(tool).parameters


def test_auth_check_uses_configured_credentials(monkeypatch):
    fake_client = FakeClient()
    monkeypatch.setattr(mcp_tools, "client_from_credentials", lambda credentials: fake_client)
    monkeypatch.setattr(
        mcp_tools,
        "credentials_from_options",
        lambda server_url, token: mcp_tools.Credentials(
            server_url=server_url or "https://devboard.test",
            token="devb_live_token|secret",
            device_id="device_123",
        ),
    )

    response = mcp_tools.devboard_auth_check()

    assert response == {"authenticated": True}
    assert fake_client.calls == [("auth_check", None)]


def test_start_run_forwards_public_payload_fields(monkeypatch):
    fake_client = FakeClient()
    monkeypatch.setattr(mcp_tools, "client_from_options", lambda server_url=None: fake_client)

    response = mcp_tools.devboard_start_run(
        project_id="proj_123",
        repository_id="repo_123",
        local_workspace_id="lw_123",
        base_sha="base123",
        branch="feature/devboard",
        base_branch="main",
        head_sha="head456",
        dirty_status="dirty",
        task_id="task_123",
    )

    assert response == {"run_id": "run_123", "status": "started"}
    assert fake_client.calls == [
        (
            "start_run",
            {
                "project_id": "proj_123",
                "repository_id": "repo_123",
                "local_workspace_id": "lw_123",
                "task_id": "task_123",
                "run_type": "genesis_import",
                "runtime_profile": "agent_plugin",
                "branch": "feature/devboard",
                "base_branch": "main",
                "base_sha": "base123",
                "head_sha": "head456",
                "dirty_status": "dirty",
            },
        )
    ]


def test_genesis_import_builds_bundle_without_returning_credentials(monkeypatch, tmp_path):
    fake_client = FakeClient()
    monkeypatch.setattr(mcp_tools, "client_from_options", lambda server_url=None: fake_client)
    monkeypatch.setattr(mcp_tools, "git_current_branch", lambda repo_path: "main")
    monkeypatch.setattr(mcp_tools, "git_head_sha", lambda repo_path: "abc123")
    monkeypatch.setattr(mcp_tools, "git_dirty_status", lambda repo_path: "clean")
    monkeypatch.setattr(mcp_tools, "build_genesis_bundle", fake_build_genesis_bundle)

    response = mcp_tools.devboard_genesis_import(
        project_id="proj_123",
        repository_id="repo_123",
        local_workspace_id="lw_123",
        repo_path=str(tmp_path),
    )

    assert response["status"] == "bundle_built"
    assert response["run_id"] == "run_123"
    assert "token" not in str(response).lower()
    assert (tmp_path / ".devboard" / "state.json").exists()
    assert fake_client.calls[0][0] == "start_run"


def test_delta_sync_builds_and_uploads_bundle(monkeypatch, tmp_path):
    fake_client = FakeClient()
    uploads = []
    monkeypatch.setattr(mcp_tools, "client_from_options", lambda server_url=None: fake_client)
    monkeypatch.setattr(mcp_tools, "git_current_branch", lambda repo_path: "feature/devboard")
    monkeypatch.setattr(mcp_tools, "git_head_sha", lambda repo_path: "head456")
    monkeypatch.setattr(mcp_tools, "git_dirty_status", lambda repo_path: "dirty")
    monkeypatch.setattr(mcp_tools, "build_delta_bundle", fake_build_delta_bundle)
    monkeypatch.setattr(
        mcp_tools,
        "upload_delta_bundle",
        lambda client, **kwargs: uploads.append(kwargs) or {"status": "active", "snapshot_id": "snap_new"},
    )

    response = mcp_tools.devboard_delta_sync(
        project_id="proj_123",
        repository_id="repo_123",
        local_workspace_id="lw_123",
        base_snapshot_id="snap_base",
        repo_path=str(tmp_path),
    )

    assert response["status"] == "active"
    assert response["run_id"] == "run_123"
    assert uploads[0]["base_snapshot_id"] == "snap_base"
    assert (tmp_path / ".devboard" / "state.json").exists()


def test_delta_sync_forwards_explicit_security_approval(monkeypatch, tmp_path):
    fake_client = FakeClient()
    uploads = []
    monkeypatch.setattr(mcp_tools, "client_from_options", lambda server_url=None: fake_client)
    monkeypatch.setattr(mcp_tools, "git_current_branch", lambda repo_path: "feature/devboard")
    monkeypatch.setattr(mcp_tools, "git_head_sha", lambda repo_path: "head456")
    monkeypatch.setattr(mcp_tools, "git_dirty_status", lambda repo_path: "dirty")
    monkeypatch.setattr(mcp_tools, "build_delta_bundle", fake_build_delta_bundle)
    monkeypatch.setattr(
        mcp_tools,
        "upload_delta_bundle",
        lambda client, **kwargs: uploads.append(kwargs) or {"status": "active", "snapshot_id": "snap_new"},
    )

    response = mcp_tools.devboard_delta_sync(
        project_id="proj_123",
        repository_id="repo_123",
        local_workspace_id="lw_123",
        base_snapshot_id="snap_base",
        repo_path=str(tmp_path),
        allow_blocked_security_findings=True,
    )

    assert response["status"] == "active"
    assert uploads[0]["allow_blocked_security_findings"] is True


def test_query_graph_calls_graph_query_endpoint(monkeypatch):
    fake_client = FakeClient()
    fake_client.query_graph_response = {
        "protocol_version": "v1",
        "project_id": "proj_123",
        "query_type": "callers",
        "symbol_id": "App\\Services\\InvoiceService",
        "results": [{"id": "node_1", "labels": ["Function"], "name": "processPayment"}],
    }
    monkeypatch.setattr(mcp_tools, "client_from_options", lambda server_url=None: fake_client)

    response = mcp_tools.devboard_query_graph(
        project_id="proj_123",
        query_type="callers",
        symbol_id="App\\Services\\InvoiceService",
        limit=10,
    )

    assert response["query_type"] == "callers"
    assert response["results"][0]["id"] == "node_1"
    assert fake_client.calls[0][0] == "query_graph"
    assert fake_client.calls[0][1]["type"] == "callers"
    assert fake_client.calls[0][1]["symbol_id"] == "App\\Services\\InvoiceService"
    assert fake_client.calls[0][1]["limit"] == 10
    assert fake_client.calls[0][1]["project_id"] == "proj_123"


def test_upload_artifact_forwards_explicit_security_approval(monkeypatch, tmp_path):
    fake_client = FakeClient()
    uploads = []
    write_repo_state(
        tmp_path,
        {
            "repository_id": "repo_123",
            "run_id": "run_123",
            "local_workspace_id": "lw_123",
            "genesis_bundle_path": "bundle",
        },
    )
    monkeypatch.setattr(mcp_tools, "client_from_options", lambda server_url=None: fake_client)
    monkeypatch.setattr(
        mcp_tools,
        "upload_genesis_bundle",
        lambda client, **kwargs: uploads.append(kwargs) or {"status": "active", "snapshot_id": "snap_new"},
    )

    response = mcp_tools.devboard_upload_artifact(
        repo_path=str(tmp_path),
        allow_blocked_security_findings=True,
    )

    assert response["status"] == "active"
    assert uploads[0]["allow_blocked_security_findings"] is True
    assert uploads[0]["bundle_path"] == tmp_path / "bundle"


class FakeClient:
    def __init__(self):
        self.calls = []
        self.query_graph_response = {"protocol_version": "v1", "project_id": "proj_123", "query_type": "callers", "results": []}

    def auth_check(self):
        self.calls.append(("auth_check", None))
        return {"authenticated": True}

    def repository_instructions(self, repository_id):
        self.calls.append(("repository_instructions", repository_id))
        return {"repository_id": repository_id}

    def repository_policy(self, repository_id):
        self.calls.append(("repository_policy", repository_id))
        return {"code_exposure": "full_code_artifacts"}

    def query_graph(self, project_id, **kwargs):
        self.calls.append(("query_graph", {**kwargs, "project_id": project_id}))
        return self.query_graph_response

    def start_run(self, payload):
        self.calls.append(("start_run", payload))
        return {"run_id": "run_123", "status": "started"}

    def heartbeat_run(self, run_id, payload):
        self.calls.append(("heartbeat_run", {"run_id": run_id, "payload": payload}))
        return {"status": "heartbeat_received"}

    def finish_run(self, run_id, payload):
        self.calls.append(("finish_run", {"run_id": run_id, "payload": payload}))
        return {"status": payload["status"]}

    def post(self, path, payload):
        self.calls.append(("post", {"path": path, "payload": payload}))
        return {"wiki_revision_id": "rev_123"}


def fake_build_genesis_bundle(repo_path: Path, output_dir: Path, context: dict):
    return {
        "output_dir": str(output_dir),
        "manifest_path": str(output_dir / "genesis-manifest.json"),
        "artifacts": [],
        "context": context,
    }


def fake_build_delta_bundle(repo_path: Path, output_dir: Path, context: dict):
    return {
        "output_dir": str(output_dir),
        "manifest_path": str(output_dir / "delta-manifest.json"),
        "artifacts": [],
        "context": context,
    }
