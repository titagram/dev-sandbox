# Audit

## 2026-07-09 Security And Readiness Remediation

Status: `verified_from_code`.

- Production seed is structural only; demo users and known passwords are isolated in `DemoDevBoardSeeder` and are not run in production.
- First production administrator creation uses the one-shot `devboard:bootstrap-admin` command with hidden password prompts.
- Dashboard page authorization and active-user enforcement are server-side.
- Plugin run writes enforce user/device ownership and cross-entity project/repository invariants.
- Genesis and Delta finalize paths are transactional, locked, and idempotent.
- Python MCP credentials are origin-bound; artifact paths and local state writes are confined and symlink-safe.
- Python and Node clients persist the one-time device secret outside repositories and sign device-bound requests.
- Hades jobs enforce effective capabilities, confirmation state, legal transitions, atomic claims, bounded artifact decompression, project lifecycle, and expanded privacy deletion/export.
- Production Compose includes app, worker, and scheduler; Traefik is a production-only overlay; local service ports bind to loopback by default.
- CI validates backend, Python, Node, frontend, dependency audits, Compose, and the production image.

- Verification baseline: Laravel `629 passed`, Python analyzer+plugin suite `122 passed`, Node agent suite passed, PHPStan level 1 clean, production image/frontend build clean, Composer/npm audits clean, and all required Compose combinations valid. PostgreSQL-only full-text/vector assertions are validated through the dedicated `DEVBOARD_POSTGRES_ACCEPTANCE=1 scripts/devboard_postgres_acceptance.sh` lane; no acceptance skips were observed in that lane.
- GitHub Actions evidence is intentionally deferred for this closure pass; this audit reflects local acceptance evidence and documentation reconciliation.
