#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKDIR="${DEVBOARD_E2E_WORKDIR:-/tmp/devboard-e2e}"
REPORT="${DEVBOARD_E2E_REPORT:-${WORKDIR}/report.json}"
PORT="${DEVBOARD_E2E_PORT:-8091}"
SERVER_URL="http://127.0.0.1:${PORT}"
DB_PATH="${WORKDIR}/devboard-e2e.sqlite"
VENV="${WORKDIR}/venv"
REPO_PATH="${WORKDIR}/repo"
SEED_PATH="${WORKDIR}/seed.json"
SERVER_LOG="${WORKDIR}/laravel-server.log"
export DEVBOARD_CREDENTIALS_PATH="${WORKDIR}/credentials.json"

SERVER_PID=""

cleanup() {
  if [[ -n "${SERVER_PID}" ]] && kill -0 "${SERVER_PID}" >/dev/null 2>&1; then
    kill "${SERVER_PID}" >/dev/null 2>&1 || true
    wait "${SERVER_PID}" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

mkdir -p "${WORKDIR}"
rm -f "${DB_PATH}" "${REPORT}" "${SEED_PATH}" "${DEVBOARD_CREDENTIALS_PATH}"
rm -rf "${REPO_PATH}"
touch "${DB_PATH}"

if [[ ! -f "${ROOT}/backend/vendor/autoload.php" ]]; then
  (cd "${ROOT}/backend" && composer install --quiet)
fi

if [[ ! -x "${VENV}/bin/python" ]]; then
  python3 -m venv "${VENV}"
fi

"${VENV}/bin/python" -m pip install -q -e "${ROOT}/analyzer" -e "${ROOT}/plugin" pytest

(
  cd "${ROOT}/backend"
  DB_CONNECTION=sqlite DB_DATABASE="${DB_PATH}" php artisan migrate:fresh --seed --seeder=DevBoardSeeder --force
)

"${VENV}/bin/python" - "${DB_PATH}" "${SEED_PATH}" <<'PY'
from __future__ import annotations

import hashlib
import json
import secrets
import sqlite3
import sys
import time
from datetime import datetime, timedelta, timezone

CROCKFORD = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"


def ulid() -> str:
    value = (int(time.time() * 1000) << 80) | secrets.randbits(80)
    return "".join(CROCKFORD[(value >> shift) & 31] for shift in range(125, -1, -5))


db_path, seed_path = sys.argv[1], sys.argv[2]
conn = sqlite3.connect(db_path)
conn.row_factory = sqlite3.Row

admin = conn.execute("select id, email from users where email = ?", ("admin@example.com",)).fetchone()
project = conn.execute("select id, slug from projects where slug = ?", ("demo-project",)).fetchone()
repository = conn.execute("select id, slug from repositories where slug = ?", ("demo-repository",)).fetchone()

token_id = ulid()
secret = secrets.token_urlsafe(36)
prefix = f"devb_live_{token_id}"
plain_token = f"{prefix}|{secret}"
now = datetime.now(timezone.utc)
expires_at = now + timedelta(days=1)
scopes = [
    "projects.read",
    "repositories.read",
    "policies.read",
    "runs.write",
    "artifacts.write",
    "wiki.write",
]

conn.execute(
    """
    insert into api_tokens (
      id, token_prefix, token_hash, user_id, device_id, name, scopes,
      expires_at, revoked_at, last_used_at, created_at, updated_at
    ) values (?, ?, ?, ?, null, ?, ?, ?, null, null, ?, ?)
    """,
    (
        token_id,
        prefix,
        hashlib.sha256(secret.encode()).hexdigest(),
        admin["id"],
        "E2E plugin token",
        json.dumps(scopes),
        expires_at.isoformat(),
        now.isoformat(),
        now.isoformat(),
    ),
)
conn.commit()

with open(seed_path, "w", encoding="utf-8") as handle:
    json.dump(
        {
            "admin_email": admin["email"],
            "admin_password": "password",
            "project_id": project["id"],
            "repository_id": repository["id"],
            "token": plain_token,
        },
        handle,
    )
PY

cp -R "${ROOT}/fixtures/repos/simple-python" "${REPO_PATH}"

(
  cd "${ROOT}/backend"
  DB_CONNECTION=sqlite \
  DB_DATABASE="${DB_PATH}" \
  APP_URL="${SERVER_URL}" \
  QUEUE_CONNECTION=sync \
  php artisan serve --host=127.0.0.1 --port="${PORT}" >"${SERVER_LOG}" 2>&1
) &
SERVER_PID="$!"

for _ in {1..80}; do
  if curl -fsS "${SERVER_URL}/up" >/dev/null 2>&1; then
    break
  fi
  sleep 0.25
done

if ! kill -0 "${SERVER_PID}" >/dev/null 2>&1; then
  cat "${SERVER_LOG}" >&2 || true
  exit 1
fi

"${VENV}/bin/python" - "${ROOT}" "${SERVER_URL}" "${DB_PATH}" "${SEED_PATH}" "${REPO_PATH}" "${REPORT}" <<'PY'
from __future__ import annotations

import json
import os
import re
import sqlite3
import subprocess
import sys
from pathlib import Path

import httpx

from devboard_plugin.client import DevBoardClient

root = Path(sys.argv[1])
server_url = sys.argv[2]
db_path = sys.argv[3]
seed = json.loads(Path(sys.argv[4]).read_text())
repo_path = Path(sys.argv[5])
report_path = Path(sys.argv[6])
env = os.environ.copy()
bin_path = Path(env.get("VIRTUAL_ENV", "")) / "bin" / "devboard"
if not bin_path.exists():
    bin_path = Path(sys.executable).parent / "devboard"


def run_devboard(args: list[str], cwd: Path | None = None) -> dict:
    result = subprocess.run(
        [str(bin_path), *args],
        cwd=cwd or root,
        env=env,
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode != 0:
        raise RuntimeError(result.stdout + result.stderr)
    return json.loads(result.stdout)


auth_check = run_devboard([
    "auth",
    "check",
    "--server-url",
    server_url,
    "--token",
    seed["token"],
])
device = run_devboard([
    "auth",
    "register-device",
    "E2E Device",
    "sha256:e2e-device",
    "darwin",
    "arm64",
    "--server-url",
    server_url,
    "--token",
    seed["token"],
])

projects = run_devboard(["projects", "list"])
client = DevBoardClient(base_url=server_url, token=seed["token"], device_id=device["device_id"])
repositories = client.list_repositories(seed["project_id"])
repository_id = repositories["repositories"][0]["repository_id"]

local_workspace = run_devboard([
    "repos",
    "link",
    seed["project_id"],
    repository_id,
    "--repo-path",
    str(repo_path),
])

genesis = run_devboard([
    "genesis",
    "run",
    "--project-id",
    seed["project_id"],
    "--repository-id",
    repository_id,
    "--local-workspace-id",
    local_workspace["local_workspace_id"],
    "--repo-path",
    str(repo_path),
])

upload = run_devboard(["artifacts", "upload", "--genesis", "--repo-path", str(repo_path)])

state_path = repo_path / ".devboard" / "state.json"
state_text = state_path.read_text()
state = json.loads(state_text)

with sqlite3.connect(db_path) as conn:
    conn.row_factory = sqlite3.Row
    active_genesis = conn.execute(
        "select id, status from genesis_imports where repository_id = ? order by created_at desc limit 1",
        (repository_id,),
    ).fetchone()
    graph_artifact = conn.execute(
        "select status from artifacts where repository_id = ? and artifact_type = 'graph_snapshot' order by created_at desc limit 1",
        (repository_id,),
    ).fetchone()

dashboard_project_ok = False
dashboard_repository_initialized = False
with httpx.Client(base_url=server_url, follow_redirects=True) as web:
    login_page = web.get("/login")
    csrf_match = re.search(r'name="csrf-token" content="([^"]+)"', login_page.text)
    csrf = csrf_match.group(1) if csrf_match else ""
    web.post(
        "/login",
        data={"email": seed["admin_email"], "password": seed["admin_password"]},
        headers={"X-CSRF-TOKEN": csrf},
    )
    project_response = web.get(
        f"/projects/{seed['project_id']}",
        headers={"X-Inertia": "true", "Accept": "application/json"},
    )
    dashboard_project_ok = project_response.status_code == 200
    if dashboard_project_ok:
        payload = project_response.json()
        repositories_payload = payload.get("props", {}).get("repositories", [])
        dashboard_repository_initialized = any(
            repo.get("repository_id") == repository_id and repo.get("genesis_status") == "active"
            for repo in repositories_payload
        )

report = {
    "auth_check": auth_check,
    "device": device,
    "projects": projects,
    "local_workspace": local_workspace,
    "repo_state": {
        "exists": state_path.exists(),
        "contains_secret": seed["token"] in state_text or "plain_token" in state_text,
        "path": str(state_path),
        "run_id": state.get("run_id"),
    },
    "genesis": {
        "run_id": genesis["run_id"],
        "bundle_status": genesis["status"],
        "upload_status": upload["status"],
        "status": active_genesis["status"] if active_genesis else None,
    },
    "graph": {
        "status": "fake-imported" if graph_artifact and graph_artifact["status"] == "imported" else "missing",
    },
    "dashboard": {
        "project_response_ok": dashboard_project_ok,
        "repository_initialized": dashboard_repository_initialized,
    },
}

report_path.parent.mkdir(parents=True, exist_ok=True)
report_path.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n")
PY
