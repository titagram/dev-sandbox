#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPORT="${DEVBOARD_QUEUE_FAULT_REPORT:-/tmp/devboard-queue-fault-report.json}"
COMPOSE=(docker compose -f "${ROOT}/docker-compose.devboard.yaml")
JOB_TRIES="${DEVBOARD_GRAPH_IMPORT_JOB_TRIES:-3}"
JOB_BACKOFF="${DEVBOARD_GRAPH_IMPORT_JOB_BACKOFF_SECONDS:-0,0,0}"

cleanup() {
  "${COMPOSE[@]}" start neo4j >/dev/null 2>&1 || true
}
trap cleanup EXIT

"${COMPOSE[@]}" up -d app postgres neo4j
"${COMPOSE[@]}" exec -T app php artisan migrate:fresh --seed --seeder=DevBoardSeeder --force
"${COMPOSE[@]}" exec -T postgres psql -U devboard -d devboard -c 'truncate table jobs restart identity; truncate table failed_jobs restart identity;'

CONTEXT_JSON="$("${COMPOSE[@]}" exec -T -e DEVBOARD_GRAPH_IMPORT_JOB_TRIES="${JOB_TRIES}" -e DEVBOARD_GRAPH_IMPORT_JOB_BACKOFF_SECONDS="${JOB_BACKOFF}" app sh -lc 'cd /workspace/backend && php /workspace/scripts/runtime_fault_context.php')"
IMPORT_ID="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["import_id"])' "${CONTEXT_JSON}")"
RUN_ID="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["run_id"])' "${CONTEXT_JSON}")"

"${COMPOSE[@]}" stop neo4j
"${COMPOSE[@]}" exec -T -e DEVBOARD_GRAPH_IMPORT_JOB_TRIES="${JOB_TRIES}" -e DEVBOARD_GRAPH_IMPORT_JOB_BACKOFF_SECONDS="${JOB_BACKOFF}" app sh -lc 'cd /workspace/backend && QUEUE_CONNECTION=database php artisan queue:work --once --timeout=30'

PHASE_ONE="$("${COMPOSE[@]}" exec -T app sh -lc "cd /workspace/backend && php /workspace/scripts/runtime_fault_status.php '${IMPORT_ID}' '${RUN_ID}'")"

for _ in $(seq 1 $((JOB_TRIES + 1))); do
  "${COMPOSE[@]}" exec -T -e DEVBOARD_GRAPH_IMPORT_JOB_TRIES="${JOB_TRIES}" -e DEVBOARD_GRAPH_IMPORT_JOB_BACKOFF_SECONDS="${JOB_BACKOFF}" app sh -lc 'cd /workspace/backend && QUEUE_CONNECTION=database php artisan queue:work --once --timeout=30' || true
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
