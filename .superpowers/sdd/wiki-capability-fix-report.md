# Wiki capability catalog and fail-fast job admission

Baseline: `98ca0938908ede205ed85f89679d55cfca12083a` (`feat(graph): add secure dashboard explorer API`), clean before this work.

## Scope

Fixed backend/frontend drift around Hades capabilities and wiki job admission. No migration was needed. The plugin route in `backend/routes/api.php` was not modified. Bootstrap and agent tokens remain separate and project-scoped; no live token, database, container, deploy, commit, or push operation was performed.

## TDD evidence

- Backend RED with explicit SQLite test environment: 24 tests, 17 passed, 5 failures, 2 errors, 188 assertions. Failures proved the missing `supportedNames()` API, unfiltered/unsorted explicit grants, `allowed=[]` expanding to all capabilities, wiki endpoints enqueueing without capability, and Admin validation rejecting `project_inspection`.
- Frontend RED after harness resolution: 2 suites, 7 tests, 3 failures, 4 passed. The real failures were the absent mock catalog and the page rendering only the legacy three capability buttons.

## Implementation

- `HadesCapabilityPolicy::supportedNames()` is the single backend catalog in canonical order: `read_files`, `read_source_slice`, `project_inspection`, `sync_git_tree`, `populate_backend_ast`, `populate_project_wiki`.
- `normalizeNames()` filters to that catalog and canonical order; `intersect()` treats explicit empty allowlists as empty, never as all.
- `HadesTokenService::createBootstrapToken()` defaults only `null` to the complete catalog, preserves explicit `[]`, and filters explicit grants.
- Agent registration persists the real declared/allowed intersection as `effective_capabilities`.
- Project-awareness bootstrap and dashboard wiki refresh validate the binding's same-project active agent and effective wiki capability before any job lookup/insert. Missing capability returns HTTP 409 with `error.code=agent_capability_not_enabled`, an actionable message, and `error.details.capability=populate_project_wiki`.
- Dashboard Hades now returns the backend catalog, decodes token `allowed_capabilities`, decodes workspace `declared_capabilities`/`effective_capabilities`, and uses the policy catalog for capability validation.
- `HadesAdminPage` initializes selection once from `supported_capabilities`, submits deliberate `[]` unchanged, and renders token/workspace grants. The mock uses the complete six-name catalog for omitted/null defaults and preserves explicit `[]`.

## GREEN and verification

- Backend focused GREEN: 24/24 tests passed, 203 assertions.
- Bounded backend gate (`HadesCapabilityPolicyTest`, Hades hardening/M1/M4, dashboard wiki refresh/Admin snapshot, Dashboard API contract): 71/71 tests passed, 903 assertions.
- Frontend focused GREEN: 2/2 suites, 7/7 tests passed.
- Frontend full suite: 9/9 suites, 50/50 tests passed.
- Frontend production build: compiled successfully. One pre-existing ESLint warning remains in `src/components/devboard/CommandPalette.tsx` (`useMemo` dependency list); that file was not changed.
- PHP lint: all 11 changed/untracked PHP files passed `php -l`.
- `git diff --check`: clean.
- `backend/vendor/pestphp/pest/.temp/test-results`: restored from `HEAD`; worktree and index diffs for the artifact are empty.

## Review wave: sibling capability admission and legacy grant compatibility

- RED evidence: the new review tests ran 6 tests with 1 pass, 5 failures, and 17 assertions. They caught the missing project_name/created_at payload keys, both unsafe Admin createJob paths, unconditional source-slice job creation, and SQL NULL being treated like JSON [].
- Work performed: bootstrapTokenPayload() now preserves project_name and created_at as null-safe fields; Admin job creation resolves the binding's same-project active agent, rejects a supplied cross-agent ID, checks effective capability, and returns the existing capability error envelope before any insert; source-slice candidates use HadesAgentJobPolicy::allowsCapability() and remain pending without a job when denied; registration distinguishes legacy SQL NULL (complete catalog) from explicit JSON [] (no capabilities).
- Focused GREEN: 6/6 tests passed, 32 assertions.
- Bounded GREEN: Dashboard Hades snapshot/createJob, wiki refresh, Hades hardening/M1/M4, and source-slice suites passed 40/40 tests, 368 assertions.
- An expanded 51-test attempt including older Hades M5 artifact paths had 47 passes and 4 failures caused by the existing synchronous Neo4j job path receiving a null NEO4J_PASSWORD; the failures were Authenticate::basic() setup errors, not capability assertions. The source-slice suite was rerun with its unrelated projection dispatch faked and passed in the bounded gate.
- This wave intentionally stopped before additional frontend work, as requested. No migration, live DB, container, deployment, token, commit, or push action occurred.

## Final review wave: catalog refresh, legacy frontend compatibility, and grant-later reconciliation

- Frontend RED: the focused HadesAdminPage suite ran 8 tests with 6 passing and 2 failures. Failures reproduced the legacy snapshot crash from missing supported_capabilities and the undefined grant being rendered as None; refresh and deny-all cases were added before production changes.
- Frontend GREEN: focused HadesAdminPage passed 8/8 tests; the full frontend suite passed 9/9 suites and 56/56 tests. Untouched selections adopt each refreshed catalog, touched selections filter removed capabilities, deliberate [] survives refresh, and legacy snapshots show an explicit backend upgrade-required state with token creation disabled. Grants render undefined as Not reported, null as All supported (default), and [] as None.
- Backend RED: the P2 source-slice wave ran 2 tests with 0 passes, 2 failures, and 13 assertions. It reproduced pending candidates being reported as present and grant-later registration creating zero jobs.
- Backend GREEN: source-slice/M1/policy focused coverage passed 17/17 tests and 127 assertions; the bounded Hades gate passed 43/43 tests and 382 assertions. The reconciler runs after registration, is capability-gated, scopes to linked bindings for the same agent/project, uses the existing candidate idempotency key, and leaves denied candidates pending and visible as approvable.
- TypeScript no-emit passed. Production build passed with the pre-existing CommandPalette hook-dependency warning only.
- Pint --test passed for the three review paths: DashboardHadesController.php, AgentRegisterController.php, and HadesAdminDashboardApiTest.php. git diff --check passed.
- No commit, push, deploy, migration, database, container, or live-token mutation occurred.

## Changed files

Backend production: `DashboardHadesController.php`, `DashboardWikiRefreshController.php`, `ProjectAwarenessBootstrapController.php`, `HadesCapabilityPolicy.php`, `HadesTokenService.php`.

Backend tests: Hades hardening/M1/M4, dashboard wiki refresh, new `HadesAdminDashboardApiTest.php`, and new `HadesCapabilityPolicyTest.php`.

Frontend: `HadesAdminPage.tsx`, new `HadesAdminPage.test.tsx`, `mockApi.ts`, `mockApi.test.ts`, and `types/devboard.ts`.

Residual risk is limited to the known pre-existing frontend lint warning; no live graph/database acceptance was required for this capability-only fix.

### Final review addendum: refresh payload integrity and four-file Pint correction

- RED evidence: the two catalog-refresh tests were rerun against the old `capabilitiesInitialized` implementation. The focused UI file had 8 tests with 6 passing and 2 failing: untouched refresh submitted the old catalog, and touched refresh submitted removed capability names. The failures asserted the exact `allowed_capabilities` payload, not only visible buttons.
- GREEN evidence: the dirty-aware reconciliation implementation passed `HadesAdminPage.test.tsx` 8/8. After refresh, untouched state submits exactly the new backend catalog; touched state submits exactly the filtered selection, with no hidden removed values. The deliberate deny-all case remains unchanged.
- Full verification: frontend 9/9 suites and 56/56 tests; bounded backend Hades gate 43/43 tests and 382 assertions; TypeScript no-emit passed; production build passed with only the pre-existing `CommandPalette.tsx` hook-dependency warning.
- Quality checks: focused Pint `--test` now covers all four requested files (`DashboardHadesController.php`, `AgentRegisterController.php`, `HadesAdminDashboardApiTest.php`, and `HadesM1ApiTest.php`) and passed after applying the two M1 fixers. All changed/untracked PHP passed `php -l`; `git diff --check` passed. The Pest temporary result was restored and `git diff 98ca0938908ede205ed85f89679d55cfca12083a -- backend/vendor/pestphp/pest/.temp/test-results` is empty.
- No commit, push, deploy, migration, database, container, live-token, or live-graph mutation occurred. Residual risk remains limited to the pre-existing frontend lint warning.

### Final backend review: binding-scoped source-slice reconciliation

- Request: remove candidate reconciliation from agent registration; reconcile only during authenticated job polling with a bounded binding scope; make candidate/job linking atomic and composite-key scoped; separate awareness pending candidates from approvable waiting jobs. No commit, push, deploy, migration, database, container, or live-graph mutation.
- RED evidence: the new/updated source-slice feature file ran 7 tests with 1 pass and 6 failures. Failures proved registration still created jobs, polling had no reconciliation, awareness omitted the two counters, and the batch limit was ignored.
- Work performed: AgentRegisterController no longer injects or calls the candidate service. AgentJobsController injects the service after linked-binding and effective/requested capability calculation, invokes it only when read_source_slice is effective/requested, and passes the validated request limit (default 25, bounded to 100). HadesSourceSliceCandidateService::reconcilePendingForBinding() selects only bounded pending IDs for the authenticated binding, re-reads each candidate under lockForUpdate() inside its own transaction, and inserts/links the job plus updates the candidate in that same transaction. Existing jobs match project, binding, agent, capability, and idempotency key; unrelated scope/capability jobs are not linked. Artifact ingestion uses the same transactional candidate/job boundary and no longer relies on separate createJob/markJobCreated helpers.
- Awareness: pending_candidates counts only pending candidates without a job; waiting_jobs counts only job_created/queued. Pending status is true for either count. Actions separately instruct an enabled agent to poll or a user to approve queued jobs.
- GREEN evidence: source-slice focused suite 7/7 tests and 79 assertions; bounded Hades/M1/M4/hardening/policy gate 36/36 tests and 334 assertions.
- Full gates: extended Hades plus dashboard gate 217 tests, 211 passed, 1 skipped, 5 failures; full backend 1050 tests, 1036 passed, 8 skipped, 5 failures and 1 error. Remaining failures are existing environment/legacy issues: Neo4j authentication with null NEO4J_PASSWORD in three synchronous projection paths, two legacy Hades traversal 404s, and the existing test-storage directory error. No new reconciliation failure appeared.
- Quality checks: Pint ran over every changed/untracked PHP file and passed; all changed/untracked PHP passed php -l; git diff --check passed; Pest temporary results were restored and have no worktree or baseline diff.
- Test coverage includes: re-registration without job creation; first poll creating exactly one job; retry idempotency; mismatched scope/capability idempotency records; bounded batch progress; and separate pending/waiting awareness actions. No new migration, scheduler, queue, or infrastructure was added.

### Final P2 correction: atomic candidate materialization during direct ingest

- RED/fixture evidence: added direct-service regressions for terminal-candidate preservation and duplicate ingestion. Both passed on the existing sequential SQLite baseline because that baseline already handled sequential terminal/idempotent calls; the test DB is SQLite :memory:, so a two-connection concurrent race is not representable without test artifices. The tests remain useful coverage for the observable contract.
- Work performed: ingestArtifactCandidates now executes insertOrIgnore with the complete initial pending candidate row inside the transaction, then re-reads project/binding/candidate_key with lockForUpdate. Terminal statuses job_created, slice_uploaded, and rejected return without reset; non-terminal rows receive current metadata while preserving created_at, then scoped job lookup, insert, and candidate update remain in the same transaction.
- GREEN evidence: source-slice focused suite 9/9 tests and 106 assertions; bounded Hades/M1/M4/hardening/policy suite 38/38 tests and 361 assertions.
- Quality checks: Pint passed over every changed/untracked PHP file; all changed/untracked PHP passed php -l; git diff --check passed; Pest temporary results were restored with empty worktree and baseline diffs.
- No migration, live DB, container, deployment, commit, or push was performed. A real two-connection race test was not added because the configured in-memory SQLite test database does not support shared concurrent connections without artifices.
