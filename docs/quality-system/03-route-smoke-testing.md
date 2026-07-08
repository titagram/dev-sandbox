# Route Smoke Testing

## Purpose

- `developer_provided`: Route smoke should support domain-truth quality checks, not just prove that a route returns HTTP 200.
- `inferred`: The first route smoke layer should detect unexpected server errors, unexpected statuses, missing route registry entries, and missing parameter providers.
- `inferred`: Route smoke should produce machine-readable findings that quality gates can consume.

## Route Inventory First

- `verified_from_code`: Laravel route metadata is available through `php artisan route:list --except-vendor` and through Laravel's router collection.
- `inferred`: `quality:route-inventory` should inspect route names, URIs, methods, controller actions, parameters, middleware, classification, configured state, and warnings without making HTTP requests.
- `inferred`: Inventory reports should be generated before smoke execution so gaps are visible without side effects.

## Classification

- `inferred`: Initial route classifications should include `SAFE_READ`, `MUTATING`, `DESTRUCTIVE`, `EXTERNAL_SIDE_EFFECT`, `AUTH`, and `UNKNOWN`.
- `inferred`: Only `SAFE_READ` configured routes should run by default.
- `developer_provided`: Mutating route scans require explicit confirmation.
- `developer_provided`: Destructive scans must require explicit human approval and must be disabled unless the API says they are allowed.

## Actor Policy

- `inferred`: Guest route smoke can be the first deterministic actor because it does not require seeded dashboard users.
- `inferred`: User, admin, developer, PM, and sysadmin smoke should wait until deterministic test actors and policy expectations are defined.
- `inferred`: Unknown actor coverage should be reported as a warning rather than silently skipped.

## Parameterized Routes

- `inferred`: Parameterized routes require explicit parameter providers in the route registry.
- `inferred`: A parameterized route without a provider should be skipped with a `missing_parameter_provider` warning.
- `inferred`: Providers must use test-safe identifiers from seeded or fixture data, not production records.

## Report Output

- `inferred`: Route smoke should write JSON and Markdown under `backend/var/quality/reports/`.
- `inferred`: Findings should include stable ids, severity, type, route name, expected behavior, actual result, and evidence.
- `inferred`: 5xx responses should be failures for routes that were safe and configured to run.

