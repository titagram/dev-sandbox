#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPORT="${DEVBOARD_RUNTIME_ACCEPTANCE_REPORT:-/tmp/devboard-runtime-acceptance-report.json}"
APP_PORT="${DEVBOARD_APP_PORT:-28000}"
PROJECT_NAME="${DEVBOARD_RUNTIME_ACCEPTANCE_PROJECT:-devboard-runtime-acceptance}"

if [[ "${DEVBOARD_RUNTIME_ACCEPTANCE:-0}" != "1" ]]; then
  echo "Refusing destructive acceptance run. Set DEVBOARD_RUNTIME_ACCEPTANCE=1." >&2
  exit 2
fi

: "${APP_KEY:=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=}"
: "${DB_PASSWORD:=runtime-acceptance-db}"
: "${NEO4J_PASSWORD:=runtime-acceptance-neo4j}"
export APP_KEY DB_PASSWORD NEO4J_PASSWORD
export DEVBOARD_APP_BIND="127.0.0.1"
export DEVBOARD_APP_PORT="${APP_PORT}"
export DEVBOARD_VITE_BIND="127.0.0.1"
export DEVBOARD_VITE_PORT="${DEVBOARD_VITE_PORT:-25173}"
export DEVBOARD_POSTGRES_BIND="127.0.0.1"
export DEVBOARD_POSTGRES_PORT="${DEVBOARD_POSTGRES_PORT:-25432}"
export DEVBOARD_NEO4J_BIND="127.0.0.1"
export DEVBOARD_NEO4J_HTTP_PORT="${DEVBOARD_NEO4J_HTTP_PORT:-27474}"
export DEVBOARD_NEO4J_BOLT_PORT="${DEVBOARD_NEO4J_BOLT_PORT:-27687}"
COMPOSE=(docker compose -p "${PROJECT_NAME}" -f "${ROOT}/docker-compose.devboard.yaml")

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

cleanup() {
  "${COMPOSE[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

wait_for_app() {
  local attempt=0

  while (( attempt < 80 )); do
    attempt=$((attempt + 1))
    if curl -fsS "http://127.0.0.1:${APP_PORT}/up" >/dev/null; then
      return 0
    fi
    sleep 0.25
  done

  return 1
}

"${COMPOSE[@]}" config >/dev/null
"${COMPOSE[@]}" up -d app worker scheduler postgres neo4j
"${COMPOSE[@]}" exec -T app php artisan migrate:fresh --seed --seeder=DatabaseSeeder --force

if [[ ! -x /tmp/devboard-plugin-venv/bin/python ]]; then
  python3 -m venv /tmp/devboard-plugin-venv
  /tmp/devboard-plugin-venv/bin/python -m pip install -e "${ROOT}/analyzer" -e "${ROOT}/plugin" pytest
fi

if ! wait_for_app; then
  echo "Timed out waiting for application readiness at /up" >&2
  exit 1
fi
"${COMPOSE[@]}" exec -T postgres psql -U devboard -d devboard -c 'select 1 as ok;' >/tmp/devboard-postgres-ok.txt
"${COMPOSE[@]}" exec -T neo4j cypher-shell -u neo4j -p "${NEO4J_PASSWORD}" 'RETURN 1 AS ok' >/tmp/devboard-neo4j-ok.txt
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
