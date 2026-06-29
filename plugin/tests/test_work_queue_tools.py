from __future__ import annotations

import inspect
import json

import httpx
from typer.testing import CliRunner

from devboard_plugin import cli, mcp_tools
from devboard_plugin.client import DevBoardClient
from devboard_plugin.cli import app


def test_client_shared_memory_pack_sends_query_and_inherited_headers():
    captured = {}

    def handler(request: httpx.Request) -> httpx.Response:
        captured["path"] = request.url.path
        captured["query"] = request.url.query.decode()
        captured["headers"] = request.headers
        return httpx.Response(200, json={"memories": []})

    client = DevBoardClient(
        base_url="https://devboard.test",
        token="devb_live_token|secret",
        device_id="device_123",
        transport=httpx.MockTransport(handler),
    )

    response = client.shared_memory_pack("proj_123", repository_id="repo/a b")

    assert response == {"memories": []}
    assert captured["path"] == "/api/plugin/v1/projects/proj_123/shared-memory-pack"
    assert captured["query"] == "repository_id=repo%2Fa+b"
    assert captured["headers"]["x-devboard-protocol"] == "v1"
    assert captured["headers"]["x-devboard-device-id"] == "device_123"


def test_client_list_work_items_sends_optional_filters():
    captured = {}

    def handler(request: httpx.Request) -> httpx.Response:
        captured["path"] = request.url.path
        captured["query"] = request.url.query.decode()
        return httpx.Response(200, json={"work_items": []})

    client = DevBoardClient(
        base_url="https://devboard.test",
        token="devb_live_token|secret",
        transport=httpx.MockTransport(handler),
    )

    response = client.list_work_items(project_id="proj_123", repository_id="repo/a b")

    assert response == {"work_items": []}
    assert captured == {
        "path": "/api/plugin/v1/agent-work-items",
        "query": "project_id=proj_123&repository_id=repo%2Fa+b",
    }


def test_client_work_item_post_payloads_include_protocol_and_required_fields():
    captured = []

    def handler(request: httpx.Request) -> httpx.Response:
        captured.append((request.url.path, json.loads(request.content)))
        return httpx.Response(200, json={"status": "ok"})

    client = DevBoardClient(
        base_url="https://devboard.test",
        token="devb_live_token|secret",
        transport=httpx.MockTransport(handler),
    )
    memory_entry = {
        "kind": "implementation",
        "summary": "Finished work",
        "payload": {"why": "Finished work", "changed": [], "tests": [], "skipped_checks": [], "risks": []},
    }

    client.claim_work_item("work_123", "lw_123")
    client.heartbeat_work_item("work_123", "lease_123")
    client.complete_work_item("work_123", "lease_123", memory_entry=memory_entry)
    client.fail_work_item("work_123", "lease_123", failure_reason="blocked")

    assert captured == [
        (
            "/api/plugin/v1/agent-work-items/work_123/claim",
            {"protocol_version": "v1", "local_workspace_id": "lw_123"},
        ),
        (
            "/api/plugin/v1/agent-work-items/work_123/heartbeat",
            {"protocol_version": "v1", "lease_token": "lease_123"},
        ),
        (
            "/api/plugin/v1/agent-work-items/work_123/complete",
            {"protocol_version": "v1", "lease_token": "lease_123", "memory_entry": memory_entry},
        ),
        (
            "/api/plugin/v1/agent-work-items/work_123/fail",
            {"protocol_version": "v1", "lease_token": "lease_123", "failure_reason": "blocked"},
        ),
    ]


def test_mcp_work_queue_helpers_forward_expected_arguments_without_token_parameters(monkeypatch):
    fake_client = FakeWorkClient()
    monkeypatch.setattr(mcp_tools, "client_from_options", lambda server_url=None: fake_client)

    assert "token" not in inspect.signature(mcp_tools.devboard_list_work_items).parameters
    assert "token" not in inspect.signature(mcp_tools.devboard_claim_work_item).parameters

    mcp_tools.devboard_shared_memory_pack("proj_123", repository_id="repo_123", server_url="https://devboard.test")
    mcp_tools.devboard_list_work_items(project_id="proj_123", repository_id="repo_123")
    mcp_tools.devboard_claim_work_item("work_123", "lw_123")
    mcp_tools.devboard_heartbeat_work_item("work_123", "lease_123")
    mcp_tools.devboard_complete_work_item("work_123", "lease_123", {"summary": "done"})
    mcp_tools.devboard_fail_work_item("work_123", "lease_123", "blocked")

    assert fake_client.calls == [
        ("shared_memory_pack", "proj_123", "repo_123"),
        ("list_work_items", "proj_123", "repo_123"),
        ("claim_work_item", "work_123", "lw_123"),
        ("heartbeat_work_item", "work_123", "lease_123"),
        ("complete_work_item", "work_123", "lease_123", {"summary": "done"}),
        ("fail_work_item", "work_123", "lease_123", "blocked"),
    ]


def test_cli_work_complete_builds_required_memory_payload(monkeypatch):
    fake_client = FakeWorkClient()
    monkeypatch.setattr(cli, "client_from_options", lambda server_url, token: fake_client)

    result = CliRunner().invoke(
        app,
        [
            "work",
            "complete",
            "work_123",
            "lease_123",
            "--summary",
            "Implemented queue tools",
            "--kind",
            "implementation",
            "--server-url",
            "https://devboard.test",
            "--token",
            "devb_live_token|secret",
        ],
    )

    assert result.exit_code == 0
    assert fake_client.calls == [
        (
            "complete_work_item",
            "work_123",
            "lease_123",
            {
                "kind": "implementation",
                "summary": "Implemented queue tools",
                "payload": {
                    "why": "Implemented queue tools",
                    "changed": [],
                    "tests": [],
                    "skipped_checks": [],
                    "risks": [],
                },
            },
        )
    ]


class FakeWorkClient:
    def __init__(self):
        self.calls = []

    def shared_memory_pack(self, project_id, repository_id=None):
        self.calls.append(("shared_memory_pack", project_id, repository_id))
        return {"project_id": project_id, "repository_id": repository_id}

    def list_work_items(self, project_id=None, repository_id=None):
        self.calls.append(("list_work_items", project_id, repository_id))
        return {"work_items": []}

    def claim_work_item(self, work_item_id, local_workspace_id):
        self.calls.append(("claim_work_item", work_item_id, local_workspace_id))
        return {"work_item_id": work_item_id}

    def heartbeat_work_item(self, work_item_id, lease_token):
        self.calls.append(("heartbeat_work_item", work_item_id, lease_token))
        return {"work_item_id": work_item_id}

    def complete_work_item(self, work_item_id, lease_token, memory_entry=None):
        self.calls.append(("complete_work_item", work_item_id, lease_token, memory_entry))
        return {"work_item_id": work_item_id, "status": "completed"}

    def fail_work_item(self, work_item_id, lease_token, failure_reason):
        self.calls.append(("fail_work_item", work_item_id, lease_token, failure_reason))
        return {"work_item_id": work_item_id, "status": "failed"}
