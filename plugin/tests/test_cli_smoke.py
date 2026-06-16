from typer.testing import CliRunner

from devboard_plugin.cli import app


def test_version_command_outputs_version():
    result = CliRunner().invoke(app, ["version"])

    assert result.exit_code == 0
    assert "devboard-plugin 0.1.0" in result.output
