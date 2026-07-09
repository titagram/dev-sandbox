import tomllib
from pathlib import Path

from typer.testing import CliRunner

from devboard_plugin.cli import app


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
