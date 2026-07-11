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
DEVBOARD_RUNTIME_ACCEPTANCE=1 scripts/devboard_runtime_acceptance.sh
cat /tmp/devboard-runtime-acceptance-report.json
```

The harness uses a dedicated Compose project and removes only its isolated volumes. It refuses to run without the explicit acceptance flag.

## Expected success signals

- app `/up` returns 200
- Postgres returns `select 1`
- Neo4j returns `RETURN 1`
- onboarding Genesis E2E passes
- report shows `Linux` + `x86_64|amd64`
