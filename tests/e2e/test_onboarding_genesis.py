from __future__ import annotations

import json
import os
import subprocess
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def test_onboarding_genesis_e2e(tmp_path: Path) -> None:
    report_path = tmp_path / "report.json"
    env = os.environ.copy()
    env["DEVBOARD_E2E_WORKDIR"] = str(tmp_path / "work")
    env["DEVBOARD_E2E_REPORT"] = str(report_path)

    result = subprocess.run(
        [str(ROOT / "scripts" / "devboard_e2e_bootstrap.sh")],
        cwd=ROOT,
        env=env,
        text=True,
        capture_output=True,
        timeout=int(os.environ.get("DEVBOARD_E2E_TIMEOUT", "240")),
        check=False,
    )

    assert result.returncode == 0, result.stdout + result.stderr
    assert report_path.exists(), result.stdout + result.stderr

    report = json.loads(report_path.read_text())

    assert report["auth_check"]["authenticated"] is True
    assert report["device"]["status"] == "active"
    assert report["local_workspace"]["status"] == "linked"
    assert report["repo_state"]["exists"] is True
    assert report["repo_state"]["contains_secret"] is False
    assert report["genesis"]["status"] == "active"
    assert report["graph"]["status"] in {"imported", "fake-imported"}
    assert report["dashboard"]["project_response_ok"] is True
    assert report["dashboard"]["repository_initialized"] is True
