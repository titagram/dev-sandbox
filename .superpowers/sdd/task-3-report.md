# Plan 1 Task 3 report

Baseline: af7a5707 (fix(graph): harden public explorer projection), clean before Task 3.

## Scope

Implemented the dashboard graph explorer controller and route, added the shared dashboard-reader guard to ChecksDashboardRoles, and added the feature contract suite. The plugin route in backend/routes/api.php was not modified.

## TDD evidence

- Initial route RED: 1 test, 1 assertion, failed with the expected 404 because the controller/route did not exist.
- Primary review RED: 33 tests, 28 passed, 4 failures, 1 error, 127 assertions. Findings covered the missing shared guard, root/edge DTO loss, typed unavailable envelope, malformed-handle reason, and nested leaks.
- DTO review RED: 39 tests, 32 passed, 7 failures, 159 assertions. Findings covered local guard shadowing, scope DTO loss, GH1 boundary validation, edge vocabulary, partial scopes, and typed graph fields.
- Final DTO RED: 42 tests, 39 passed, 2 failures, 1 error, 248 assertions. Findings covered truthful returned, closed reasons/status, and nested edge-type members.
- Final Task 3 GREEN: 42 tests, 42 passed, 256 assertions.

## Verification

- Exact focused suite with APP_KEY=0123456789abcdef0123456789abcdef: 175 tests, 173 passed, 2 skipped, 1,461 assertions.
- The two skips are the existing read-only Neo4j smoke tests requiring NEO4J_READ_ONLY_SMOKE_GRAPH_VERSION plus NEO4J_READ_ONLY_SMOKE_START or NEO4J_READ_ONLY_SMOKE_ISOLATED_START.
- Changed and untracked PHP files passed php -l.
- git diff --check passed.
- backend/vendor/pestphp/pest/.temp/test-results was restored from HEAD; git diff 984a1ad4 -- backend/vendor/pestphp/pest/.temp/test-results is empty.

## Independent-review follow-up — single Neo4j lookup correction

- RED evidence: the single-lookup assertions produced 40 service tests with 34 passed, 6 failures, and 328 assertions. Failures showed duplicated key/detail and key/search queries plus readiness reason regressions.
- GREEN evidence: Explorer service 40/40 tests with 339 assertions; dashboard API 53/53 tests with 328 assertions.
- Refactor: projectionForRead() now reads SQL projection state only. Detail and resolveNode() use one combined version plus optional indexed-node Cypher query, returning version_project_key and version_source_fingerprint for PHP comparison. Search validates those scalars in its single full-text query; overview retains its own one-query compatibility check. Neighborhood, path, and impact propagate differentiated readiness/node reasons without a Neo4j preflight.
- Additional API coverage verifies real Authorization/X-DevBoard headers, scope-only signed cursor tampering and non-paginated cursor rejection, zero limits, unavailable traversal/path/impact envelopes, stale/deleted/unlinked scope filtering, ambiguous-scope pagination metadata, and ISO-8601 projection timestamps. Source-location/reflection authorization tests were removed; the recursive DTO fixture uses generated GH1 handles.
- Combined focused gate: 190 tests, 188 passed, 2 skipped, 1,577 assertions. Broad relevant Task 2 gate: 371 tests, 368 passed, 3 skipped, 2,542 assertions.
- Changed/untracked PHP passed php -l; git diff --check passed. The Pest result file was restored from 984a1ad4, and its parent diff is empty.
- No commit, push, deploy, database, container, or live-graph mutation was performed. A fresh independent review is requested; live Neo4j checks remain skipped without explicit fixture variables.

## Public contract notes

The controller emits a coarse safe DTO: GH1 handles, closed node kinds (method, class, method_reference, external_class, table, route, trait, external_symbol, interface, file, unknown), exact live edge vocabulary/families, typed scope metadata, a nullable root node, truthful sanitized returned, bounded pagination fields, and a closed reason vocabulary. Internal IDs, raw paths, graph-version origin fields, projection/artifact IDs, and plugin credentials are recursively removed. No database, container, deployment, live-graph mutation, commit, or push was performed.

## Third independent review follow-up

- Added preview regressions for an older ready projection plus a newer queued artifact candidate, and for a stale projection retaining active version and current fingerprint. The preview now serves only the ready winner and returns an empty, non-clickable rebuild-required envelope for stale state.
- Aligned no-ready projection fallback ordering with explicit stale/failed priority over queued/projecting, independent of row insertion order. The exact public kind list above remains the controller contract.
- Focused preview/dashboard/Explorer gate: 211 tests, 209 passed, 2 environment-gated skips, 1,723 assertions. Broad Task 2/3 gate: 468 tests, 465 passed, 3 skips, 3,194 assertions. PHP lint and `git diff --check` passed; the Pest artifact was restored from `HEAD` and its parent diff is empty. No commit or push was performed.

## Final artifact-reference privacy correction

- Canonical ready and rebuild-required responses no longer include `source.ref`; `source.type`, `status`, `origin`, and `generated_at` remain present. Non-canonical source references are unchanged.
- The ready-winner regression asserts the ready `graph_version`, `active_graph_version`, and artifact `created_at` as `generated_at` without using or exposing an artifact id.
- Final focused gate: 211 tests, 209 passed, 2 environment-gated skips, 1,727 assertions. Broad gate: 468 tests, 465 passed, 3 skips, 3,198 assertions. PHP lint, `git diff --check`, and Pest restoration from `HEAD` passed. No commit or push.
