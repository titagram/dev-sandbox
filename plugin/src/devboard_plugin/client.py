from __future__ import annotations

from dataclasses import dataclass
import hashlib
from typing import Any

import httpx


class DevBoardApiError(RuntimeError):
    def __init__(self, code: str, message: str, details: dict[str, Any] | None = None):
        super().__init__(message)
        self.code = code
        self.details = details or {}


@dataclass
class DevBoardClient:
    base_url: str
    token: str
    device_id: str | None = None
    plugin_version: str = "0.1.0"
    transport: httpx.BaseTransport | None = None

    def get(self, path: str) -> dict[str, Any]:
        return self.request("GET", path)

    def post(self, path: str, payload: dict[str, Any] | None = None) -> dict[str, Any]:
        body = {"protocol_version": "v1"}
        if payload:
            body.update(payload)

        return self.request("POST", path, json=body)

    def request(self, method: str, path: str, json: dict[str, Any] | None = None) -> dict[str, Any]:
        with httpx.Client(
            base_url=self.base_url.rstrip("/"),
            headers=self.headers(),
            transport=self.transport,
            timeout=30.0,
        ) as client:
            response = client.request(method, path, json=json)

        if response.is_error:
            self._raise_api_error(response)

        if response.content:
            return response.json()

        return {}

    def request_bytes(self, method: str, path: str, content: bytes, headers: dict[str, str]) -> dict[str, Any]:
        merged_headers = self.headers()
        merged_headers.update(headers)
        with httpx.Client(
            base_url=self.base_url.rstrip("/"),
            headers=merged_headers,
            transport=self.transport,
            timeout=30.0,
        ) as client:
            response = client.request(method, path, content=content)

        if response.is_error:
            self._raise_api_error(response)

        return response.json() if response.content else {}

    def headers(self) -> dict[str, str]:
        headers = {
            "Authorization": f"Bearer {self.token}",
            "Accept": "application/json",
            "Content-Type": "application/json",
            "X-DevBoard-Protocol": "v1",
            "X-DevBoard-Plugin-Version": self.plugin_version,
        }

        if self.device_id:
            headers["X-DevBoard-Device-Id"] = self.device_id

        return headers

    def auth_check(self) -> dict[str, Any]:
        return self.post("/api/plugin/v1/auth/check")

    def register_device(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self.post("/api/plugin/v1/devices/register", payload)

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

    def _raise_api_error(self, response: httpx.Response) -> None:
        try:
            error = response.json()["error"]
        except (ValueError, KeyError, TypeError):
            response.raise_for_status()

        raise DevBoardApiError(
            code=error.get("code", "server_error"),
            message=error.get("message", "DevBoard API request failed."),
            details=error.get("details", {}),
        )
