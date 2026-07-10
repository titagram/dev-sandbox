from __future__ import annotations

import json
import os
import subprocess
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def test_queue_fault_harness_refuses_missing_acceptance_before_docker(tmp_path: Path) -> None:
    bin_path = tmp_path / "bin"
    bin_path.mkdir()
    docker_marker = tmp_path / "docker-called"
    docker_stub = bin_path / "docker"
    docker_stub.write_text(
        "#!/usr/bin/env sh\n"
        'touch "$DEVBOARD_DOCKER_STUB_MARKER"\n'
        "exit 99\n"
    )
    docker_stub.chmod(0o755)

    env = os.environ.copy()
    env.pop("DEVBOARD_QUEUE_FAULT_ACCEPTANCE", None)
    env["DEVBOARD_QUEUE_FAULT_REPORT"] = str(tmp_path / "must-not-exist.json")
    env["DEVBOARD_DOCKER_STUB_MARKER"] = str(docker_marker)
    env["PATH"] = f"{bin_path}:{env['PATH']}"

    result = subprocess.run(
        [str(ROOT / "scripts" / "devboard_queue_fault_harness.sh")],
        cwd=ROOT,
        env=env,
        text=True,
        capture_output=True,
        timeout=10,
        check=False,
    )

    assert result.returncode == 2
    assert "Set DEVBOARD_QUEUE_FAULT_ACCEPTANCE=1" in result.stderr
    assert docker_marker.exists() is False


def test_queue_retry_fault_harness(tmp_path: Path) -> None:
    report_path = tmp_path / "queue-fault-report.json"
    env = os.environ.copy()
    env["DEVBOARD_QUEUE_FAULT_ACCEPTANCE"] = "1"
    env["DEVBOARD_QUEUE_FAULT_REPORT"] = str(report_path)
    env["DEVBOARD_GRAPH_IMPORT_JOB_TRIES"] = "3"
    env["DEVBOARD_GRAPH_IMPORT_JOB_BACKOFF_SECONDS"] = "0,0,0"

    result = subprocess.run(
        [str(ROOT / "scripts" / "devboard_queue_fault_harness.sh")],
        cwd=ROOT,
        env=env,
        text=True,
        capture_output=True,
        timeout=300,
        check=False,
    )

    assert result.returncode == 0, result.stdout + result.stderr
    report = json.loads(report_path.read_text())

    assert report["phase_one"]["import_status"] == "active"
    assert report["phase_one"]["graph_imported_event"] is False
    assert report["phase_one"]["graph_import_failed_event"] is False
    assert report["phase_final"]["import_status"] == "failed"
    assert report["phase_final"]["graph_import_failed_event"] is True
    assert report["phase_final"]["job_rows"] == 0
