#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
JOB_TRIES="${DEVBOARD_GRAPH_IMPORT_JOB_TRIES:-3}"
JOB_BACKOFF="${DEVBOARD_GRAPH_IMPORT_JOB_BACKOFF_SECONDS:-0,0,0}"

if [[ "${DEVBOARD_QUEUE_FAULT_ACCEPTANCE:-0}" != "1" ]]; then
  echo "Refusing destructive fault run. Set DEVBOARD_QUEUE_FAULT_ACCEPTANCE=1." >&2
  exit 2
fi

if [[ -n "${DEVBOARD_QUEUE_FAULT_PROJECT:-}" ]]; then
  if [[ "${DEVBOARD_QUEUE_FAULT_ALLOW_PROJECT_OVERRIDE:-0}" != "1" ]]; then
    echo "Refusing custom Compose project. Set DEVBOARD_QUEUE_FAULT_ALLOW_PROJECT_OVERRIDE=1." >&2
    exit 2
  fi
  PROJECT_NAME="${DEVBOARD_QUEUE_FAULT_PROJECT}"
else
  PROJECT_NAME="devboard-queue-fault-${UID:-0}-$(date -u +%Y%m%d%H%M%S)-$$"
fi

if [[ ! "${PROJECT_NAME}" =~ ^[a-z0-9][a-z0-9_-]*$ ]]; then
  echo "Invalid Compose project name: ${PROJECT_NAME}" >&2
  exit 2
fi

REPORT="${DEVBOARD_QUEUE_FAULT_REPORT:-/tmp/${PROJECT_NAME}-report.json}"
LOCK_DIR="/tmp/${PROJECT_NAME}.lock"

if ! mkdir "${LOCK_DIR}" 2>/dev/null; then
  echo "Compose project is already reserved by another harness: ${PROJECT_NAME}" >&2
  exit 2
fi

release_lock() {
  rmdir "${LOCK_DIR}" >/dev/null 2>&1 || true
}
trap release_lock EXIT

project_has_resources() {
  [[ -n "$(docker ps -aq --filter "label=com.docker.compose.project=${PROJECT_NAME}")" ]] \
    || [[ -n "$(docker volume ls -q --filter "label=com.docker.compose.project=${PROJECT_NAME}")" ]] \
    || [[ -n "$(docker network ls -q --filter "label=com.docker.compose.project=${PROJECT_NAME}")" ]]
}

if project_has_resources && [[ "${DEVBOARD_QUEUE_FAULT_ALLOW_PROJECT_REUSE:-0}" != "1" ]]; then
  echo "Compose project already has Docker resources: ${PROJECT_NAME}. Set DEVBOARD_QUEUE_FAULT_ALLOW_PROJECT_REUSE=1 to reuse and delete them." >&2
  exit 2
fi

: "${APP_KEY:=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=}"
: "${DB_PASSWORD:=queue-fault-db}"
: "${NEO4J_PASSWORD:=queue-fault-neo4j}"
export APP_KEY DB_PASSWORD NEO4J_PASSWORD
export DEVBOARD_APP_BIND="127.0.0.1"
export DEVBOARD_APP_PORT="${DEVBOARD_APP_PORT:-38000}"
export DEVBOARD_VITE_BIND="127.0.0.1"
export DEVBOARD_VITE_PORT="${DEVBOARD_VITE_PORT:-35173}"
export DEVBOARD_POSTGRES_BIND="127.0.0.1"
export DEVBOARD_POSTGRES_PORT="${DEVBOARD_POSTGRES_PORT:-35432}"
export DEVBOARD_NEO4J_BIND="127.0.0.1"
export DEVBOARD_NEO4J_HTTP_PORT="${DEVBOARD_NEO4J_HTTP_PORT:-37474}"
export DEVBOARD_NEO4J_BOLT_PORT="${DEVBOARD_NEO4J_BOLT_PORT:-37687}"
COMPOSE=(docker compose -p "${PROJECT_NAME}" -f "${ROOT}/docker-compose.devboard.yaml")

cleanup() {
  "${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
  release_lock
}
trap cleanup EXIT

"${COMPOSE[@]}" up -d app postgres neo4j
"${COMPOSE[@]}" exec -T app php artisan migrate:fresh --seed --seeder=DatabaseSeeder --force
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
