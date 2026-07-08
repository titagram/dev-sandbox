# Quality Gates

## Gate Purpose

- `developer_provided`: Quality gates should be deterministic and safe for AI-assisted workflows.
- `inferred`: Gates should consume existing report JSON and decide pass/fail without rerunning scanners or route smoke implicitly.
- `inferred`: Gate reports should be written as JSON and Markdown so humans and automation can read the same decision.

## Initial Gate

- `inferred`: The first gate should be `pull_request`.
- `inferred`: Later gates can include `nightly` and `release` once scanner and browser coverage are explicit.
- `inferred`: A gate should fail closed when required report files are missing and the gate policy marks them required.

## Blocking Findings

- `inferred`: `route_5xx` should fail the pull request gate when found in safe route smoke output.
- `inferred`: High or critical `unexpected_status` findings should fail the pull request gate.
- `inferred`: High or critical package audit findings should fail the pull request gate when composer audit ingestion exists.
- `inferred`: `secret_detected` should fail the pull request gate when validated or produced by a trusted configured scanner.

## Warnings

- `inferred`: `missing_config` should warn until route registry coverage is required by policy.
- `inferred`: `missing_parameter_provider` should warn until a route is declared required for smoke coverage.
- `inferred`: `missing_setup` should warn for optional scanners that are documented but disabled.

## Exit Codes

- `inferred`: Gate command exit code should be `0` on pass and `1` on fail.
- `inferred`: Warnings alone should not fail a gate unless the gate config explicitly promotes them.
- `inferred`: Invalid config should fail with a clear setup error rather than producing a pass report.

## Dashboard API

- `verified_from_code`: `/api/dashboard/quality/gates/{gate}` exposes the last generated gate report as the frontend `QualityGate` shape, with blocking findings, warnings, and human approval requirements separated for display.
- `verified_from_code`: `POST /api/dashboard/quality/runs` can evaluate `quality-gate`, `quality-gate:pull_request`, `quality-gate:nightly`, and `quality-gate:release` for Admin/Sysadmin dashboard users only.
- `verified_from_code`: Gate evaluation through the dashboard API returns the generated report even when the underlying Artisan command exits non-zero for a failing gate, so the UI can display the failure evidence.
- `developer_provided`: The dashboard API must not invoke `/api/plugin/v1`; plugin CLI/MCP execution remains separate from browser operations.
