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
