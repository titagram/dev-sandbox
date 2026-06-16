from __future__ import annotations

from dataclasses import dataclass
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
