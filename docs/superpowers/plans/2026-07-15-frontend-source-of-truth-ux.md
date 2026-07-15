# Frontend Source-of-Truth UX Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Hades project overview, Kickstart, Graph, Memory, and Wiki use canonical backend state, expose safe evidence, and remain readable and accessible at mobile widths while implementing every acceptance criterion in the 2026-07-15 frontend source-of-truth UX request.

**Architecture:** Add one backend `ProjectOperationalStatusResolver` consumed by project summaries, project detail, overview, and Kickstart. Extend the canonical graph public contract with deterministic exact-match ranking, completeness, safe source metadata, and truthful capacity reasons. Enforce manual Wiki verification status in `WikiRevisionService`. Replace the ad-hoc Wiki Markdown renderer with a pinned GFM/sanitizer pipeline and split the UI changes into focused React behaviors.

**Tech Stack:** Laravel 13, PHP, Pest/PHPUnit, canonical Postgres graph projection and Neo4j projection, React 19, TypeScript, CRA/CRACO, shadcn/Radix UI, Tailwind, Yarn 1.22.22, `react-markdown@10.1.0`, `remark-gfm@4.0.1`, and `rehype-sanitize@6.0.0`.

## Global Constraints

- Work only on `codex/frontend-source-of-truth-ux`; preserve unrelated worktree changes.
- Read and obey `/home/ubuntu/dev-sandbox/AGENTS.md`, `ai-sandbox/INIT.md`, `ai-sandbox/instructions/INDEX.md`, and the relevant backend/frontend policies already selected for this task.
- Never edit `ai-sandbox/logbooks/LOGBOOK_SANDBOX_IA.md` or `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`.
- Never merge, push, deploy, alter Traefik, reset/seed the database, or run destructive database operations.
- Use `apply_patch` for source and document edits. Do not add a dependency until the existing package manifest and `yarn.lock` have been inspected; pin every new dependency through the lockfile.
- For every task: write or extend the failing test first, run the focused command and retain the red result in the work record, implement the smallest coherent change, rerun the same command green, then run the task regression command.
- Do not expose absolute paths, raw IDs, legacy internal identities, or unsafe HTML/URLs.
- Do not claim completion without fresh command output from the final checks.

---

## 1. Record the design and baseline

- [ ] Add `docs/superpowers/specs/2026-07-15-frontend-source-of-truth-ux-design.md` with the operational-status, graph, Wiki, Memory, navigation, responsive, security, and testing contracts. Verify it contains no unresolved placeholder language with `rg -n 'placeholder-marker' docs/superpowers/specs/2026-07-15-frontend-source-of-truth-ux-design.md` and `git diff --check`.
- [ ] Add this plan at `docs/superpowers/plans/2026-07-15-frontend-source-of-truth-ux.md`. Verify the exact files, tests, status enums, and commit sequence are present with `rg -n 'ProjectOperationalStatusResolver|react-markdown|yarn test|php artisan test' docs/superpowers/plans/2026-07-15-frontend-source-of-truth-ux.md` and confirm the document has no unresolved placeholders.
- [ ] Run the baseline focused suites before implementation:

  ```bash
  cd backend && php artisan test tests/Feature/Dashboard/ProjectKickstartDashboardApiTest.php tests/Feature/Dashboard/WikiManualDashboardApiTest.php tests/Feature/Dashboard/DashboardGraphExplorerApiTest.php
  cd ../frontend && CI=true corepack yarn test --watchAll=false --runInBand src/pages/GraphPage.test.tsx src/pages/WikiPageDetailPage.test.tsx src/components/devboard/GraphExplorer.test.tsx
  ```

- [ ] Commit only the two documents as `docs: specify source-of-truth UX contracts`.

## 2. Make project operational status canonical (strict red-green)

- [ ] Extend `backend/tests/Feature/Dashboard/ProjectKickstartDashboardApiTest.php` with a fixture where `canonical_graph_projections` has a ready workspace-binding projection while legacy `artifacts`, `genesis_imports`, and `local_workspaces` rows are empty or incomplete. First assert overview and project detail return `operational_status.graph.status=ready`, `workspace.status=linked`, `genesis.status=complete`, and `artifacts.status=available`; assert Kickstart uses the same object. Run `cd backend && php artisan test tests/Feature/Dashboard/ProjectKickstartDashboardApiTest.php`, capture the red assertion, and only then implement.
- [ ] Create `backend/app/Services/Dashboard/ProjectOperationalStatusResolver.php` with constructor dependencies for the canonical graph repository and project repositories, a `forProject(string $projectId): array` method, safe status mapping, and human-readable reasons. Select the same ready canonical projection winner used by Graph. Count a linked workspace binding from the canonical scope plus existing linked bindings without allowing a legacy zero to override canonical readiness. Treat a ready canonical projection as Genesis/graph evidence and report legacy artifact count separately.
- [ ] Update `backend/app/Dashboard/DashboardApiReader.php` to inject the resolver and attach the exact `operational_status` object to `overview.projects`, `project`, `projectSummary`, repository-facing project data, and `kickstart`. Remove client-dependent readiness inference from the API shape while leaving old scalar fields for compatibility. Add the resolver’s fields to `backend/tests/Unit/Dashboard/ProjectOperationalStatusResolverTest.php` with ready, missing, partial, and failed/no-projection cases.
- [ ] Run focused backend tests and formatter:

  ```bash
  cd backend && php artisan test tests/Unit/Dashboard/ProjectOperationalStatusResolverTest.php tests/Feature/Dashboard/ProjectKickstartDashboardApiTest.php
  cd backend && vendor/bin/pint --test
  ```

- [ ] Commit as `feat(api): expose canonical project operational status`.

## 3. Enforce manual Wiki verification status in the backend

- [ ] Extend `backend/tests/Feature/Dashboard/WikiManualDashboardApiTest.php` before implementation. Submit create and update payloads with each of `developer_provided`, `verified_from_code`, `ai_generated`, `stale`, and `conflict_with_code`; assert every response and persisted revision has `source_status=needs_verification` and manual source metadata. Run `cd backend && php artisan test tests/Feature/Dashboard/WikiManualDashboardApiTest.php` and retain the red result.
- [ ] Update `backend/app/Services/WikiRevisionService.php::write()` so a manual writer (`producer=dashboard_user` or `source_type=user_manual`) overwrites any requested status with `needs_verification` before validation/persistence. Keep code-evidence validation for non-manual verification workflows. Keep request validation backwards-compatible by accepting a legacy field but never trusting it for manual writes.
- [ ] Remove the status selectors and status submission fields from `frontend/src/pages/WikiPage.tsx` and `frontend/src/pages/WikiPageDetailPage.tsx`. Display a fixed “Needs verification” explanation in create/edit confirmation areas. Extend `frontend/src/pages/WikiPageDetailPage.test.tsx` and the Wiki page tests to prove no selector is rendered and a manual save sends no self-assigned status.
- [ ] Run:

  ```bash
  cd backend && php artisan test tests/Feature/Dashboard/WikiManualDashboardApiTest.php
  cd frontend && CI=true corepack yarn test --watchAll=false --runInBand src/pages/WikiPage.test.tsx src/pages/WikiPageDetailPage.test.tsx
  ```

- [ ] Commit as `fix(wiki): force manual revisions into verification queue`.

## 4. Harden graph search, public metadata, and completeness

- [ ] Add failing unit tests in `backend/tests/Unit/Services/Graph/DashboardGraphSearchTermsTest.php` for safe relative source metadata and rejection of absolute/path-traversal values. Assert no returned public field contains an absolute path or raw identity.
- [ ] Add failing service/API tests in `backend/tests/Unit/Services/Graph/DashboardGraphExplorerServiceTest.php` and `backend/tests/Feature/Dashboard/DashboardGraphExplorerApiTest.php` for: exact route `/generale/soggetti-attivi/` before `contact_flock_roles_worker`; exact `AdminControllerBulkDeleteBehavior` before its `Test` class; safe `kind`, `label`, `source_file`, line, namespace, match type/reason; verified-none versus partial/not-indexed empty callers/dependencies/impact; and exact-looking capacity omission returning no fuzzy substitute with an actionable partial reason.
- [ ] Run the graph tests and retain the expected red failures:

  ```bash
  cd backend && php artisan test tests/Unit/Services/Graph/DashboardGraphSearchTermsTest.php tests/Unit/Services/Graph/DashboardGraphExplorerServiceTest.php tests/Feature/Dashboard/DashboardGraphExplorerApiTest.php
  ```

- [ ] Update `backend/app/Services/Graph/DashboardGraphSearchTerms.php` to derive bounded public `source_file`, `line_start`, `line_end`, and `namespace` values from canonical properties. Keep raw source properties internal. Update `backend/app/Services/Graph/Neo4jCanonicalGraphProjector.php` so those derived values are projected into the existing canonical search node properties; no schema change is required.
- [ ] Update `backend/app/Services/Graph/DashboardGraphExplorerService.php` to collect exact normalized symbol-name and route-path candidates before capacity-limited full-text candidates, emit deterministic `match_type` and `match_reason`, and suppress fuzzy output for exact-looking capacity misses. Add completeness calculation to search, detail, neighborhood, callers, dependencies, and impact results.
- [ ] Update `backend/app/Http/Controllers/Dashboard/Api/DashboardGraphExplorerController.php` to allow only sanitized public metadata, add `completeness`, and allow the new actionable reason while continuing to strip absolute paths and private fields. Extend the response-contract tests without replacing them with snapshots.
- [ ] Mirror the public behavior in `frontend/src/api/mockApi.ts`, `frontend/src/api/mockData.ts`, and `frontend/src/types/devboard.ts`. Add frontend API tests in `frontend/src/api/mockApi.test.ts` for exact ranking, metadata, and capacity/partial messaging.
- [ ] Run the focused backend and frontend graph suites green, then run `cd backend && vendor/bin/pint --test`.
- [ ] Commit as `feat(graph): expose ranked public evidence and completeness`.

## 5. Replace Wiki Markdown rendering with safe GFM

- [ ] Extend `frontend/src/pages/WikiPageDetailPage.test.tsx` first with failing assertions for GFM headings/lists/fenced code/inline code/blockquote/table, safe external links, unsafe URL inertness, raw HTML sanitization, and duplicate body H1 suppression. Keep raw Markdown edit assertions.
- [ ] Inspect `frontend/package.json`, `frontend/yarn.lock`, and the current Yarn version, then add exact dependencies:

  ```bash
  cd frontend && corepack yarn add --exact react-markdown@10.1.0 remark-gfm@4.0.1 rehype-sanitize@6.0.0
  ```

- [ ] Replace the local ad-hoc renderer in `frontend/src/pages/WikiPageDetailPage.tsx` with a small `MarkdownContent` component using `react-markdown`, `remark-gfm`, and `rehype-sanitize`. Configure the sanitizer schema for safe protocols and class names only as needed by existing design tokens. Detect and omit the first body H1 when it normalizes to the page title. Keep edit mode as a raw textarea.
- [ ] Run the focused Wiki tests and production TypeScript build check:

  ```bash
  cd frontend && CI=true corepack yarn test --watchAll=false --runInBand src/pages/WikiPageDetailPage.test.tsx
  cd frontend && corepack yarn build
  ```

- [ ] Commit as `feat(wiki): render sanitized GitHub-flavored Markdown`.

## 6. Improve Wiki index search, filters, queue, and refresh status

- [ ] Extend the Wiki index tests before implementation for client-side text matching, audience/page-type/status/source filters, verification queue count, and one understandable refresh status line. Assert the manual create dialog has no source selector.
- [ ] Update `frontend/src/pages/WikiPage.tsx` with controlled text search, audience/page-type/status/source filters, derived filtered rows, a verification queue count, and concise refresh state. Use safe labels from existing types; do not duplicate raw revision IDs in the index.
- [ ] Update `frontend/src/types/devboard.ts` only where the existing API needs optional audience/page-type/source fields, and update `frontend/src/api/mockData.ts` fixtures so tests exercise each filter without inventing production data.
- [ ] Run `cd frontend && CI=true corepack yarn test --watchAll=false --runInBand src/pages/WikiPage.test.tsx src/pages/WikiPageDetailPage.test.tsx`, then commit as `feat(wiki): add index discovery and verification queue filters`.

## 7. Improve Graph result cards, detail evidence, and truthful empty states

- [ ] Extend `frontend/src/components/devboard/GraphExplorer.test.tsx` first for exact-match badges, safe source/line/namespace display, match reason, readable result hierarchy, and distinct verified-none/partial/not-indexed callers/dependencies/impact states. Run the focused test to capture red output.
- [ ] Update `frontend/src/types/devboard.ts` with graph metadata/match/completeness unions and `frontend/src/api/mockApi.ts` responses. Update `frontend/src/components/devboard/GraphExplorer.tsx` so cards show kind, label, match reason, source evidence, and score/distance in a compact hierarchy; detail shows public evidence and operational completeness; empty relationships use the backend completeness language. Never display handles as if they were source names.
- [ ] Update `frontend/src/pages/GraphPage.tsx` to render human project names, wrap/middle-truncate IDs with accessible full labels, and show graph provenance/scope without clipping. Add a visible Graph item in `frontend/src/lib/nav.ts` using the existing scoped route.
- [ ] Run:

  ```bash
  cd frontend && CI=true corepack yarn test --watchAll=false --runInBand src/components/devboard/GraphExplorer.test.tsx src/pages/GraphPage.test.tsx src/pages/GraphPageProjectTransition.test.tsx src/api/mockApi.test.ts
  ```

- [ ] Commit as `feat(graph-ui): make evidence and uncertainty scannable`.

## 8. Make Memory primary and import advanced

- [ ] Extend or add a focused test for `frontend/src/pages/ProjectMemoryPage.tsx` that asserts Memory stream appears before import controls and the workspace import form is hidden until an “Advanced import” disclosure is opened. Run `cd frontend && CI=true corepack yarn test --watchAll=false --runInBand src/pages/ProjectMemoryPage.test.tsx` and retain red output.
- [ ] Update `frontend/src/pages/ProjectMemoryPage.tsx` to render the Memory stream before a collapsed `Collapsible`/`details` Advanced import panel. Preserve import form, batch table, detail dialog, cancellation, read-only state, and explicit proposal semantics inside the advanced panel. Add accessible trigger labeling and do not hide import failures.
- [ ] Run the focused Memory test and the existing page suite green, then commit as `feat(memory): prioritize stream and collapse advanced import`.

## 9. Fix project scope, mobile overflow, and icon accessibility

- [ ] Extend `frontend/src/components/devboard/AppShell.test.tsx` or the nearest existing shell test first for selecting a real active project after projects load, clearing stale Demo Project scope, accessible names for icon-only theme/menu controls, and middle-truncated breadcrumb IDs with a full accessible label. Add/extend page tests for long Graph/Wiki metadata and project UUID wrapping. Run the focused tests red.
- [ ] Update `frontend/src/components/devboard/AppShell.tsx` to reconcile persisted/current project IDs against loaded authenticated projects, prefer a valid route/current project, and select the first known active project when no valid selection exists. Use a `min-w-0` breadcrumb container, human project labels, accessible full titles, and explicit `aria-label`s on icon-only controls.
- [ ] Update `frontend/src/components/devboard/Layout.tsx`, `frontend/src/pages/ProjectDetailPage.tsx`, `frontend/src/pages/ProjectMemoryPage.tsx`, `frontend/src/pages/GraphPage.tsx`, and Wiki pages for `min-w-0`, wrapping/middle-truncation helpers, visible critical state, and readable human Genesis/workspace language. Remove `Demo Project` fallback only when real authenticated projects are known; keep loading behavior honest.
- [ ] Run the focused shell/page tests and, if available in this workspace, a Playwright rendered check at 390×844. Record that no Browser skill is installed if only the Playwright fallback is available. Commit as `fix(frontend): preserve project scope and mobile accessibility`.

## 10. Full verification and handoff

- [ ] Run the full frontend suite non-watch:

  ```bash
  cd frontend && CI=true corepack yarn test --watchAll=false
  ```

- [ ] Run the frontend production install/build:

  ```bash
  cd frontend && corepack yarn install --frozen-lockfile && corepack yarn build
  ```

- [ ] Run relevant backend tests and available quality checks:

  ```bash
  cd backend && php artisan test tests/Feature/Dashboard/ProjectKickstartDashboardApiTest.php tests/Feature/Dashboard/WikiManualDashboardApiTest.php tests/Feature/Dashboard/DashboardGraphExplorerApiTest.php tests/Unit/Dashboard/ProjectOperationalStatusResolverTest.php tests/Unit/Services/Graph/DashboardGraphSearchTermsTest.php tests/Unit/Services/Graph/DashboardGraphExplorerServiceTest.php
  cd backend && vendor/bin/pint --test
  cd backend && vendor/bin/phpstan analyse --memory-limit=1G
  cd .. && git diff --check
  ```

  If PHPStan is not installed or configured in this checkout, record the exact command output as an environment limitation and run every configured static check available in `backend/composer.json`.

- [ ] Inspect `git diff --stat`, `git diff`, `git status --short`, and `git log --oneline` to confirm only intended source/docs/package/lock changes exist and no Carnovali logbook changed. Verify the worktree is clean after committing all intended changes.
- [ ] Finish with coherent commit SHAs, exact test/build/formatter/static commands and outcomes, the non-destructive re-projection note if applicable, and any real remaining blocker. Do not merge, push, deploy, or alter Traefik.
