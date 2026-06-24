# Security Testing

## Safety Model

- `developer_provided`: Destructive scans, active DAST, production scans, payment tests, email sends, and destructive route calls must never run by default.
- `developer_provided`: Target repository scanning should run near the target code through the local plugin or local agent.
- `inferred`: DevBoard should treat scanner outputs as reports and evidence, then normalize findings into dashboard and gate views.

## Immediate Non-Destructive Checks

- `verified_from_code`: `backend/composer.json` can support `composer audit` without adding a scanner engine.
- `verified_from_code`: `composer quality:audit` now runs `composer audit` and reports no advisories at Task 7 verification time.
- `verified_from_code`: `composer quality:static` now runs PHPStan over `app/Quality` and `app/Console/Commands/Quality` at level 5.
- `verified_from_code`: Laravel Pint is present as a direct dev dependency.
- `inferred`: Composer audit findings can be ingested as quality reports once report primitives exist.
- `inferred`: Pint results can be surfaced as static quality status, but formatting should remain a deliberate developer action until the workflow decides whether auto-fix is allowed.

## Optional Scanner Readiness

- `inferred`: Semgrep can be used for static source scanning only when a ruleset and target repository scope are explicit.
- `inferred`: Trivy can be used for filesystem, image, or dependency checks only when the artifact scope is explicit.
- `inferred`: ZAP baseline can be considered for passive web checks in a controlled non-production environment, but it is not installed or enabled by default.
- `inferred`: Nuclei, Wapiti, Greenbone/OpenVAS, active ZAP scans, load tests, payment tests, and email-producing tests require explicit opt-in configuration and human approval.
- `inferred`: Infection mutation testing, Schemathesis API fuzzing, and k6 load tests require dedicated setup and must not be part of default quality runs.
- `developer_provided`: Test/scanner execution against a target source repository belongs near that target repository via the local plugin or agent; DevBoard backend consumes normalized reports, evidence, artifacts, and gate status.

## Gate Inputs

- `inferred`: High or critical scanner findings should be represented as report findings with severity and evidence.
- `inferred`: Secret detection findings should be blocking by default when validated.
- `inferred`: Missing scanner setup should be a `missing_setup` or warning state, not a false pass.
- `inferred`: High or critical Composer audit advisories should normalize to `composer_audit_advisory` findings so the pull request gate can evaluate them.

## Production Protection

- `developer_provided`: Production scans must not run by default.
- `inferred`: Scanner config should include target environment, allowed host patterns, active-scan flags, and approval requirements.
- `inferred`: Commands should fail closed when scanner configuration is absent or ambiguous.
