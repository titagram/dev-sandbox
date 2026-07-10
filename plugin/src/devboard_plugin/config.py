from __future__ import annotations

import hmac
import json
import os
from dataclasses import asdict, dataclass
from pathlib import Path
from typing import Any

from devboard_plugin.client import DevBoardApiError, normalize_server_url
from devboard_plugin.secure_io import atomic_write_text, reject_symlink_components


@dataclass(frozen=True)
class Credentials:
    server_url: str
    token: str
    device_id: str | None = None
    device_secret: str | None = None

    def __post_init__(self) -> None:
        object.__setattr__(self, "server_url", normalize_server_url(self.server_url))


def credentials_path() -> Path:
    override = os.environ.get("DEVBOARD_CREDENTIALS_PATH")
    if override:
        return Path(override).expanduser()

    return Path.home() / ".config" / "devboard" / "credentials.json"


def load_credentials(path: Path | None = None) -> Credentials:
    credential_file = path or credentials_path()
    try:
        reject_symlink_components(credential_file)
        data = json.loads(credential_file.read_text())
    except FileNotFoundError:
        raise DevBoardApiError(
            code="credentials_missing",
            message=(
                f"Credentials file not found at {credential_file}. "
                "Run 'devboard auth check --server-url <URL> --token <TOKEN>' to configure."
            ),
        ) from None

    except (OSError, ValueError, json.JSONDecodeError, TypeError, KeyError) as error:
        raise DevBoardApiError(code="credentials_invalid", message=f"Credentials file is invalid: {error}") from None

    try:
        return Credentials(
            server_url=data["server_url"],
            token=data["token"],
            device_id=data.get("device_id"),
            device_secret=data.get("device_secret"),
        )
    except (TypeError, KeyError) as error:
        raise DevBoardApiError(code="credentials_invalid", message=f"Credentials file is invalid: {error}") from None


def save_credentials(credentials: Credentials, path: Path | None = None) -> Path:
    credential_file = path or credentials_path()
    atomic_write_text(credential_file, json.dumps(asdict(credentials), indent=2, sort_keys=True) + "\n")

    return credential_file


def credentials_from_options(server_url: str | None, token: str | None) -> Credentials:
    normalized_override = normalize_server_url(server_url) if server_url else None
    if server_url and token:
        try:
            loaded = load_credentials()
        except DevBoardApiError as error:
            if error.code != "credentials_missing":
                raise
            return Credentials(server_url=normalized_override, token=token)
        if loaded.server_url == normalized_override and hmac.compare_digest(loaded.token, token):
            return Credentials(
                server_url=normalized_override,
                token=token,
                device_id=loaded.device_id,
                device_secret=loaded.device_secret,
            )
        return Credentials(server_url=normalized_override, token=token)

    loaded = load_credentials()

    if normalized_override and normalized_override != loaded.server_url:
        raise DevBoardApiError(
            code="credentials_origin_mismatch",
            message=(
                f"Configured credentials belong to {loaded.server_url}, not the different server origin "
                f"{normalized_override}. Provide an explicit token for the new origin."
            ),
        )

    selected_token = token if token is not None else loaded.token
    reuse_device = token is None or hmac.compare_digest(loaded.token, token)
    return Credentials(
        server_url=normalized_override or loaded.server_url,
        token=selected_token,
        device_id=loaded.device_id if reuse_device else None,
        device_secret=loaded.device_secret if reuse_device else None,
    )


def public_credentials_summary(credentials: Credentials) -> dict[str, Any]:
    return {
        "server_url": credentials.server_url,
        "device_id": credentials.device_id,
        "token_configured": bool(credentials.token),
    }
