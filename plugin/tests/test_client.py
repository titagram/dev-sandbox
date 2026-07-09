import hashlib
import hmac
import json
import time

import httpx
import pytest

from devboard_plugin.client import DevBoardApiError, DevBoardClient


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


def test_client_wraps_non_devboard_error_responses():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(500, json={"message": "Server Error"})

    client = DevBoardClient(
        base_url="https://devboard.test",
        token="devb_live_token|secret",
        transport=httpx.MockTransport(handler),
    )

    with pytest.raises(DevBoardApiError) as error:
        client.post("/api/plugin/v1/repositories/repo_123/genesis-imports", {})

    assert error.value.code == "http_500"
    assert "500 Internal Server Error" in str(error.value)


def test_client_adds_device_signature_headers_when_device_secret_is_configured():
    captured_headers = {}

    def handler(request: httpx.Request) -> httpx.Response:
        captured_headers.update(request.headers)
        return httpx.Response(200, json={"ok": True})

    client = DevBoardClient(
        base_url="https://devboard.test",
        token="devb_live_token|secret",
        device_id="device_01ABC",
        device_secret="a" * 64,
        transport=httpx.MockTransport(handler),
    )

    client.post("/api/plugin/v1/auth/check")

    assert captured_headers["x-devboard-device-id"] == "device_01ABC"
    assert "x-devboard-timestamp" in captured_headers
    assert "x-devboard-content-sha256" in captured_headers
    assert "x-devboard-signature" in captured_headers

    timestamp = int(captured_headers["x-devboard-timestamp"])
    assert abs(time.time() - timestamp) <= 5

    expected_body_bytes = json.dumps({"protocol_version": "v1"}, separators=(",", ":")).encode()
    expected_body_hash = hashlib.sha256(expected_body_bytes).hexdigest()
    assert captured_headers["x-devboard-content-sha256"] == expected_body_hash

    signing_key = hashlib.sha256(b"a" * 64).hexdigest()
    canonical = f"POST\n/api/plugin/v1/auth/check\n{timestamp}\n{expected_body_hash}"
    expected_sig = "v1=" + hmac.new(signing_key.encode(), canonical.encode(), hashlib.sha256).hexdigest()
    assert captured_headers["x-devboard-signature"] == expected_sig


def test_credentials_round_trip_preserves_device_secret(tmp_path):
    from devboard_plugin.config import Credentials, save_credentials, load_credentials

    cred = Credentials(
        server_url="https://devboard.test",
        token="devb_live_token|secret",
        device_id="device_01ABC",
        device_secret="b" * 64,
    )

    path = save_credentials(cred, tmp_path / ".config" / "devboard" / "credentials.json")
    loaded = load_credentials(path)

    assert loaded.device_id == "device_01ABC"
    assert loaded.device_secret == "b" * 64
