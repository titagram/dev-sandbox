#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG="$(mktemp)"
trap 'rm -f "$CONFIG"' EXIT

APP_KEY='base64:test' \
DB_PASSWORD='test-db-password' \
NEO4J_PASSWORD='test-neo4j-password' \
DEVBOARD_TRAEFIK_HOST='home-sweet-home.cloud' \
DEVBOARD_TRAEFIK_BASIC_AUTH_USERS='test:test' \
docker compose \
  -f "$ROOT/docker-compose.devboard.yaml" \
  -f "$ROOT/docker-compose.devboard.traefik.yaml" \
  config --format json >"$CONFIG"

jq -e '
  (.services | keys | sort) == ["app", "frontend", "neo4j", "postgres", "scheduler", "worker"] and
  (.services | has("traefik") | not) and
  .networks.traefik.external == true and
  .networks.traefik.name == "traefik_default" and
  (.services.app.labels["traefik.http.routers.devboard-web.rule"] | contains("home-sweet-home.cloud")) and
  (.services.frontend.labels["traefik.http.routers.devboard-frontend.rule"] | contains("home-sweet-home.cloud")) and
  (.services.app.healthcheck.test != null) and
  (.services.app.command | tostring | contains("safe.directory /workspace")) and
  ([.services.app.volumes[] | select(.type == "volume" and .target == "/workspace/backend/vendor")] | length) == 1 and
  ([.services.worker.volumes[] | select(.type == "volume" and .target == "/workspace/backend/vendor")] | length) == 1 and
  ([.services.scheduler.volumes[] | select(.type == "volume" and .target == "/workspace/backend/vendor")] | length) == 1 and
  (.services.frontend.healthcheck.test != null) and
  .services.frontend.depends_on.app.condition == "service_healthy" and
  .services.worker.depends_on.app.condition == "service_healthy" and
  .services.scheduler.depends_on.app.condition == "service_healthy" and
  ((.services.worker.command | tostring | contains("composer install")) | not) and
  ((.services.scheduler.command | tostring | contains("composer install")) | not)
' "$CONFIG" >/dev/null

printf 'Hades Compose contract: PASS\n'
