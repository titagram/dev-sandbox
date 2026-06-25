import json

import httpx

from devboard_plugin.client import DevBoardClient


def test_client_sends_protocol_v1_header():
    captured_headers = {}

    def handler(request: httpx.Request) -> httpx.Response:
        captured_headers.update(request.headers)
        return httpx.Response(200, json={"ok": True})

    client = DevBoardClient(
        base_url="https://devboard.test",
        token="devb_live_token|secret",
        transport=httpx.MockTransport(handler),
    )

    client.get("/api/plugin/v1/projects")

    assert captured_headers["x-devboard-protocol"] == "v1"
    assert captured_headers["authorization"] == "Bearer devb_live_token|secret"


def test_client_finalize_genesis_import_can_send_security_override():
    captured = {}

    def handler(request: httpx.Request) -> httpx.Response:
        captured["path"] = request.url.path
        captured["payload"] = json.loads(request.content)
        return httpx.Response(200, json={"status": "active"})

    client = DevBoardClient(
        base_url="https://devboard.test",
        token="devb_live_token|secret",
        transport=httpx.MockTransport(handler),
    )

    response = client.finalize_genesis_import("gen_123", allow_blocked_security_findings=True)

    assert response == {"status": "active"}
    assert captured["path"] == "/api/plugin/v1/genesis-imports/gen_123/finalize"
    assert captured["payload"] == {
        "protocol_version": "v1",
        "allow_blocked_security_findings": True,
    }


def test_client_finalize_delta_sync_can_send_security_override():
    captured = {}

    def handler(request: httpx.Request) -> httpx.Response:
        captured["path"] = request.url.path
        captured["payload"] = json.loads(request.content)
        return httpx.Response(200, json={"status": "active"})

    client = DevBoardClient(
        base_url="https://devboard.test",
        token="devb_live_token|secret",
        transport=httpx.MockTransport(handler),
    )

    response = client.finalize_delta_sync("delta_123", allow_blocked_security_findings=True)

    assert response == {"status": "active"}
    assert captured["path"] == "/api/plugin/v1/delta-syncs/delta_123/finalize"
    assert captured["payload"] == {
        "protocol_version": "v1",
        "allow_blocked_security_findings": True,
    }
