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
