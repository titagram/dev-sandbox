# DevBoard Quality Strategy

## Product Boundary

- `developer_provided`: DevBoard is an orchestration, registry, report, gate, and dashboard control plane.
- `developer_provided`: The local plugin or local agent is responsible for running target-code tests and scanners near the target repository.
- `developer_provided`: The frontend is an operational UI and future Quality Center.
- `verified_from_code`: Current plugin endpoints under `/api/plugin/v1/*` already support local repository context, runs, Genesis Import, Delta Sync, artifact upload, and wiki revision flows.
- `inferred`: Quality reports should reuse that separation: target repository execution produces artifacts, and DevBoard normalizes and visualizes the results.

## Strategy

- `developer_provided`: The quality system must be deterministic, incremental, safe for AI-assisted workflows, and oriented around domain truth rather than simple route status checks.
- `inferred`: Build the quality layer in dependency order: documents, registries, report primitives, route inventory, safe route smoke, gates, tooling scripts, disabled scanner integration, then dashboard API.
- `verified_from_code`: Current dashboard functionality is browser-facing Inertia route work; the emergent.sh frontend expects JSON resources under `/api/dashboard/*`.
- `inferred`: Backend quality APIs should begin with read-only endpoints and only later add run-triggering endpoints behind explicit permissions and approval flags.

## Source Truth

- `developer_provided`: Do not treat inferred facts as verified.
- `verified_from_code`: The project already uses source status values in `ai-sandbox/config/project.yaml`: `verified_from_code`, `developer_provided`, `inferred`, and `needs_verification`.
- `inferred`: Quality registry entries should carry source status and evidence references so the UI can distinguish implemented checks, examples, warnings, and missing setup.

## Safety Defaults

- `developer_provided`: Destructive scans, active DAST, production scans, payment tests, email sends, and destructive route calls must never run by default.
- `inferred`: Default quality commands should be read-only or confined to configured safe routes.
- `inferred`: Any operation that can mutate application state, crawl authenticated state, send messages, hit payment providers, load-test services, or run active security probes should require explicit backend approval and a human confirmation trail.

## Integration With emergent.sh Frontend

- `verified_from_code`: The generated frontend already has a `DevboardApi` adapter interface and `httpApi.ts` paths under `/api/dashboard`.
- `verified_from_code`: The generated frontend has mock data by default and can be switched to HTTP with `REACT_APP_USE_MOCK=false`.
- `inferred`: DevBoard integration should add Laravel dashboard JSON endpoints or adapter resources before copying or replacing the current Inertia screens.
- `inferred`: React component edits should remain small unless the Laravel API contract cannot reasonably satisfy the generated types.

