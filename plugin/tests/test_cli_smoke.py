import tomllib
import json
from pathlib import Path

from typer.testing import CliRunner

from devboard_plugin import cli
from devboard_plugin.cli import app
from devboard_plugin.config import Credentials, save_credentials


def test_version_command_outputs_version():
    result = CliRunner().invoke(app, ["version"])

    assert result.exit_code == 0
    assert "devboard-plugin 0.1.0" in result.output


def test_plugin_declares_analyzer_dependency():
    pyproject = Path(__file__).resolve().parent.parent / "pyproject.toml"
    data = tomllib.loads(pyproject.read_text())
    dependencies = data.get("project", {}).get("dependencies", [])

    devboard_analyzer_deps = [d for d in dependencies if isinstance(d, str) and "devboard-analyzer" in d]
    assert len(devboard_analyzer_deps) > 0, "devboard-analyzer must be declared as a plugin dependency"


def test_cli_server_override_rejects_credentials_for_another_origin(monkeypatch, tmp_path):
    credentials = tmp_path / "credentials.json"
    save_credentials(Credentials("https://one.example", "token-one"), credentials)
    monkeypatch.setenv("DEVBOARD_CREDENTIALS_PATH", str(credentials))

    result = CliRunner().invoke(app, ["projects", "list", "--server-url", "https://two.example"])

    assert result.exit_code != 0
    assert "different server origin" in str(result.exception)


def test_register_device_persists_secret_without_printing_it(monkeypatch, tmp_path):
    secret = "device-secret-value"
    credentials = tmp_path / "credentials.json"
    save_credentials(Credentials("https://devboard.test", "token"), credentials)
    monkeypatch.setenv("DEVBOARD_CREDENTIALS_PATH", str(credentials))

    class FakeClient:
        def register_device(self, payload):
            return {"device_id": "device-1", "device_secret": secret, "status": "registered"}

    monkeypatch.setattr(cli, "client_from_credentials", lambda configured: FakeClient())
    result = CliRunner().invoke(
        app,
        ["auth", "register-device", "laptop", "hash", "linux", "amd64"],
    )

    assert result.exit_code == 0
    assert secret not in result.output
    assert json.loads(result.output) == {"device_id": "device-1", "status": "registered"}
    assert cli.load_credentials(credentials).device_secret == secret


def test_cli_client_receives_device_secret(monkeypatch):
    captured = {}
    monkeypatch.setattr(cli, "DevBoardClient", lambda **kwargs: captured.update(kwargs) or object())

    cli.client_from_credentials(Credentials("https://devboard.test", "token", "device-1", "device-secret"))

    assert captured["device_secret"] == "device-secret"


def test_second_register_device_preserves_one_shot_secret_for_same_device(monkeypatch, tmp_path):
    credentials = tmp_path / "credentials.json"
    save_credentials(
        Credentials("https://devboard.test", "token", "device-1", "existing-secret"),
        credentials,
    )
    monkeypatch.setenv("DEVBOARD_CREDENTIALS_PATH", str(credentials))

    class FakeClient:
        def register_device(self, payload):
            return {"device_id": "device-1", "status": "already_registered"}

    monkeypatch.setattr(cli, "client_from_credentials", lambda configured: FakeClient())
    result = CliRunner().invoke(app, ["auth", "register-device", "laptop", "hash", "linux", "amd64"])

    assert result.exit_code == 0
    assert cli.load_credentials(credentials).device_secret == "existing-secret"
    assert "existing-secret" not in result.output


def test_register_device_without_available_secret_fails_cleanly(monkeypatch, tmp_path):
    credentials = tmp_path / "credentials.json"
    save_credentials(Credentials("https://devboard.test", "token"), credentials)
    monkeypatch.setenv("DEVBOARD_CREDENTIALS_PATH", str(credentials))

    class FakeClient:
        def register_device(self, payload):
            return {"device_id": "device-1", "status": "already_registered"}

    monkeypatch.setattr(cli, "client_from_credentials", lambda configured: FakeClient())
    result = CliRunner().invoke(app, ["auth", "register-device", "laptop", "hash", "linux", "amd64"])

    assert result.exit_code != 0
    assert isinstance(result.exception, cli.DevBoardApiError)
    assert result.exception.code == "device_secret_missing"
    assert not isinstance(result.exception, KeyError)
