# Project Clarity + Contextual Backlog Triage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace opaque project setup copy with a clear numbered Hades journey, make canonical graph readiness the graph status source of truth, and make Backlog Triage contextually honest by supplying bounded task, memory, Wiki, and graph evidence to the deterministic fallback and read-only model tools. Suggestions remain approval-required and no project, task, Kanban, Wiki, run, or graph mutation is introduced.

**Architecture:** Implement after Plan 1 and Plan 2. Backend project summaries remain served by `DashboardApiReader`; a scope is current exactly when its project-owned `canonical_graph_projections` row has `status=ready` and non-null `active_graph_version`. All canonical reads use `active_graph_version`, even when it differs from origin `graph_version`; legacy `graph_snapshot` artifacts never make a product status current. Project status aggregates expected scopes with explicit failed/processing/complete/not-started precedence, while repositories report only their own scope. Kickstart persistence keys stay `project_intake`, `repository_declaration`, `local_workspace_link`, `genesis`, and `knowledge_review` while labels/descriptions/actions explain the human meaning. Backlog Triage gets a bounded context snapshot from PostgreSQL plus Plan 1’s dashboard graph explorer service, and its registry permits only read tools. Frontend view models expose evidence coverage only when matching evidence refs exist.

**Tech Stack:** Laravel 13, PHP 8.3, Pest, PostgreSQL, Neo4j canonical projections, React 19, TypeScript 5.4, CRA/CRACO, Radix/Tailwind, Jest/jsdom.

## Global Constraints

- Execution order is Plan 1, Plan 2, then this Plan 3. Use the public graph handles and source metadata from Plan 1 and the Wiki/memory discriminants from Plan 2.
- Work in the existing clean checkout on `feature/canonical-graph-foundation-20260712` at `82f9f469` without a nested worktree. Do not execute commits, deploy, mutate database contents, or reset data while reviewing this plan.
- Preserve persistence identifiers and existing API keys. `genesis` remains a compatibility key but is displayed as Initial code import; `local_workspace_link` remains a compatibility key but is displayed as Hades workspace connection; graph import/status is displayed as Code graph.
- Every visible status explains status, next action, and truth source: `backend_verified` for database/projection facts and `local_agent_reported` for local workspace observations. Do not present a local-agent report as backend verification.
- Canonical graph projection readiness is the only source of `graph_status=complete`: a scope is current iff its project-owned `canonical_graph_projections` row has `status=ready` and non-null `active_graph_version`. Canonical reads always use that active version, including when it differs from origin `graph_version`. For project aggregation, zero expected scopes or legacy-only data is `not_started`; any failed expected scope is `failed`; with no failed scopes, any processing expected scope is `processing`; `complete` requires every expected scope to be current. Repositories evaluate their own project-owned scope only. The exact observed cases must be tested.
- Backlog Triage is project-scoped and read-only. Its model tool registry may include `read_project_summary`, `read_project_tasks`, `search_project_memory`, `search_wiki_revisions`, and `query_project_graph` only. The suggestion write is the existing service persistence operation, remains `approval_required=true`, and never changes a target task or knowledge row.
- The deterministic fallback uses all four sources when present: open tasks, bounded relevant memory, current Wiki page/revision excerpts, and canonical graph projection metadata/bounded important symbols/statistics. Evidence refs are emitted only for actual rows/artifacts/projections included in the context.
- No provider key, endpoint, raw provider error, Neo4j external id, raw local path, or unscoped evidence appears in the browser. If safe provider/fallback metadata is not already present in the API, the frontend does not claim it.
- Every production behavior starts with an observed failing test. Every task ends with focused tests, a diff check, and a no-push commit command.

---

## Task 1: Make canonical projection readiness the graph status source of truth

**Files:**

- Modify `backend/app/Dashboard/DashboardApiReader.php` methods `projectSummary`, repository summary mapping, `graphStatus`, and any private graph-status helper used by those methods.
- Modify `backend/tests/Feature/Dashboard/ProjectKickstartDashboardApiTest.php`.
- Modify or extend `backend/tests/Feature/Dashboard/MultiprojectDashboardApiTest.php` when it covers the same project summary response.

**Consumed/produced interfaces:**

Project and repository summary fields remain `graph_status: PipelineStatus`. A scope is current only when its project-owned canonical projection has `status=ready` and a non-null `active_graph_version`; `graph_version` identifies the origin and may differ. Every graph read and readiness check uses `active_graph_version`. A legacy `artifacts.artifact_type=graph_snapshot` is never a product-status source, so legacy-only projects are always `not_started`.

For a run, `DashboardApiReader::graphStatus(string $runId): string` first resolves the run’s project/repository scope and returns `complete` only when the matching projection is current by the exact `status=ready && active_graph_version != null` rule. A legacy snapshot alone returns `not_started` and never `complete`; existing running/failed status behavior remains unchanged.

- [ ] Add the exact observed-case fixture: create a project/repository, insert a ready canonical projection with active graph version/current marker, omit or detach its legacy graph snapshot artifact, request `GET /api/dashboard/projects/{project}`, and assert project and repository `graph_status` are `complete`.
- [ ] Run the RED command: `cd backend && php artisan test --filter='ProjectKickstartDashboardApiTest|MultiprojectDashboardApiTest'`; expected failure is that the canonical-ready/no-legacy-artifact case reports `not_started`. If the filter name is unavailable, run `cd backend && php artisan test tests/Feature/Dashboard/ProjectKickstartDashboardApiTest.php` and record the failing assertion.
- [ ] Add a private helper that defines current as exactly `status=ready && active_graph_version is not null`, queries `canonical_graph_projections` by project and source scope, and returns the active version for graph reads; do not add a table or migration.
- [ ] Update project/repository summary graph status to use that helper and the active version before any legacy-artifact lookup. Aggregate project scopes with precedence `failed` if any expected scope is failed; otherwise `processing` if any expected scope is processing; otherwise `complete` only if every expected scope is current; otherwise `not_started`, including zero-scope and legacy-only projects. Map each repository from its own source scope without borrowing another repository’s status.
- [ ] Add negative tests for stale, failed, foreign-project-ready, active-null, origin-versus-active version mismatch, zero-scope, and legacy-only projections; assert legacy-only is always `not_started`, only project-owned current scopes count as current, and every canonical read receives `active_graph_version` even when it differs from `graph_version`.
- [ ] Add a multi-scope matrix fixture covering failed plus processing, processing plus current, all current, incomplete/active-null, zero scopes, and legacy-only data; assert the exact project aggregation and per-repository statuses.
- [ ] Run the GREEN command: `cd backend && php artisan test tests/Feature/Dashboard/ProjectKickstartDashboardApiTest.php tests/Feature/Dashboard/MultiprojectDashboardApiTest.php`; expected result is current-version and multi-scope status coverage passing, legacy-only fixed at not_started, and no legacy artifact completing graph status.
- [ ] Check the exact diff with `git diff --check -- backend/app/Dashboard/DashboardApiReader.php backend/tests/Feature/Dashboard/ProjectKickstartDashboardApiTest.php backend/tests/Feature/Dashboard/MultiprojectDashboardApiTest.php`.
- [ ] Prepare the task commit command, without executing it during planning: `git add backend/app/Dashboard/DashboardApiReader.php backend/tests/Feature/Dashboard/ProjectKickstartDashboardApiTest.php backend/tests/Feature/Dashboard/MultiprojectDashboardApiTest.php && git commit -m "fix(dashboard): use canonical projection readiness for graph status"`.

## Task 2: Turn Kickstart into a numbered, contextual setup/status journey

**Files:**

- Modify `backend/app/Dashboard/DashboardApiReader.php` method `kickstart`.
- Modify `frontend/src/types/devboard.ts` project kickstart types.
- Modify `frontend/src/pages/ProjectDetailPage.tsx` constants `KICKSTART_STEP_LABELS`, `KICKSTART_STEPS`, `KickstartPanel`, metric labels, repository table labels, and workspace copy.
- Create `frontend/src/pages/kickstartModel.ts`.
- Create `frontend/src/pages/kickstartModel.test.ts`.
- Create `frontend/src/pages/ProjectDetailPage.test.tsx`.
- Modify `backend/tests/Feature/Dashboard/ProjectKickstartDashboardApiTest.php` for the response contract.

**Consumed/produced interfaces:**

Keep the existing step and pairing keys, and add:

    interface ProjectKickstartStep {
      key: ProjectKickstartStepKey;
      status: ProjectKickstartStepStatus;
      label: string;
      description: string;
      next_action: string;
      action_href: string | null;
      truth_source: "backend_verified" | "local_agent_reported";
    }

The backend labels are exact: `Project intake`, `Repository declaration`, `Hades workspace connection`, `Initial code import`, and `Knowledge review`. The visible graph metric and repository column label is `Code graph`. Keep `pairing.api_base=/api/plugin/v1` and `pairing.local_workspace_endpoint` as operational metadata for the existing local agent, but the browser does not call those endpoints or display credentials.

- [ ] Add a failing API assertion for the five ordered keys, exact human labels, descriptions, next actions, truth-source fields, and unchanged pairing API keys; run `cd backend && php artisan test tests/Feature/Dashboard/ProjectKickstartDashboardApiTest.php` and observe missing fields/old labels.
- [ ] Add backend step metadata with explicit actions: project intake is backend verified and complete; repository declaration points to the existing repository form; Hades workspace connection says the local Hades agent must report a linked workspace; Initial code import says the linked agent must submit genesis; Knowledge review points to project graph/wiki links and is backend verified.
- [ ] Update TypeScript kickstart types and `kickstartModel` to derive ordered numbered rows, status text, next action text, and truth-source text from API metadata while keeping the fallback for projects without a kickstart object.
- [ ] Run the RED command for the model: `cd frontend && CI=true corepack yarn test --watchAll=false --runInBand src/pages/kickstartModel.test.ts`; expected failure is the missing model and old opaque labels.
- [ ] Refactor `KickstartPanel` to render an accessible ordered list with number, label, description, status, next action, truth-source badge, and contextual action. Use `#repository-declaration` for the existing repository form and project links for graph/wiki review; do not link the browser to plugin endpoints.
- [ ] Replace visible `Genesis` with `Initial code import`, `Local workspace link` with `Hades workspace connection`, `Graph import` with `Code graph`, and `local-only Git sources` with a clear Hades workspace explanation while retaining technical keys in Machine/help text only.
- [ ] Add ProjectDetailPage jsdom assertions for numbered order, status explanations, backend/local truth labels, repository-form action, and no browser fetch to `/api/plugin/v1`. Keep existing role-gated repository creation behavior.
- [ ] Run the GREEN commands: `cd frontend && CI=true corepack yarn test --watchAll=false --runInBand src/pages/kickstartModel.test.ts src/pages/ProjectDetailPage.test.tsx` and `cd backend && php artisan test tests/Feature/Dashboard/ProjectKickstartDashboardApiTest.php`; expected result is all journey/API tests passing.
- [ ] Check the exact diff with `git diff --check -- backend/app/Dashboard/DashboardApiReader.php backend/tests/Feature/Dashboard/ProjectKickstartDashboardApiTest.php frontend/src/types/devboard.ts frontend/src/pages/ProjectDetailPage.tsx frontend/src/pages/kickstartModel.ts frontend/src/pages/kickstartModel.test.ts frontend/src/pages/ProjectDetailPage.test.tsx`.
- [ ] Prepare the task commit command, without executing it during planning: `git add backend/app/Dashboard/DashboardApiReader.php backend/tests/Feature/Dashboard/ProjectKickstartDashboardApiTest.php frontend/src/types/devboard.ts frontend/src/pages/ProjectDetailPage.tsx frontend/src/pages/kickstartModel.ts frontend/src/pages/kickstartModel.test.ts frontend/src/pages/ProjectDetailPage.test.tsx && git commit -m "feat(dashboard): clarify project kickstart journey"`.

## Task 3: Supply honest four-source context to Backlog Triage and expand read-only tools

**Files:**

- Modify `backend/app/Assistants/Agents/BacklogTriageAgent.php` instructions.
- Modify `backend/app/Assistants/BacklogTriageService.php` constructor, `contextSnapshot`, `evidenceRefs`, `promptForContext`, `structuredSuggestion`, and safe run metadata projection.
- Modify `backend/app/Assistants/Tools/QueryProjectGraphTool.php` (the actual repository path for the graph tool).
- Modify `backend/app/Assistants/AiAgentToolRegistry.php` controlled default handling.
- Modify `backend/database/seeders/DevBoardSeeder.php` backlog profile tools for fresh test data; do not edit an already-applied migration and do not add a migration.
- Modify `backend/tests/Feature/Assistants/AiAgentReadToolsTest.php`.
- Modify `backend/tests/Feature/Dashboard/BacklogTriageDashboardTest.php`.

**Consumed/produced interfaces:**

The effective read-tool set is exactly `read_project_summary`, `read_project_tasks`, `search_project_memory`, `search_wiki_revisions`, and `query_project_graph`. `AiAgentToolRegistry::forAgentKey` filters unknown/mutation names and merges these controlled defaults for `backlog_triage` so existing deployed profiles gain the two read tools without a migration. The seeder stores the same five names for fresh databases.

The context snapshot contains bounded project-scoped sections:

    project
    tasks(max 50)
    memory(max 20 relevant summaries)
    wiki(max 10 current page/revision titles, statuses, excerpts)
    graph(scopes max 10, projection metadata, stats, important symbols max 20)

Each section includes `source_status` and exact source refs used. A graph scope is eligible only when its canonical projection is current by `status=ready && active_graph_version != null`; all graph queries pass the active version, even when origin `graph_version` differs. Graph symbols come from Plan 1’s bounded dashboard explorer overview/search response and use public handles plus safe labels only. Memory ranking is deterministic: risk, then conflict, then incomplete, then normal; within that priority use term-overlap descending, recency descending, and id ascending. Symbol ranking is impact/degree descending, then handle ascending, and includes only current symbols with resolved safe labels. The prompt allows provided context and the five read-only tools; it no longer claims context is the sole possible source while omitting Wiki/graph tools. Agent instructions prohibit all mutations.

Evidence refs are typed and project-scoped: `project`, `task`, `memory`, `wiki_page`, `wiki_revision`, `graph_projection`, and `graph_artifact`. A ref is emitted only when that row or artifact was placed in context. Graph refs use safe dashboard graph/projection references and never a Neo4j external id. The response exposes safe `execution_mode` and `external_provider_call` only when already available; it never exposes provider key, URL, API key, or raw failure text.

`QueryProjectGraphTool` must serve only the current canonical projection through the public-handle contract. Its schema accepts a project/scope selector, bounded text query, and public-handle query fields only; `symbol_id` and raw identifier/path selectors are rejected. Its node/edge output is limited to current public handles and resolved safe labels plus bounded relationship metadata; it never returns a legacy snapshot, generic raw node id, path, properties object, `symbol_id`, or any other internal graph identifier. Apply the recursive allow-list at every nested level so adversarial service data cannot reintroduce those fields.

- [ ] Update the read-tool test expectation from three to five effective Backlog Triage tools and assert the exact names, no write tool, and no plugin credential argument. Run RED: `cd backend && php artisan test tests/Feature/Assistants/AiAgentReadToolsTest.php`; expected failure is the old three-tool assertion.
- [ ] Update the seed profile to the five effective read tools and add registry tests proving a stale stored list gains only controlled Wiki/graph read tools while mutation/unknown names remain filtered.
- [ ] Inject Plan 1’s `DashboardGraphExplorerService` into `BacklogTriageService` and implement bounded context readers for memory entries, current Wiki revisions/excerpts, and current canonical graph scope/projection metadata plus important symbols/statistics. Keep tasks capped at 50 and every other section at its stated limit. Apply the deterministic memory and symbol ranking defined above, and exclude non-current scopes and symbols without resolved labels.
- [ ] Replace the legacy/fallback behavior in `backend/app/Assistants/Tools/QueryProjectGraphTool.php` with a bounded current-canonical query that uses public handles and safe labels. Ensure a ready projection with `active_graph_version` different from origin `graph_version` is queried with the active version, and a legacy snapshot is never used as a result source.
- [ ] Add `AiAgentReadToolsTest` coverage for the graph tool: fixture current canonical nodes and relationships plus a legacy snapshot, assert only current public handles and resolved safe labels are returned, assert no generic raw id/path/properties/symbol_id/internal identifier or legacy snapshot field appears at any nesting depth, and assert the recursive sanitizer drops adversarial nested keys without scanning.
- [ ] Add a context test with rows in all four sources, assert every section is present, excerpts are bounded, foreign-project rows are absent, current active versions are used, memory order is risk/conflict/incomplete then term overlap then recency/id, symbol order is impact/degree then handle, and no context field contains a raw Neo4j id, plugin token, unresolved label, or unbounded Markdown body.
- [ ] Add a context test with rows in all four sources, assert every section is present, excerpts are bounded, foreign-project rows are absent, and no context field contains a raw Neo4j id, plugin token, or unbounded Markdown body.
- [ ] Change `BacklogTriageAgent::instructions` to say: review supplied task/memory/Wiki/graph context; use only the five named read-only tools when more project evidence is needed; never mutate tasks, Kanban, Wiki, runs, project state, or graph; return structured grounded output.
- [ ] Change `promptForContext` to allow supplied context plus those read-only tools, prohibit task ids absent from context/tool results, and require claims to identify evidence sources. Preserve the structured output schema.
- [ ] Extend `evidenceRefs` from project/tasks to actual memory, Wiki page/revision, graph projection/artifact, and task refs used in context. Add assertions that a missing source produces no fabricated evidence ref.
- [ ] Extend deterministic `structuredSuggestion` so fallback logic incorporates all four sources: vague/high-risk tasks, incomplete/risky memory, stale/conflicting Wiki pages, and unavailable/low-quality or high-impact graph metadata. Keep groups/recommendations task-linked, keep writes inside the existing suggestion/run transaction, and keep `approval_required=true`.
- [ ] Add a dashboard test that forces provider fallback, asserts four-source evidence refs and source-aware summary/risk output, then verifies tasks, memory, Wiki, graph projection rows, and Kanban state are unchanged.
- [ ] Add project-isolation tests with a second project’s task/memory/Wiki/graph rows; assert neither context nor evidence refs include foreign rows. Add a provider-failure test asserting safe execution mode without raw provider details in the API payload.
- [ ] Run GREEN commands: `cd backend && php artisan test tests/Feature/Assistants/AiAgentReadToolsTest.php` and `cd backend && php artisan test tests/Feature/Dashboard/BacklogTriageDashboardTest.php`; expected result is registry, bounded context, evidence, fallback, isolation, and no-mutation coverage passing.
- [ ] Check the exact diff with `git diff --check -- backend/app/Assistants/Agents/BacklogTriageAgent.php backend/app/Assistants/BacklogTriageService.php backend/app/Assistants/Tools/QueryProjectGraphTool.php backend/app/Assistants/AiAgentToolRegistry.php backend/database/seeders/DevBoardSeeder.php backend/tests/Feature/Assistants/AiAgentReadToolsTest.php backend/tests/Feature/Dashboard/BacklogTriageDashboardTest.php`.
- [ ] Prepare the task commit command, without executing it during planning: `git add backend/app/Assistants/Agents/BacklogTriageAgent.php backend/app/Assistants/BacklogTriageService.php backend/app/Assistants/Tools/QueryProjectGraphTool.php backend/app/Assistants/AiAgentToolRegistry.php backend/database/seeders/DevBoardSeeder.php backend/tests/Feature/Assistants/AiAgentReadToolsTest.php backend/tests/Feature/Dashboard/BacklogTriageDashboardTest.php && git commit -m "feat(assistant): ground backlog triage in four project sources"`.

## Task 4: Explain Triage inputs, evidence coverage, and actionable task links in the frontend

**Files:**

- Modify `frontend/src/types/devboard.ts` AssistantRun, AssistantEvidenceRef, and Backlog Triage payload types.
- Create `frontend/src/pages/backlogTriageModel.ts`.
- Create `frontend/src/pages/backlogTriageModel.test.ts`.
- Modify `frontend/src/pages/ProjectDetailPage.tsx` `BacklogTriagePanel` and helper list components.
- Create `frontend/src/pages/ProjectDetailPage.triage.test.tsx`.

**Consumed/produced interfaces:**

    export interface BacklogTriageEvidenceCoverage {
      task: number; memory: number; wiki_page: number; wiki_revision: number;
      graph_projection: number; graph_artifact: number; total: number;
    }
    export interface BacklogTriageViewModel {
      inputs: string[];
      coverage: BacklogTriageEvidenceCoverage;
      groups: Array<{ label: string; reason: string; task_ids: string[] }>;
      recommendations: Array<{ title: string; body: string; priority: string; task_ids: string[] }>;
      risks: string[];
      execution: string|null;
    }
    export function buildBacklogTriageViewModel(suggestion: AssistantSuggestion<BacklogTriagePayload>, projectId: string): BacklogTriageViewModel;

The model builds source coverage only from actual `suggestion.evidence_refs`. It states Tasks, Memory, Wiki, and Code graph inputs only when matching refs exist. Task ids in groups/recommendations become project-scoped links to `/projects/{projectId}/kanban?task={taskId}` only after validating that each id is represented by a task evidence ref; unsupported ids render as text. Provider/fallback copy is shown only from safe `run.execution_mode`/`run.external_provider_call` fields when present.

- [ ] Add failing model tests for all four source refs, missing Wiki/graph refs, duplicate refs, task-link filtering, and safe execution metadata; run RED: `cd frontend && CI=true corepack yarn test --watchAll=false --runInBand src/pages/backlogTriageModel.test.ts`; expected failure is missing model/types.
- [ ] Implement `buildBacklogTriageViewModel` with deterministic evidence counts, source labels, actual-ref gating, deduplicated task links, and bounded execution display.
- [ ] Refactor `BacklogTriagePanel` to show Inputs used, evidence coverage counts, approval-required status, and provider/fallback mode only when safely supplied. Do not say Wiki, memory, or graph was used when its evidence count is zero.
- [ ] Render groups and recommendations as separate accessible lists, include reason/body/priority, and render each evidence-backed task id as a Link to the project Kanban view. Keep risks and no-suggestion/failed-run states explicit.
- [ ] Add the component test with a suggestion containing task, memory, Wiki revision, and graph projection refs; assert counts, source copy, task links, recommendation text, approval-required status, and absence of an unbacked Wiki-used claim in the missing-ref fixture.
- [ ] Run the GREEN command: `cd frontend && CI=true corepack yarn test --watchAll=false --runInBand src/pages/backlogTriageModel.test.ts src/pages/ProjectDetailPage.triage.test.tsx`; expected result is evidence/view-model/component coverage passing.
- [ ] Run the focused frontend suite: `cd frontend && CI=true corepack yarn test --watchAll=false --runInBand src/pages/kickstartModel.test.ts src/pages/ProjectDetailPage.test.tsx src/pages/backlogTriageModel.test.ts src/pages/ProjectDetailPage.triage.test.tsx`.
- [ ] Check the exact diff with `git diff --check -- frontend/src/types/devboard.ts frontend/src/pages/backlogTriageModel.ts frontend/src/pages/backlogTriageModel.test.ts frontend/src/pages/ProjectDetailPage.tsx frontend/src/pages/ProjectDetailPage.triage.test.tsx`.
- [ ] Prepare the task commit command, without executing it during planning: `git add frontend/src/types/devboard.ts frontend/src/pages/backlogTriageModel.ts frontend/src/pages/backlogTriageModel.test.ts frontend/src/pages/ProjectDetailPage.tsx frontend/src/pages/ProjectDetailPage.triage.test.tsx && git commit -m "feat(frontend): explain contextual backlog triage evidence"`.

## Task 5: Run full verification, deploy safely, and perform public smoke/data-count checks

**Files/configuration inspected only:** `backend/routes/web.php`, `backend/routes/api.php`, `frontend/src/App.tsx`, `docker-compose.devboard.yaml`, `docker-compose.devboard.traefik.yaml`, `docs/runbooks/traefik-integration.md`, and the server `.env` Compose selection.

- [ ] Run focused backend tests: `cd backend && php artisan test tests/Feature/Dashboard/ProjectKickstartDashboardApiTest.php tests/Feature/Dashboard/MultiprojectDashboardApiTest.php tests/Feature/Assistants/AiAgentReadToolsTest.php tests/Feature/Dashboard/BacklogTriageDashboardTest.php`; expected result is zero failures.
- [ ] Run focused frontend tests: `cd frontend && CI=true corepack yarn test --watchAll=false --runInBand src/pages/kickstartModel.test.ts src/pages/ProjectDetailPage.test.tsx src/pages/backlogTriageModel.test.ts src/pages/ProjectDetailPage.triage.test.tsx`; expected result is zero failures.
- [ ] Run complete backend and frontend suites: `cd backend && php artisan test` and `cd frontend && CI=true corepack yarn test --watchAll=false --runInBand`; expected result is zero failures and no database reset.
- [ ] Build only after tests pass: `cd frontend && corepack yarn install --frozen-lockfile && CI=true corepack yarn build`; expected result is a successful production bundle.
- [ ] Check `git diff --check` and `git status --short`; confirm no migration, persistence identifier rename, task mutation, Wiki mutation, graph mutation, plugin route change, or Traefik service change is present.
- [ ] Deploy from `/home/ubuntu/dev-sandbox` with the server-selected Compose files using the canonical command `docker compose up -d --build --wait`; do not add/recreate/remove a `traefik` service, do not reset the database, and reload/restart backend PHP only if the changed image requires it.
- [ ] Verify public deep links for `/projects/<project-id>`, `/projects/<project-id>/graph`, `/projects/<project-id>/memory?view=human`, and `/projects/<project-id>/wiki?view=human` return the frontend shell without a 404, then record the deployed JavaScript bundle hash from the entry HTML.
- [ ] In an authenticated browser session verify the project summary says Code graph and complete for the canonical-ready/no-legacy-artifact fixture, Kickstart shows numbered actions and truth-source labels, and Backlog Triage shows only evidence-backed source counts/task links.
- [ ] Verify data-count invariants: Triage evidence refs are a subset of rows in the requested project; no foreign-project task/memory/Wiki/graph ref appears; no task status/column, Wiki revision, graph projection, or Kanban row changes after triage; suggestion status remains pending and `approval_required` remains true.
- [ ] Prepare the final task commit command, without executing it during planning: `git add backend frontend && git commit -m "feat(projects): clarify setup and contextual triage"`.
