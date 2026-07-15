# Wiki Verification Boundary Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close three confirmed Hades wiki-verification boundary gaps without changing legitimate internal or administrator-authored wiki writes.

**Architecture:** Keep the current capability and revision services, but make their trust boundaries explicit at each entry point. Legacy SQL `NULL` grants resolve through a frozen pre-verification catalog; verification rechecks project lifecycle state while holding the project lock; refresh jobs remain draft producers and are applied only for the intended capability/job-type pair.

**Tech Stack:** PHP 8, Laravel, Pest, SQLite test database.

## Global Constraints

- Preserve explicit current-catalog grants for newly issued bootstrap tokens.
- Preserve prompt- and API-contract behavior outside the three confirmed gaps.
- Do not modify or stage `backend/vendor/pestphp/pest/.temp/test-results`.
- Use failing regression tests before production changes.

---

### Task 1: Freeze legacy null grants

**Files:**
- Modify: `backend/tests/Feature/Hades/HadesM1ApiTest.php`
- Modify: `backend/app/Services/Hades/HadesCapabilityPolicy.php`
- Modify: `backend/app/Http/Controllers/Hades/AgentRegisterController.php`

**Interfaces:**
- Consumes: `HadesCapabilityPolicy::supportedNames()` for the current catalog.
- Produces: `HadesCapabilityPolicy::legacyNullGrantNames(): array` containing the six pre-`verify_project_wiki` capabilities.

- [ ] Change the legacy-null registration test to declare the current catalog but expect only the frozen pre-verification names.
- [ ] Run the focused test and confirm it fails because `verify_project_wiki` is still effective.
- [ ] Add `legacyNullGrantNames()` and use it only when the persisted bootstrap grant is SQL `NULL`.
- [ ] Run `HadesM1ApiTest.php` and confirm it passes.

### Task 2: Recheck project lifecycle under the verification lock

**Files:**
- Modify: `backend/tests/Feature/Hades/HadesWikiWorkflowTest.php`
- Modify: `backend/app/Services/Hades/WikiVerificationService.php`

**Interfaces:**
- Consumes: the locked `projects` row inside `WikiVerificationService::verify()`.
- Produces: `project_not_found`, `project_deleted`, or `project_archived` `HadesTokenException` responses before any page revision is written.

- [ ] Add service-level dataset tests for missing, deleted, and archived projects, asserting no new revision or verification audit event.
- [ ] Run the focused tests and confirm current verification writes or reaches the wrong error.
- [ ] Validate the locked project row using the same lifecycle predicates/messages as the Hades draft path.
- [ ] Run the focused lifecycle tests and the complete wiki workflow suite.

### Task 3: Keep refresh results at draft trust and require the intended job type

**Files:**
- Modify: `backend/tests/Feature/Dashboard/WikiRefreshDashboardApiTest.php`
- Modify: `backend/tests/Feature/Hades/HadesM4AgentJobsTest.php`
- Modify: `backend/app/Services/WikiRefreshResultService.php`
- Modify: `backend/app/Http/Controllers/Hades/AgentJobResultController.php`

**Interfaces:**
- Consumes: page content/evidence from `devboard.wiki_refresh_result.v1`.
- Produces: revisions with `source_status=needs_verification`; automatic application only when `capability=populate_project_wiki` and `job_type=wiki_refresh`.

- [ ] Update both refresh result tests to expect draft status even when the agent submits `verified_from_code` and evidence.
- [ ] Add a started `populate_project_wiki` non-wiki-job regression test whose typed result completes without being applied.
- [ ] Run the focused tests and confirm they fail against current behavior.
- [ ] Remove caller-selected trust inference from `WikiRefreshResultService` and tighten `shouldApplyWikiRefreshResult()` to the capability/job-type conjunction.
- [ ] Run both focused suites and the combined Hades regression suites.

### Task 4: Audit sibling revision writers and finish

**Files:**
- Inspect: all callers of `WikiRevisionService::write()` outside Hades verification/refresh.
- Modify only if a caller is an untrusted Hades-agent path capable of minting `verified_from_code`.

**Interfaces:**
- Produces: a handoff list classifying internal/admin/plugin callers as legitimate trust authorities or in scope.

- [ ] Enumerate every production caller and trace its authentication and source-status handling.
- [ ] Run formatting or static checks relevant to modified PHP files.
- [ ] Run fresh targeted tests, inspect the final diff and status, then commit only intended files.
