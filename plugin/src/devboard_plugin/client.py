from __future__ import annotations

from dataclasses import dataclass
import hashlib
import hmac
import json
import time
from typing import Any
from urllib.parse import urlencode, urlsplit
import ipaddress

import httpx
from httpx import ConnectError, TimeoutException


class DevBoardApiError(RuntimeError):
    def __init__(self, code: str, message: str, details: dict[str, Any] | None = None):
        super().__init__(message)
        self.code = code
        self.details = details or {}


def normalize_server_url(server_url: str) -> str:
    try:
        parsed = urlsplit(server_url.strip())
        port = parsed.port
    except (AttributeError, ValueError):
        raise DevBoardApiError(code="invalid_server_url", message="server_url must be a valid HTTP(S) URL.") from None

    scheme = parsed.scheme.lower()
    host = parsed.hostname
    if scheme not in {"http", "https"} or not host or parsed.username or parsed.password:
        raise DevBoardApiError(code="invalid_server_url", message="server_url must be an HTTP(S) origin without credentials.")

    host = host.lower().rstrip(".")
    try:
        is_loopback = ipaddress.ip_address(host).is_loopback
    except ValueError:
        is_loopback = host == "localhost" or host.endswith(".localhost")
    if scheme != "https" and not is_loopback:
        raise DevBoardApiError(code="insecure_server_url", message="HTTPS is required for non-loopback server hosts.")

    if ":" in host:
        host = f"[{host}]"
    default_port = (scheme == "https" and port == 443) or (scheme == "http" and port == 80)
    return f"{scheme}://{host}{f':{port}' if port is not None and not default_port else ''}"


@dataclass
class DevBoardClient:
    base_url: str
    token: str
    device_id: str | None = None
    device_secret: str | None = None
    plugin_version: str = "0.1.0"
    transport: httpx.BaseTransport | None = None
    max_retries: int = 3

    def __post_init__(self) -> None:
        self.base_url = normalize_server_url(self.base_url)
        self._http_client: httpx.Client | None = None

    def _get_client(self) -> httpx.Client:
        if self._http_client is None:
            self._http_client = httpx.Client(
                base_url=self.base_url.rstrip("/"),
                transport=self.transport,
                timeout=30.0,
            )
        return self._http_client

    def _close_client(self) -> None:
        if self._http_client is not None:
            self._http_client.close()
            self._http_client = None

    def get(self, path: str) -> dict[str, Any]:
        return self.request("GET", path)

    def post(self, path: str, payload: dict[str, Any] | None = None) -> dict[str, Any]:
        body: dict[str, Any] = {"protocol_version": "v1"}
        if payload:
            body.update(payload)

        return self.request("POST", path, body=body)

    def request(self, method: str, path: str, body: dict[str, Any] | None = None) -> dict[str, Any]:
        body_bytes = b""
        if body is not None:
            body_bytes = json.dumps(body, separators=(",", ":")).encode()

        response = self._send_with_retry(
            method, path, self.headers(method, path, body_bytes), body_bytes if body_bytes else None
        )

        if response.content:
            return response.json()

        return {}

    def request_bytes(self, method: str, path: str, content: bytes, headers: dict[str, str]) -> dict[str, Any]:
        merged_headers = self.headers(method, path, content)
        merged_headers.update(headers)

        response = self._send_with_retry(method, path, merged_headers, content if content else None)

        return response.json() if response.content else {}

    def _send_with_retry(
        self, method: str, path: str, headers: dict[str, str], content: bytes | None
    ) -> httpx.Response:
        last_exception: Exception | None = None

        attempts = self.max_retries if method.upper() in {"GET", "HEAD", "OPTIONS", "PUT", "DELETE"} else 1
        for attempt in range(attempts):
            try:
                response = self._get_client().request(method, path, headers=headers, content=content)

                if response.is_error and response.status_code >= 500:
                    if attempt < attempts - 1:
                        time.sleep(2 ** attempt)
                        self._close_client()
                        continue
                    self._raise_api_error(response)

                if response.is_error:
                    self._raise_api_error(response)

                return response
            except (ConnectError, TimeoutException) as e:
                last_exception = e
                if attempt < attempts - 1:
                    time.sleep(2 ** attempt)
                    self._close_client()
                    continue
                raise DevBoardApiError(code="connect_error", message=str(e)) from None

        if isinstance(last_exception, httpx.HTTPStatusError) and last_exception.response is not None:
            self._raise_api_error(last_exception.response)

        raise DevBoardApiError(code="request_failed", message="Request failed after all retries") from last_exception

    def headers(self, method: str = "GET", path: str = "/", body_bytes: bytes = b"") -> dict[str, str]:
        headers = {
            "Authorization": f"Bearer {self.token}",
            "Accept": "application/json",
            "Content-Type": "application/json",
            "X-DevBoard-Protocol": "v1",
            "X-DevBoard-Plugin-Version": self.plugin_version,
        }

        if self.device_id:
            headers["X-DevBoard-Device-Id"] = self.device_id

            if self.device_secret:
                timestamp = int(time.time())
                signing_key = hashlib.sha256(self.device_secret.encode()).hexdigest()
                body_hash = hashlib.sha256(body_bytes).hexdigest()
                canonical = f"{method}\n{path}\n{timestamp}\n{body_hash}"
                signature = "v1=" + hmac.new(signing_key.encode(), canonical.encode(), hashlib.sha256).hexdigest()

                headers["X-DevBoard-Timestamp"] = str(timestamp)
                headers["X-DevBoard-Content-SHA256"] = body_hash
                headers["X-DevBoard-Signature"] = signature

        return headers

    def auth_check(self) -> dict[str, Any]:
        return self.post("/api/plugin/v1/auth/check")

    def register_device(self, payload: dict[str, Any]) -> dict[str, Any]:
        response = self.post("/api/plugin/v1/devices/register", payload)

        device_secret = response.get("device_secret")
        if device_secret:
            self.device_secret = device_secret

        return response

    def list_projects(self) -> dict[str, Any]:
        return self.get("/api/plugin/v1/projects")

    def list_repositories(self, project_id: str) -> dict[str, Any]:
        return self.get(f"/api/plugin/v1/projects/{project_id}/repositories")

    def register_local_workspace(self, repository_id: str, payload: dict[str, Any]) -> dict[str, Any]:
        return self.post(f"/api/plugin/v1/repositories/{repository_id}/local-workspaces", payload)

    def repository_policy(self, repository_id: str) -> dict[str, Any]:
        return self.get(f"/api/plugin/v1/repositories/{repository_id}/policy")

    def repository_instructions(self, repository_id: str) -> dict[str, Any]:
        return self.get(f"/api/plugin/v1/repositories/{repository_id}/instructions")

    def shared_memory_pack(self, project_id: str, repository_id: str | None = None) -> dict[str, Any]:
        return self.get(
            self._path_with_query(
                f"/api/plugin/v1/projects/{project_id}/shared-memory-pack",
                {"repository_id": repository_id},
            )
        )

    def list_work_items(self, project_id: str | None = None, repository_id: str | None = None) -> dict[str, Any]:
        return self.get(
            self._path_with_query(
                "/api/plugin/v1/agent-work-items",
                {"project_id": project_id, "repository_id": repository_id},
            )
        )

    def claim_work_item(self, work_item_id: str, local_workspace_id: str) -> dict[str, Any]:
        return self.post(
            f"/api/plugin/v1/agent-work-items/{work_item_id}/claim",
            {"local_workspace_id": local_workspace_id},
        )

    def heartbeat_work_item(self, work_item_id: str, lease_token: str) -> dict[str, Any]:
        return self.post(
            f"/api/plugin/v1/agent-work-items/{work_item_id}/heartbeat",
            {"lease_token": lease_token},
        )

    def complete_work_item(
        self,
        work_item_id: str,
        lease_token: str,
        memory_entry: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        payload: dict[str, Any] = {"lease_token": lease_token}
        if memory_entry is not None:
            payload["memory_entry"] = memory_entry

        return self.post(f"/api/plugin/v1/agent-work-items/{work_item_id}/complete", payload)

    def fail_work_item(self, work_item_id: str, lease_token: str, failure_reason: str) -> dict[str, Any]:
        return self.post(
            f"/api/plugin/v1/agent-work-items/{work_item_id}/fail",
            {"lease_token": lease_token, "failure_reason": failure_reason},
        )

    def start_run(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self.post("/api/plugin/v1/runs", payload)

    def heartbeat_run(self, run_id: str, payload: dict[str, Any] | None = None) -> dict[str, Any]:
        return self.post(f"/api/plugin/v1/runs/{run_id}/heartbeat", payload or {})

    def finish_run(self, run_id: str, payload: dict[str, Any]) -> dict[str, Any]:
        return self.post(f"/api/plugin/v1/runs/{run_id}/finish", payload)

    def start_genesis_import(
        self,
        repository_id: str,
        manifest: dict[str, Any],
        run_id: str,
        local_workspace_id: str,
    ) -> dict[str, Any]:
        return self.post(
            f"/api/plugin/v1/repositories/{repository_id}/genesis-imports",
            {
                "run_id": run_id,
                "local_workspace_id": local_workspace_id,
                "manifest": manifest,
            },
        )

    def upload_genesis_chunk(self, import_id: str, artifact_id: str, chunk_index: int, content: bytes) -> dict[str, Any]:
        return self.request_bytes(
            "PUT",
            f"/api/plugin/v1/genesis-imports/{import_id}/artifacts/{artifact_id}/chunks/{chunk_index}",
            content,
            {
                "X-DevBoard-Chunk-SHA256": hashlib.sha256(content).hexdigest(),
                "X-DevBoard-Chunk-Size": str(len(content)),
                "Content-Type": "application/octet-stream",
            },
        )

    def finalize_genesis_import(
        self,
        import_id: str,
        allow_blocked_security_findings: bool = False,
    ) -> dict[str, Any]:
        payload = {}
        if allow_blocked_security_findings:
            payload["allow_blocked_security_findings"] = True

        return self.post(f"/api/plugin/v1/genesis-imports/{import_id}/finalize", payload)

    def record_local_snapshot(self, run_id: str, payload: dict[str, Any]) -> dict[str, Any]:
        return self.post(f"/api/plugin/v1/runs/{run_id}/local-snapshots", payload)

    def start_delta_sync(
        self,
        run_id: str,
        manifest: dict[str, Any],
        local_workspace_id: str,
        base_snapshot_id: str,
    ) -> dict[str, Any]:
        return self.post(
            f"/api/plugin/v1/runs/{run_id}/delta-syncs",
            {
                "local_workspace_id": local_workspace_id,
                "base_snapshot_id": base_snapshot_id,
                "branch": manifest.get("branch", "unknown"),
                "base_sha": manifest.get("base_sha") or manifest.get("head_sha") or "unknown",
                "head_sha": manifest.get("head_sha"),
                "dirty_status": manifest.get("dirty_status", "unknown"),
                "manifest": manifest,
            },
        )

    def upload_delta_chunk(self, delta_id: str, artifact_id: str, chunk_index: int, content: bytes) -> dict[str, Any]:
        return self.request_bytes(
            "PUT",
            f"/api/plugin/v1/delta-syncs/{delta_id}/artifacts/{artifact_id}/chunks/{chunk_index}",
            content,
            {
                "X-DevBoard-Chunk-SHA256": hashlib.sha256(content).hexdigest(),
                "X-DevBoard-Chunk-Size": str(len(content)),
                "Content-Type": "application/octet-stream",
            },
        )

    def finalize_delta_sync(
        self,
        delta_id: str,
        allow_blocked_security_findings: bool = False,
    ) -> dict[str, Any]:
        payload = {}
        if allow_blocked_security_findings:
            payload["allow_blocked_security_findings"] = True

        return self.post(f"/api/plugin/v1/delta-syncs/{delta_id}/finalize", payload)

    def query_graph(
        self,
        project_id: str,
        type: str,
        symbol_id: str | None = None,
        from_symbol_id: str | None = None,
        to_symbol_id: str | None = None,
        limit: int = 50,
        max_depth: int = 5,
    ) -> dict[str, Any]:
        payload: dict[str, Any] = {"type": type}
        if symbol_id is not None:
            payload["symbol_id"] = symbol_id
        if from_symbol_id is not None:
            payload["from_symbol_id"] = from_symbol_id
        if to_symbol_id is not None:
            payload["to_symbol_id"] = to_symbol_id
        if type in ("callers", "callees"):
            payload["limit"] = limit
        if type == "path":
            payload["max_depth"] = max_depth

        return self.post(f"/api/plugin/v1/projects/{project_id}/graph/query", payload)

    def _path_with_query(self, path: str, query: dict[str, str | None]) -> str:
        params = {key: value for key, value in query.items() if value is not None}
        if not params:
            return path

        return f"{path}?{urlencode(params)}"

    def _raise_api_error(self, response: httpx.Response) -> None:
        try:
            error = response.json()["error"]
        except (ValueError, KeyError, TypeError):
            raise DevBoardApiError(
                code=f"http_{response.status_code}",
                message=f"{response.status_code} {response.reason_phrase}: DevBoard API request failed.",
                details={"url": str(response.request.url)},
            ) from None

        raise DevBoardApiError(
            code=error.get("code", "server_error"),
            message=error.get("message", "DevBoard API request failed."),
            details=error.get("details", {}),
        )
