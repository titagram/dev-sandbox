from __future__ import annotations

import json
import os
import subprocess
from pathlib import Path

import pytest


ROOT = Path(__file__).resolve().parents[2]


@pytest.mark.skipif(
    os.environ.get("DEVBOARD_RUNTIME_ACCEPTANCE") != "1",
    reason="Set DEVBOARD_RUNTIME_ACCEPTANCE=1 to run the Ubuntu runtime acceptance harness.",
)
def test_runtime_acceptance(tmp_path: Path) -> None:
    report_path = tmp_path / "runtime-acceptance-report.json"
    env = os.environ.copy()
    env["DEVBOARD_RUNTIME_ACCEPTANCE_REPORT"] = str(report_path)

    result = subprocess.run(
        [str(ROOT / "scripts" / "devboard_runtime_acceptance.sh")],
        cwd=ROOT,
        env=env,
        text=True,
        capture_output=True,
        timeout=600,
        check=False,
    )

    assert result.returncode == 0, result.stdout + result.stderr
    report = json.loads(report_path.read_text())

    assert report["host"]["os"] == "Linux"
    assert report["host"]["arch"] in {"x86_64", "amd64"}
    assert report["docker"]["os"] == "linux"
    assert report["docker"]["arch"] in {"x86_64", "amd64"}
    assert report["services"]["app_up"] is True
    assert report["services"]["postgres_ok"] is True
    assert report["services"]["neo4j_ok"] is True
    assert report["e2e"]["onboarding_genesis_passed"] is True
