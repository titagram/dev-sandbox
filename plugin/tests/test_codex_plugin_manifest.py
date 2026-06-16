from __future__ import annotations

import json
from pathlib import Path

import tomllib


PLUGIN_ROOT = Path(__file__).resolve().parents[1]


def test_codex_plugin_manifest_points_to_mcp_config():
    manifest = json.loads((PLUGIN_ROOT / ".codex-plugin" / "plugin.json").read_text())

    assert manifest["name"] == "devboard"
    assert manifest["mcpServers"] == "./.mcp.json"
    assert manifest["interface"]["displayName"] == "DevBoard"
    assert "Codex" in manifest["description"]
    assert "Claude" in manifest["description"]


def test_mcp_config_runs_devboard_mcp_entrypoint():
    config = json.loads((PLUGIN_ROOT / ".mcp.json").read_text())

    assert config["mcpServers"]["devboard"]["command"] == "devboard-mcp"
    assert config["mcpServers"]["devboard"]["args"] == []


def test_pyproject_exposes_devboard_mcp_script():
    pyproject = tomllib.loads((PLUGIN_ROOT / "pyproject.toml").read_text())

    assert pyproject["project"]["scripts"]["devboard-mcp"] == "devboard_plugin.mcp_server:main"
