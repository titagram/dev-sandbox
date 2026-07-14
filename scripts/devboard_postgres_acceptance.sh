#!/usr/bin/env bash
set -euo pipefail

usage() {
  printf '%s\n' "Usage: DEVBOARD_POSTGRES_ACCEPTANCE=1 $0 [PHPUnit arguments...]"
  printf '%s\n' "Example: DEVBOARD_POSTGRES_ACCEPTANCE=1 $0 --filter=AuditLoggerConcurrencyTest"
}

if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
  usage
  exit 0
fi

if [[ "${DEVBOARD_POSTGRES_ACCEPTANCE:-}" != "1" ]]; then
  printf '%s\n' "Refusing to run destructive PostgreSQL acceptance cleanup without DEVBOARD_POSTGRES_ACCEPTANCE=1." >&2
  exit 1
fi

phpunit_args=()
for arg in "$@"; do
  case "$arg" in
    --script-*)
      printf 'Unknown script-only flag: %s\n' "$arg" >&2
      exit 2
      ;;
    *)
      phpunit_args+=("$arg")
      ;;
  esac
done

workspace_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
project="devboard-postgres-acceptance-$(date +%s)-$$"
tmp_dir="$(mktemp -d)"
compose_file="$tmp_dir/compose.yaml"
export APP_KEY="base64:$(openssl rand -base64 32 | tr -d '\n')"

cleanup() {
  docker compose -p "$project" -f "$compose_file" down -v --remove-orphans >/dev/null 2>&1 || true
  rm -rf "$tmp_dir"
}
trap cleanup EXIT

cat >"$compose_file" <<'YAML'
services:
  app:
    build:
      context: .
      dockerfile: docker/devboard/backend.Dockerfile
    working_dir: /workspace/backend
    environment:
      APP_ENV: testing
      APP_KEY: ${APP_KEY:?APP_KEY is required}
      DB_CONNECTION: pgsql
      DB_HOST: postgres
      DB_PORT: "5432"
      DB_DATABASE: devboard_acceptance
      DB_USERNAME: devboard_acceptance
      DB_PASSWORD: devboard_acceptance_password
      DB_URL: ""
      CACHE_STORE: array
      QUEUE_CONNECTION: sync
      SESSION_DRIVER: array
    volumes:
      - .:/workspace
      - composer-cache:/tmp/composer-cache
    depends_on:
      postgres:
        condition: service_healthy

  postgres:
    image: pgvector/pgvector:pg16@sha256:1d533553fefe4f12e5d80c7b80622ba0c382abb5758856f52983d8789179f0fb
    environment:
      POSTGRES_DB: devboard_acceptance
      POSTGRES_USER: devboard_acceptance
      POSTGRES_PASSWORD: devboard_acceptance_password
    volumes:
      - postgres-acceptance-data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U devboard_acceptance -d devboard_acceptance"]
      interval: 5s
      timeout: 5s
      retries: 20

volumes:
  composer-cache:
  postgres-acceptance-data:
YAML

docker compose -p "$project" -f "$compose_file" --project-directory "$workspace_root" up -d postgres
docker compose -p "$project" -f "$compose_file" --project-directory "$workspace_root" run --rm app sh -lc \
  'if [ ! -f vendor/autoload.php ]; then composer install --no-interaction --prefer-dist --no-progress; fi && bash scripts/run_postgres_acceptance.sh "$@"' \
  sh "${phpunit_args[@]}"
