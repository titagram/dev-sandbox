from __future__ import annotations

import json
import os
from dataclasses import asdict, dataclass
from pathlib import Path
from typing import Any

from devboard_plugin.client import DevBoardApiError


@dataclass(frozen=True)
class Credentials:
    server_url: str
    token: str
    device_id: str | None = None
    device_secret: str | None = None


def credentials_path() -> Path:
    override = os.environ.get("DEVBOARD_CREDENTIALS_PATH")
    if override:
        return Path(override).expanduser()

    return Path.home() / ".config" / "devboard" / "credentials.json"


def load_credentials(path: Path | None = None) -> Credentials:
    credential_file = path or credentials_path()
    try:
        data = json.loads(credential_file.read_text())
    except FileNotFoundError:
        raise DevBoardApiError(
            code="credentials_missing",
            message=(
                f"Credentials file not found at {credential_file}. "
                "Run 'devboard auth check --server-url <URL> --token <TOKEN>' to configure."
            ),
        ) from None

    return Credentials(
        server_url=data["server_url"],
        token=data["token"],
        device_id=data.get("device_id"),
        device_secret=data.get("device_secret"),
    )


def save_credentials(credentials: Credentials, path: Path | None = None) -> Path:
    credential_file = path or credentials_path()
    credential_file.parent.mkdir(parents=True, exist_ok=True)
    credential_file.write_text(json.dumps(asdict(credentials), indent=2, sort_keys=True) + "\n")
    os.chmod(credential_file, 0o600)

    return credential_file


def credentials_from_options(server_url: str | None, token: str | None) -> Credentials:
    if server_url and token:
        return Credentials(server_url=server_url, token=token)

    loaded = load_credentials()

    return Credentials(
        server_url=server_url or loaded.server_url,
        token=token or loaded.token,
        device_id=loaded.device_id,
        device_secret=loaded.device_secret,
    )


def public_credentials_summary(credentials: Credentials) -> dict[str, Any]:
    return {
        "server_url": credentials.server_url,
        "device_id": credentials.device_id,
        "token_configured": bool(credentials.token),
    }
