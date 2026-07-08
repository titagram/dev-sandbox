# DevBoard Runtime Residuals Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the remaining real-world runtime gaps for DevBoard V1: prove the stack on a real Ubuntu x64 host and add a deterministic Docker fault harness that exhausts Neo4j queue retries and captures the final failure transition.

**Architecture:** Reuse the current Docker, Laravel, plugin, and E2E bootstrap surface instead of inventing a parallel test system. Add two operator-facing runtime scripts at the repo root `scripts/`, keep the queue failure semantics configurable through Laravel config, and write pytest wrappers that assert on generated JSON reports rather than scraping logs.

**Tech Stack:** Laravel 13, Docker Compose, PostgreSQL, Neo4j, Python 3, pytest, bash, existing `scripts/devboard_e2e_bootstrap.sh`.

---

## Current Verified Baseline

These facts are already true in the current branch and should be treated as baseline, not re-designed:

- `README.md` already documents the local stack, test credentials, plugin bootstrap, and known residuals.
- `scripts/devboard_e2e_bootstrap.sh` already performs a real onboarding + Genesis happy path against a locally served Laravel backend.
- `backend/app/Jobs/ImportGenesisGraphToNeo4j.php` now leaves `genesis_imports.status = active` while retries are still pending.
- `backend/app/Jobs/ImportGenesisGraphToNeo4j.php::failed()` is the only place that should mark a queued graph import permanently failed after retries are exhausted.
- Docker runtime defaults today:
  - app: `http://127.0.0.1:8000`
  - Postgres: `devboard/devboard`
  - Neo4j: `neo4j/graphify-sandbox`
- Seed credentials today:
  - dashboard: `admin@example.com / password`

## Required Context To Read First

- `README.md`
- `docker/devboard/README.md`
- `docker-compose.devboard.yaml`
- `docker-compose.devboard.amd64.yaml`
- `scripts/devboard_e2e_bootstrap.sh`
- `tests/e2e/test_onboarding_genesis.py`
- `backend/app/Jobs/ImportGenesisGraphToNeo4j.php`
- `backend/app/Services/GenesisGraphImportService.php`
- `backend/config/services.php`
- `backend/tests/Feature/GenesisGraphImportTest.php`

## Scope Boundaries

In scope:

- deterministic live retry/fault validation against Docker services;
- explicit runtime scripts that write machine-readable JSON reports;
- configurable queue retry/backoff knobs needed only to make the live harness fast and reproducible;
- Ubuntu x64 operator documentation and acceptance script.

Out of scope:

- changing the product contract for Genesis/Delta;
- redesigning Docker topology;
- adding SaaS/CI deployment automation;
- broad infra refactors unrelated to runtime validation.

## Deliverables

At the end of this plan the repo must contain:

- `scripts/devboard_queue_fault_harness.sh`
- `scripts/devboard_runtime_acceptance.sh`
- `tests/e2e/test_queue_retry_fault.py`
- `tests/e2e/test_runtime_acceptance.py`
- `docs/runbooks/devboard-ubuntu-x64-validation.md`
- updated runtime config in `backend/config/services.php`
- updated queue job behavior in `backend/app/Jobs/ImportGenesisGraphToNeo4j.php`
- updated backend tests for config-driven retry/backoff behavior
- updated `README.md` and `docker/devboard/README.md`

## Commit Strategy

Use these commit messages:

```text
test: cover configurable graph import retry knobs
feat: add deterministic queue fault harness
feat: add ubuntu runtime acceptance harness
docs: add ubuntu runtime validation runbook
```

### Task 1: Make queued graph retry timing configurable

**Files:**
- Modify: `backend/config/services.php`
- Modify: `backend/app/Jobs/ImportGenesisGraphToNeo4j.php`
- Modify: `backend/tests/Feature/GenesisGraphImportTest.php`

- [ ] **Step 1: Write the failing backend test for configurable retry timing**

Add this test to `backend/tests/Feature/GenesisGraphImportTest.php`:

```php
it('uses config-driven retry and backoff values for queued graph imports', function () {
    config([
        'services.devboard.graph_import_job_tries' => 4,
        'services.devboard.graph_import_job_backoff_seconds' => [0, 1, 2],
    ]);

    $job = new ImportGenesisGraphToNeo4j('import_123');

    expect($job->tries)->toBe(4);
    expect($job->backoff())->toBe([0, 1, 2]);
});
```

- [ ] **Step 2: Run the focused backend test and verify RED**

Run:

```bash
cd backend
php artisan test tests/Feature/GenesisGraphImportTest.php --filter='config-driven retry'
```

Expected: FAIL because the job still hardcodes `tries = 3` and `[10, 60, 300]`.

- [ ] **Step 3: Add config defaults to `backend/config/services.php`**

Add these keys under `services.devboard`:

```php
'graph_import_job_tries' => env('DEVBOARD_GRAPH_IMPORT_JOB_TRIES', 3),
'graph_import_job_backoff_seconds' => array_map(
    static fn (string $value): int => max(0, (int) trim($value)),
    array_filter(
        explode(',', (string) env('DEVBOARD_GRAPH_IMPORT_JOB_BACKOFF_SECONDS', '10,60,300')),
        static fn (string $value): bool => trim($value) !== ''
    )
),
```

- [ ] **Step 4: Make the queue job read the config**

Update `backend/app/Jobs/ImportGenesisGraphToNeo4j.php` like this:

```php
public int $tries;

public int $maxExceptions;

public int $timeout = 120;

public function __construct(public readonly string $genesisImportId)
{
    $this->tries = max(1, (int) config('services.devboard.graph_import_job_tries', 3));
    $this->maxExceptions = $this->tries;
}

public function backoff(): array
{
    $configured = config('services.devboard.graph_import_job_backoff_seconds', [10, 60, 300]);

    if (! is_array($configured) || $configured === []) {
        return [10, 60, 300];
    }

    return array_map(
        static fn (mixed $value): int => max(0, (int) $value),
        $configured,
    );
}
```

- [ ] **Step 5: Re-run the focused backend test and verify GREEN**

Run:

```bash
cd backend
php artisan test tests/Feature/GenesisGraphImportTest.php --filter='config-driven retry'
```

Expected: PASS.

- [ ] **Step 6: Re-run the whole graph import feature suite**

Run:

```bash
cd backend
php artisan test tests/Feature/GenesisGraphImportTest.php
```

Expected: PASS with no regression in the existing queued-failure semantics.

- [ ] **Step 7: Commit**

```bash
git add backend/config/services.php backend/app/Jobs/ImportGenesisGraphToNeo4j.php backend/tests/Feature/GenesisGraphImportTest.php
git commit -m "test: cover configurable graph import retry knobs"
```

### Task 2: Add a deterministic Docker queue fault harness

**Files:**
- Create: `scripts/devboard_queue_fault_harness.sh`
- Create: `tests/e2e/test_queue_retry_fault.py`
- Modify: `README.md`
- Modify: `docker/devboard/README.md`

- [ ] **Step 1: Write the failing E2E wrapper test**

Create `tests/e2e/test_queue_retry_fault.py`:

```python
from __future__ import annotations

import json
import os
import subprocess
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def test_queue_retry_fault_harness(tmp_path: Path) -> None:
    report_path = tmp_path / "queue-fault-report.json"
    env = os.environ.copy()
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
```

- [ ] **Step 2: Run the new E2E test and verify RED**

Run:

```bash
/tmp/devboard-plugin-venv/bin/python -m pytest tests/e2e/test_queue_retry_fault.py -q
```

Expected: FAIL because the harness script does not exist yet.

- [ ] **Step 3: Create the fault harness script**

Create `scripts/devboard_queue_fault_harness.sh` with this structure:

```bash
#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPORT="${DEVBOARD_QUEUE_FAULT_REPORT:-/tmp/devboard-queue-fault-report.json}"
COMPOSE=(docker compose -f "${ROOT}/docker-compose.devboard.yaml")

cleanup() {
  "${COMPOSE[@]}" start neo4j >/dev/null 2>&1 || true
}
trap cleanup EXIT

"${COMPOSE[@]}" up -d app postgres neo4j
"${COMPOSE[@]}" exec -T app php artisan migrate:fresh --seed --seeder=DevBoardSeeder --force
"${COMPOSE[@]}" exec -T postgres psql -U devboard -d devboard -c 'truncate table jobs restart identity; truncate table failed_jobs restart identity;'

CONTEXT_JSON="$("${COMPOSE[@]}" exec -T app sh -lc 'cd /workspace/backend && php /workspace/scripts/runtime_fault_context.php')"
IMPORT_ID="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["import_id"])' "${CONTEXT_JSON}")"
RUN_ID="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["run_id"])' "${CONTEXT_JSON}")"

"${COMPOSE[@]}" stop neo4j
"${COMPOSE[@]}" exec -T app sh -lc 'cd /workspace/backend && QUEUE_CONNECTION=database php artisan queue:work --once --timeout=30'

PHASE_ONE="$("${COMPOSE[@]}" exec -T app sh -lc "cd /workspace/backend && php /workspace/scripts/runtime_fault_status.php '${IMPORT_ID}' '${RUN_ID}'")"

for _ in 1 2 3 4; do
  "${COMPOSE[@]}" exec -T app sh -lc 'cd /workspace/backend && QUEUE_CONNECTION=database php artisan queue:work --once --timeout=30' || true
done

"${COMPOSE[@]}" start neo4j
sleep 6

PHASE_FINAL="$("${COMPOSE[@]}" exec -T app sh -lc "cd /workspace/backend && php /workspace/scripts/runtime_fault_status.php '${IMPORT_ID}' '${RUN_ID}'")"

python3 - <<'PY' "${REPORT}" "${CONTEXT_JSON}" "${PHASE_ONE}" "${PHASE_FINAL}"
import json
import sys

report_path, context_json, phase_one_json, phase_final_json = sys.argv[1:5]

report = {
    "context": json.loads(context_json),
    "phase_one": json.loads(phase_one_json),
    "phase_final": json.loads(phase_final_json),
}

with open(report_path, "w", encoding="utf-8") as handle:
    json.dump(report, handle, indent=2, sort_keys=True)
PY
```

Important implementation decision:

- do **not** inline large PHP `tinker` one-liners in the harness;
- create two helper PHP scripts in `scripts/`:
  - `scripts/runtime_fault_context.php`
  - `scripts/runtime_fault_status.php`

These should bootstrap Laravel directly and:

- `runtime_fault_context.php`: create device/workspace/run/artifact/snapshot/genesis_import rows, dispatch the queue job, and print `{"import_id": "...", "run_id": "..."}`.
- `runtime_fault_status.php`: print `{"import_status": "...", "graph_imported_event": false, "graph_import_failed_event": false, "job_rows": 1, "failed_jobs": 0}` for the passed ids.

- [ ] **Step 4: Create the PHP helper script for setup**

Create `scripts/runtime_fault_context.php` with this structure:

```php
<?php

require __DIR__.'/../backend/vendor/autoload.php';
$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

config(['queue.default' => 'database']);

// create seeded runtime context rows
// create a minimal validated graph_snapshot artifact
// create active genesis_import row
// dispatch ImportGenesisGraphToNeo4j
// echo json_encode(['import_id' => $importId, 'run_id' => $runId], JSON_THROW_ON_ERROR);
```

Use the same table shapes already exercised in `backend/tests/Feature/GenesisGraphImportTest.php`.

- [ ] **Step 5: Create the PHP helper script for status polling**

Create `scripts/runtime_fault_status.php`:

```php
<?php

require __DIR__.'/../backend/vendor/autoload.php';
$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$importId = $argv[1];
$runId = $argv[2];

echo json_encode([
    'import_status' => DB::table('genesis_imports')->where('id', $importId)->value('status'),
    'graph_imported_event' => DB::table('run_events')->where('run_id', $runId)->where('event_type', 'graph.imported')->exists(),
    'graph_import_failed_event' => DB::table('run_events')->where('run_id', $runId)->where('event_type', 'graph.import_failed')->exists(),
    'job_rows' => DB::table('jobs')->count(),
    'failed_jobs' => DB::table('failed_jobs')->count(),
], JSON_THROW_ON_ERROR);
```

- [ ] **Step 6: Re-run the new E2E test and verify GREEN**

Run:

```bash
/tmp/devboard-plugin-venv/bin/python -m pytest tests/e2e/test_queue_retry_fault.py -q
```

Expected: PASS with a report showing:

- first attempt leaves import `active`;
- final attempt leaves import `failed`;
- `graph.import_failed` exists only in the final phase;
- no queued jobs remain after retry exhaustion.

- [ ] **Step 7: Document the new harness**

Update `README.md` and `docker/devboard/README.md` with:

```md
### Queue retry fault harness

```bash
/tmp/devboard-plugin-venv/bin/python -m pytest tests/e2e/test_queue_retry_fault.py -q
scripts/devboard_queue_fault_harness.sh
```

The harness:

- seeds the Docker stack;
- queues a real `ImportGenesisGraphToNeo4j` job;
- stops Neo4j;
- verifies phase 1 (`active`, retry pending);
- exhausts retries with zero backoff;
- verifies final failure (`graph.import_failed`).
```

- [ ] **Step 8: Commit**

```bash
git add scripts/devboard_queue_fault_harness.sh scripts/runtime_fault_context.php scripts/runtime_fault_status.php tests/e2e/test_queue_retry_fault.py README.md docker/devboard/README.md
git commit -m "feat: add deterministic queue fault harness"
```

### Task 3: Add an Ubuntu x64 acceptance harness and runbook

**Files:**
- Create: `scripts/devboard_runtime_acceptance.sh`
- Create: `tests/e2e/test_runtime_acceptance.py`
- Create: `docs/runbooks/devboard-ubuntu-x64-validation.md`
- Modify: `README.md`
- Modify: `docker/devboard/README.md`

- [ ] **Step 1: Write the failing acceptance wrapper test**

Create `tests/e2e/test_runtime_acceptance.py`:

```python
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
```

- [ ] **Step 2: Run the acceptance wrapper in RED**

Run:

```bash
DEVBOARD_RUNTIME_ACCEPTANCE=1 /tmp/devboard-plugin-venv/bin/python -m pytest tests/e2e/test_runtime_acceptance.py -q
```

Expected: FAIL because `scripts/devboard_runtime_acceptance.sh` does not exist.

- [ ] **Step 3: Create the Ubuntu acceptance script**

Create `scripts/devboard_runtime_acceptance.sh` with this structure:

```bash
#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPORT="${DEVBOARD_RUNTIME_ACCEPTANCE_REPORT:-/tmp/devboard-runtime-acceptance-report.json}"
APP_PORT="${DEVBOARD_APP_PORT:-8000}"
VITE_PORT="${DEVBOARD_VITE_PORT:-5173}"
POSTGRES_PORT="${DEVBOARD_POSTGRES_PORT:-5432}"
NEO4J_HTTP_PORT="${DEVBOARD_NEO4J_HTTP_PORT:-7474}"
NEO4J_BOLT_PORT="${DEVBOARD_NEO4J_BOLT_PORT:-7687}"

HOST_OS="$(uname -s)"
HOST_ARCH="$(uname -m)"
DOCKER_PLATFORM="$(docker info --format '{{.OSType}}/{{.Architecture}}')"

case "${HOST_OS}" in
  Linux) ;;
  *) echo "This harness is for Linux hosts only." >&2; exit 1 ;;
esac

case "${HOST_ARCH}" in
  x86_64|amd64) ;;
  *) echo "This harness is for x64 hosts only." >&2; exit 1 ;;
esac

docker compose -f "${ROOT}/docker-compose.devboard.yaml" config >/dev/null
docker compose -f "${ROOT}/docker-compose.devboard.yaml" up -d app node postgres neo4j
docker compose -f "${ROOT}/docker-compose.devboard.yaml" exec -T app php artisan migrate:fresh --seed --seeder=DevBoardSeeder --force

if [[ ! -x /tmp/devboard-plugin-venv/bin/python ]]; then
  python3 -m venv /tmp/devboard-plugin-venv
  /tmp/devboard-plugin-venv/bin/python -m pip install -e "${ROOT}/analyzer" -e "${ROOT}/plugin" pytest
fi

curl -fsS "http://127.0.0.1:${APP_PORT}/up" >/dev/null
docker compose -f "${ROOT}/docker-compose.devboard.yaml" exec -T postgres psql -U devboard -d devboard -c 'select 1 as ok;' >/tmp/devboard-postgres-ok.txt
docker compose -f "${ROOT}/docker-compose.devboard.yaml" exec -T neo4j cypher-shell -u neo4j -p graphify-sandbox 'RETURN 1 AS ok' >/tmp/devboard-neo4j-ok.txt
/tmp/devboard-plugin-venv/bin/python -m pytest tests/e2e/test_onboarding_genesis.py -q >/tmp/devboard-runtime-e2e.txt

python3 - <<'PY' "${REPORT}" "${HOST_OS}" "${HOST_ARCH}" "${DOCKER_PLATFORM}"
import json
import pathlib
import sys

report_path, host_os, host_arch, docker_platform = sys.argv[1:5]
docker_os, docker_arch = docker_platform.split("/", 1)

report = {
    "host": {"os": host_os, "arch": host_arch},
    "docker": {"os": docker_os, "arch": docker_arch},
    "services": {
        "app_up": True,
        "postgres_ok": True,
        "neo4j_ok": True,
    },
    "e2e": {
        "onboarding_genesis_passed": "1 passed" in pathlib.Path("/tmp/devboard-runtime-e2e.txt").read_text(),
    },
}

pathlib.Path(report_path).write_text(json.dumps(report, indent=2, sort_keys=True))
PY
```

- [ ] **Step 4: Run the acceptance wrapper in GREEN on Ubuntu x64**

Run:

```bash
DEVBOARD_RUNTIME_ACCEPTANCE=1 /tmp/devboard-plugin-venv/bin/python -m pytest tests/e2e/test_runtime_acceptance.py -q
```

Expected: PASS on Ubuntu x64.  
Expected on macOS/Apple Silicon: SKIP or explicit non-Linux/non-x64 failure, depending on how you invoke it.

- [ ] **Step 5: Write the operator runbook**

Create `docs/runbooks/devboard-ubuntu-x64-validation.md` with these sections:

```md
# DevBoard Ubuntu x64 Runtime Validation

## Purpose

This runbook proves the DevBoard V1 stack on a real Linux x64 host rather than only validating Docker config from macOS.

## Host prerequisites

- Ubuntu 24.04 LTS or equivalent
- Docker Engine + Compose plugin
- Python 3.11+
- Git

## Repo bootstrap

```bash
git clone <repo-url>
cd ai-sandbox-framework
python3 -m venv /tmp/devboard-plugin-venv
/tmp/devboard-plugin-venv/bin/python -m pip install -e analyzer -e plugin pytest
```

## Full acceptance

```bash
scripts/devboard_runtime_acceptance.sh
cat /tmp/devboard-runtime-acceptance-report.json
```

## Expected success signals

- app `/up` returns 200
- Postgres returns `select 1`
- Neo4j returns `RETURN 1`
- onboarding Genesis E2E passes
- report shows `Linux` + `x86_64|amd64`
```

- [ ] **Step 6: Update top-level docs**

Update `README.md` and `docker/devboard/README.md` to add:

```md
### Ubuntu x64 acceptance

```bash
DEVBOARD_RUNTIME_ACCEPTANCE=1 /tmp/devboard-plugin-venv/bin/python -m pytest tests/e2e/test_runtime_acceptance.py -q
scripts/devboard_runtime_acceptance.sh
```

Use this only on a real Linux x64 host. It is the acceptance path that closes the remaining runtime validation gap left by local macOS Docker checks.
```

- [ ] **Step 7: Commit**

```bash
git add scripts/devboard_runtime_acceptance.sh tests/e2e/test_runtime_acceptance.py docs/runbooks/devboard-ubuntu-x64-validation.md README.md docker/devboard/README.md
git commit -m "feat: add ubuntu runtime acceptance harness"
```

## Final Verification

- [ ] **Step 1: Run the backend verification suite**

```bash
cd backend
php artisan test
```

Expected: PASS.

- [ ] **Step 2: Run plugin and analyzer suites**

```bash
cd /Users/gabriele/Dev/ai-sandbox-framework/plugin
/tmp/devboard-plugin-venv/bin/python -m pytest -q

cd /Users/gabriele/Dev/ai-sandbox-framework/analyzer
/tmp/devboard-plugin-venv/bin/python -m pytest -q
```

Expected: PASS.

- [ ] **Step 3: Run all E2E runtime wrappers**

```bash
cd /Users/gabriele/Dev/ai-sandbox-framework
/tmp/devboard-plugin-venv/bin/python -m pytest tests/e2e/test_onboarding_genesis.py -q
/tmp/devboard-plugin-venv/bin/python -m pytest tests/e2e/test_queue_retry_fault.py -q
DEVBOARD_RUNTIME_ACCEPTANCE=1 /tmp/devboard-plugin-venv/bin/python -m pytest tests/e2e/test_runtime_acceptance.py -q
```

Expected:

- onboarding Genesis passes anywhere Docker local stack works;
- queue fault harness passes anywhere Docker local stack works;
- Ubuntu runtime acceptance passes only on a real Linux x64 host.

- [ ] **Step 4: Run diff hygiene**

```bash
cd /Users/gabriele/Dev/ai-sandbox-framework
git diff --check
```

Expected: no whitespace or merge-marker issues.

## Completion Criteria

This residual is complete only when all of the following are true:

- the queue fault harness proves `active` after the first Neo4j failure and `failed + graph.import_failed` after retry exhaustion;
- the behavior is captured in a machine-readable JSON report and asserted by pytest;
- the Ubuntu x64 acceptance script passes on a real Linux x64 host;
- the Ubuntu host runbook is accurate enough that a fresh AI or engineer can execute it without reconstructing tribal knowledge from chat history.
