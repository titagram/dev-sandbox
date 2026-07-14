# Knowledge Human/Machine Views Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add human and machine audiences to project Memory and Wiki without changing their URLs, while preserving one shared fetch per page. Human views provide deterministic readable digests, explicitly identify partial pages, and can load more through the same bounded state used by Machine. Machine views retain complete technical metadata, sanitized evidence DTOs, links, and bounded pagination. Wiki provenance must reflect the database producer/source fields, including `hades_wiki_refresh`.

**Architecture:** Implement after Plan 1 and before Plan 3. The backend extends `DashboardApiReader::projectMemory`, `wiki`, and `wikiPage` plus `DashboardMemoryController::index` query parsing. No migration is expected because `project_memory_links`, `wiki_pages`, and `wiki_revisions` already contain the required data. The frontend keeps `/projects/:projectId/memory`, `/wiki`, and `/wiki/pages/:pageId`, reads `view=human|machine` with Human default, and shares one request state between tabs. `SafeMarkdown` uses pinned `react-markdown` plus `remark-gfm` with raw HTML disabled. No raw untrusted HTML is rendered and no `dangerouslySetInnerHTML` is added.

**Tech Stack:** Laravel 13, PHP 8.3, Pest, PostgreSQL, React 19, TypeScript 5.4, CRA/CRACO, Jest/jsdom, `react-markdown` 10.1.0, `remark-gfm` 4.0.1, Radix/Tailwind; Yarn 1.22.22.

## Global Constraints

- Execution order is Plan 1, then this Plan 2, then Plan 3. Use Plan 1’s public graph/source metadata and never reintroduce raw graph identifiers.
- Work in the existing checkout on `feature/canonical-graph-foundation-20260712` at `82f9f469` without a nested worktree. Every task has its own future commit command; do not execute commits, deploy, or mutate production while reviewing this plan.
- Keep existing URLs. `view=human` and `view=machine` are frontend audience state; the same data request is shared by both tabs and `view` is not sent as an API filter.
- Project scope and authorization remain enforced by existing dashboard reader/mutator roles. Memory writes, Wiki edits, refresh requests, and import actions retain their existing RBAC and project ownership checks.
- The project backend is the source of truth. Do not infer total counts from returned rows. The API must state `total`, `returned`, `limit`, `offset`, `has_more`, and `truncated`, plus full-dataset aggregates.
- Default memory page size is 50 and the maximum is 100. Offset is non-negative and capped at 5000; the API returns `next_offset` or null. Machine Load more requests the next bounded offset and never performs an unbounded query.
- Wiki Human renders only `body_markdown` through `SafeMarkdown`. Wiki Edit continues to show and submit raw Markdown in the existing textarea. Wiki Machine shows raw Markdown as text inside a code block plus complete sanitized metadata, `SafeEvidenceRef[]`, and `related_graph_nodes` with copy controls. Persisted `evidence_refs` remain server-only and are never serialized as stored input into a dashboard response.
- Dashboard DTOs recursively sanitize evidence through an explicit `SafeEvidenceRef` allow-list. A graph relation is represented only by `related_graph_nodes`, whose exact shape is `{scope_type, scope_id, handle, label?}`; non-graph refs expose only an allowed type, safe label, and project-scoped dashboard href. At every nesting depth, remove `node_id`, `graph_node_id`, `related_node_id`, `symbol_id`, `external_id`, `path`, every `properties` key, and every unknown nested object before serialization. If a graph ref has no scope while the project has multiple scopes, return at most one bounded unresolved relation with null scope/handle and no cross-scope scan; never guess a scope.
- The frontend must not use `dangerouslySetInnerHTML` for Markdown or provenance. Raw HTML in hostile Markdown must be treated as text or omitted by the Markdown renderer, never inserted into the DOM.
- Do not add a migration, reset a database, delete knowledge rows, or rename persistence identifiers. The Wiki-as-memory discriminant is `domain:"wiki", kind:"wiki", source:"wiki_revision", source_status:<status>`; do not overload `completeness` with a Wiki source status. `evidence_refs` is persisted storage only; the browser receives only the sanitized DTO.
- Every production behavior starts with an observed failing test. Each task ends with focused tests, a diff check, and a commit command; no task includes push.

---

## Task 1: Correct the backend memory envelope, Wiki-as-memory union, and Wiki provenance

**Files:**

- Modify `backend/app/Http/Controllers/Dashboard/Api/DashboardMemoryController.php`.
- Modify `backend/app/Dashboard/DashboardApiReader.php` methods `projectMemory`, `projectMemoryEntries`, `wikiMemoryEntries`, `memoryEntry`, `wiki`, `wikiPage`, `wikiSummary`, `wikiEvidence`, and `wikiRelatedRunIds`.
- Modify `backend/tests/Feature/Dashboard/ProjectMemoryDashboardApiTest.php`.
- Modify `backend/tests/Feature/Dashboard/WikiManualDashboardApiTest.php`.
- Modify `backend/tests/Feature/Dashboard/WikiRefreshDashboardApiTest.php`.

**Consumed/produced interfaces:**

The memory GET remains `GET /api/dashboard/projects/{project}/memory` and accepts `domain`, `q`/`query`, `limit`, and `offset`. It returns an envelope with `domain`, `query`, `total`, `returned`, `limit`, `offset`, `has_more`, `truncated`, `next_offset`, `domains`, `aggregates`, and `entries`. Aggregates are full-dataset counts:

    aggregates: {
      by_domain: Record<string, number>,
      by_kind: Record<string, number>,
      by_source: Record<string, number>,
      by_completeness: { complete: number, incomplete: number }
    }

Non-Wiki entries contain `domain:logbook|agent_notes`, stored `completeness`, source, ids, payload, and `links`. Wiki entries are a separate discriminant:

    {
      domain: "wiki",
      kind: "wiki",
      source: "wiki_revision",
      source_status: "verified_from_code"|"needs_verification"|"stale"|"conflict_with_code",
      wiki_page_id: string,
      wiki_revision_id: string|null,
      slug: string,
      links: Array<{target_type:string,target_id:string,href:string|null}>
    }

Memory links are read from `project_memory_links` by entry id, joined only through entries in the requested project, and returned with project-safe hrefs or null when a target has no dashboard route. Full-dataset aggregates count filtered rows before offset/limit and include Wiki rows for the requested project/domain/query.

Wiki summary/detail keep `slug`, `repository_id`, `current_revision_id`, `revision_id`, `producer`, original `source_type`, `source_status`, `author_user_id`, and `author_device_id`, but return only sanitized `evidence_refs: SafeEvidenceRef[]`, `related_run_ids`, and typed `related_graph_nodes: RelatedGraphNode[]`. `RelatedGraphNode` contains only `scope_type`, `scope_id`, `handle`, and optional resolved label; null scope/handle is reserved for the bounded unresolved case. The summary source type preserves `hades_wiki_refresh` and never maps it to `user_manual`. The sanitizer must remove forbidden identifiers and nested `properties` from adversarial fixtures at every depth before a browser DTO is built.

- [ ] Add failing Pest assertions for `total > returned` with `limit=1`, `has_more`, `next_offset`, all four aggregate maps, memory links, and a Wiki row whose `source_status` is stale while its `completeness` key is absent. Include a nested adversarial evidence fixture and assert the response contains only `SafeEvidenceRef` fields and `related_graph_nodes` fields, never forbidden identifiers or `properties`.
- [ ] Run the RED command: `cd backend && php artisan test --filter='ProjectMemoryDashboardApiTest|WikiManualDashboardApiTest|WikiRefreshDashboardApiTest'`; expected failure is missing envelope fields, Wiki source-status leakage through completeness, and missing provenance fields.
- [ ] Add bounded validation in `DashboardMemoryController::index`: `limit` integer 1–100 and `offset` integer 0–5000; return a 422 validation response before calling the reader when bounds fail.
- [ ] Refactor `DashboardApiReader::projectMemory` to calculate filtered totals and aggregates before applying offset-plus-limit, then merge the selected page without using returned-row count as total. Preserve existing domain/query matching.
- [ ] Add `project_memory_links` batch loading to `memoryEntry`/`projectMemoryEntries`; verify target ids belong to the same project before emitting a dashboard href and emit `href:null` for an unresolvable target.
- [ ] Make `wikiMemoryEntries` emit the Wiki discriminant and `source_status`, remove its use of `completeness`, and align its payload with the live Wiki fields.
- [ ] Extend Wiki selects to include `wiki_pages.slug`, `wiki_pages.repository_id`, `wiki_pages.current_revision_id`, `wiki_revisions.id`, `producer`, `source_type`, `author_user_id`, `author_device_id`, `evidence_refs`, and revision timestamps. Preserve `hades_wiki_refresh`.
- [ ] Implement evidence parsing with explicit project checks for run/artifact/page/node references, derive related runs from direct run refs and artifact-to-run refs, and derive only current public node handles through the Plan 1 resolver/public-handle service. Recursively allow-list `SafeEvidenceRef`, drop forbidden keys and nested `properties`, and when scope is absent for a multi-scope project emit one bounded unresolved `related_graph_nodes` item without scanning or guessing.
- [ ] Run the GREEN command: `cd backend && php artisan test --filter='ProjectMemoryDashboardApiTest|WikiManualDashboardApiTest|WikiRefreshDashboardApiTest'`; expected result is bounded envelope, discriminated Wiki memory, source preservation, links, and provenance tests passing.
- [ ] Check the exact diff with `git diff --check -- backend/app/Http/Controllers/Dashboard/Api/DashboardMemoryController.php backend/app/Dashboard/DashboardApiReader.php backend/tests/Feature/Dashboard/ProjectMemoryDashboardApiTest.php backend/tests/Feature/Dashboard/WikiManualDashboardApiTest.php backend/tests/Feature/Dashboard/WikiRefreshDashboardApiTest.php`.
- [ ] Prepare the task commit command, without executing it during planning: `git add backend/app/Http/Controllers/Dashboard/Api/DashboardMemoryController.php backend/app/Dashboard/DashboardApiReader.php backend/tests/Feature/Dashboard/ProjectMemoryDashboardApiTest.php backend/tests/Feature/Dashboard/WikiManualDashboardApiTest.php backend/tests/Feature/Dashboard/WikiRefreshDashboardApiTest.php && git commit -m "feat(knowledge): return bounded memory and wiki provenance"`.

## Task 2: Add shared AudienceViewTabs, SafeMarkdown, and JsonInspector primitives

**Files:**

- Modify `frontend/package.json`.
- Modify `frontend/yarn.lock` through the package-manager command only.
- Create `frontend/src/components/devboard/AudienceViewTabs.tsx`.
- Create `frontend/src/components/devboard/SafeMarkdown.tsx`.
- Create `frontend/src/components/devboard/JsonInspector.tsx`.
- Create `frontend/src/components/devboard/SafeMarkdown.test.tsx`.
- Create `frontend/src/components/devboard/AudienceViewTabs.test.tsx`.
- Create `frontend/src/components/devboard/JsonInspector.test.tsx`.

**Consumed/produced interfaces:**

    export type AudienceView = "human" | "machine";
    export interface AudienceViewTabsProps { value: AudienceView; onChange: (value: AudienceView) => void; label?: string; }
    export function SafeMarkdown(props: { markdown: string; className?: string }): JSX.Element;
    export function JsonInspector(props: { value: unknown; label?: string; defaultExpanded?: boolean; copyLabel?: string }): JSX.Element;

`AudienceViewTabs` uses accessible tab semantics or two labeled controls with `aria-pressed`, preserves active view through the caller’s `useSearchParams`, and never triggers a fetch. `SafeMarkdown` uses `react-markdown` with `remarkPlugins={[remarkGfm]}` and no raw-HTML plugin; it renders headings, GFM tables, fenced code, and links while hostile HTML is not executable or inserted as raw DOM. `JsonInspector` renders JSON as safe text nodes, provides an expandable disclosure, and copies `JSON.stringify(value, null, 2)` with visible copied/error state.

- [ ] Add exact dependencies `react-markdown:10.1.0` and `remark-gfm:4.0.1` to `frontend/package.json`, then run `cd frontend && corepack yarn add --exact react-markdown@10.1.0 remark-gfm@4.0.1`; expected RED result before implementation is module resolution failure for `react-markdown`, and Yarn 1.22.22 must update `yarn.lock` without a `--mode=skip-builds` flag.
- [ ] Add SafeMarkdown tests for `# Heading`, a GFM table, a fenced `typescript` block, a normal link, `<script>alert(1)</script>`, `<img onerror=alert(1)>`, and hostile raw `div`; expected failure before implementation is missing module/component resolution.
- [ ] Implement `SafeMarkdown` with `ReactMarkdown` and `remarkGfm`, set external links to `rel="noreferrer noopener"` and `target="_blank"`, and omit any raw-HTML plugin.
- [ ] Add AudienceViewTabs tests for Human default, Machine activation, keyboard activation, and exactly one callback per change. Add JsonInspector tests for collapsed/expanded JSON and copy-button status using a mocked clipboard.
- [ ] Run the GREEN command: `cd frontend && CI=true corepack yarn test --watchAll=false --runInBand src/components/devboard/SafeMarkdown.test.tsx src/components/devboard/AudienceViewTabs.test.tsx src/components/devboard/JsonInspector.test.tsx`; expected result is all primitive tests passing and hostile input absent from executable/raw DOM nodes.
- [ ] Run the safety check: `rg -n "dangerouslySetInnerHTML|rehype-raw" frontend/src/components/devboard/SafeMarkdown.tsx frontend/src/components/devboard/SafeMarkdown.test.tsx`; expected result is no matches.
- [ ] Check the exact diff with `git diff --check -- frontend/package.json frontend/yarn.lock frontend/src/components/devboard/AudienceViewTabs.tsx frontend/src/components/devboard/SafeMarkdown.tsx frontend/src/components/devboard/JsonInspector.tsx frontend/src/components/devboard/SafeMarkdown.test.tsx frontend/src/components/devboard/AudienceViewTabs.test.tsx frontend/src/components/devboard/JsonInspector.test.tsx`.
- [ ] Prepare the task commit command, without executing it during planning: `git add frontend/package.json frontend/yarn.lock frontend/src/components/devboard/AudienceViewTabs.tsx frontend/src/components/devboard/SafeMarkdown.tsx frontend/src/components/devboard/JsonInspector.tsx frontend/src/components/devboard/SafeMarkdown.test.tsx frontend/src/components/devboard/AudienceViewTabs.test.tsx frontend/src/components/devboard/JsonInspector.test.tsx && git commit -m "feat(frontend): add safe knowledge view primitives"`.

## Task 3: Add shared Memory Human/Machine views and the deterministic digest

**Files:**

- Modify `frontend/src/types/devboard.ts` memory types and add the discriminated Wiki memory type.
- Modify `frontend/src/api/devboardApi.ts`, `frontend/src/api/httpApi.ts`, `frontend/src/api/mockApi.ts`, and `frontend/src/api/mockData.ts`.
- Create `frontend/src/pages/memoryDigest.ts`.
- Create `frontend/src/pages/memoryDigest.test.ts`.
- Modify `frontend/src/pages/ProjectMemoryPage.tsx`.
- Create `frontend/src/pages/ProjectMemoryPage.test.tsx`.

**Consumed/produced interfaces:**

    export interface ProjectMemoryLink { target_type: string; target_id: string; href: string | null; }
    export interface ProjectMemoryRecord { id: string; domain: "logbook" | "agent_notes"; kind: MemoryEntryKind; completeness: MemoryCompleteness; source: ProjectMemorySource; links: ProjectMemoryLink[]; }
    export interface WikiMemoryRecord { id: string; domain: "wiki"; kind: "wiki"; source: "wiki_revision"; source_status: SourceStatus; wiki_page_id: string; wiki_revision_id: string | null; links: ProjectMemoryLink[]; }
    export type ProjectMemoryEntry = ProjectMemoryRecord | WikiMemoryRecord;
    export interface ProjectMemoryQuery { domain?: ProjectMemoryDomain | "all"; query?: string; limit?: number; offset?: number; }
    export interface ProjectMemoryResponse { domain: ProjectMemoryDomain | "all"; query: string | null; total: number; returned: number; limit: number; offset: number; has_more: boolean; truncated: boolean; next_offset: number | null; domains: Record<ProjectMemoryDomain, number>; aggregates: { by_domain: Record<string, number>; by_kind: Record<string, number>; by_source: Record<string, number>; by_completeness: Record<string, number> }; entries: ProjectMemoryEntry[]; }
    export interface MemoryDigestSection { key: "decisions" | "risks_incidents" | "needs_attention" | "verifications" | "implementation_handoffs" | "recent_activity"; title: string; entries: ProjectMemoryEntry[]; }
    export interface MemoryDigestMeta { partial: boolean; total: number; returned: number; next_offset: number | null; }
    export function buildMemoryDigest(entries: ProjectMemoryEntry[]): MemoryDigestSection[];

Human digest categories are deterministic: Decisions uses `decision`, Risks/Incidents uses `risk`/`incident`, Needs Attention uses incomplete entries plus Wiki stale/conflict, Verifications uses `verification`, Implementation/Handoffs uses `implementation`/`handoff`, and Recent Activity is sorted by `occurred_at` descending. Each digest item displays source, timestamp, and available real links. No LLM call is made.

- [ ] Add type fixtures proving a Wiki record cannot be treated as a record with `completeness` and that `source_status` is distinct. Run RED: `cd frontend && CI=true corepack yarn test --watchAll=false --runInBand src/pages/memoryDigest.test.ts`; expected failure is missing model/types.
- [ ] Implement `buildMemoryDigest` with stable category order, stable timestamp/id tie-breakers, no mutation, and explicit Wiki stale/conflict routing to Needs Attention.
- [ ] Extend `ProjectMemoryQuery` and `httpApi.getProjectMemory` to send only `domain`, `q`, `limit`, and `offset`; keep `view` out of the request. Update the mock response to match the backend envelope, including `total` greater than `entries.length`, Wiki source status, sanitized evidence DTOs, and a second page containing a high-priority digest item.
- [ ] Refactor `ProjectMemoryPage` to read `view` from `useSearchParams`, default invalid/missing values to Human, keep one shared request/accumulator state keyed by project/domain/query/limit/offset, and render `AudienceViewTabs` without separate tab fetches. Both Human and Machine consume the same accumulated rows and `next_offset`; when `projectId`, domain, or query changes, reset offset and accumulated rows before fetching. Merge pages through a `Map` keyed by entry id so overlapping pages are deduplicated for both views.
- [ ] Add Human view cards for all six digest sections, with source/time/link badges and a clear empty state. When `has_more` or `truncated` is true, show an explicit partial-digest message with `returned / total` and the shared accessible `Load more` action; the action advances `next_offset` using the same request state as Machine. Keep add/edit/delete/import controls gated by existing roles and hide mutation controls for Wiki entries.
- [ ] Add Machine view metrics showing `returned / total`, `limit`, `offset`, `has_more`, and `truncated`; retain the technical table, add expandable `JsonInspector` payload/provenance per row, copy JSON, real links, and the same shared `Load more` action. Preserve accumulated rows with the bounded `Map` deduplication and reset the map and offset on project, domain, or query changes.
- [ ] Ensure Machine table state uses `source_status` for Wiki and `completeness` only for non-Wiki records; never display a Wiki source status as complete/incomplete.
- [ ] Add `ProjectMemoryPage.test.tsx` cases that change project/filter/query and assert offset/rows reset, then return an overlapping page and assert exactly one row per `id` in both views. Start on Human page 1, assert the partial-digest message, activate `Load more`, and verify the prioritized risk/incomplete item that exists only beyond page 1 appears in the digest while the request count advances once; switch to Machine and assert it sees the same accumulated rows and offset. Run `cd frontend && CI=true corepack yarn test --watchAll=false --runInBand src/pages/ProjectMemoryPage.test.tsx` before the merge/reset implementation; expected failure is duplicate rows, stale rows, or a missing prioritized item after Load more.
- [ ] Run the GREEN command: `cd frontend && CI=true corepack yarn test --watchAll=false --runInBand src/pages/memoryDigest.test.ts src/pages/ProjectMemoryPage.test.tsx`; expected result is digest partial-state, shared-fetch, query-string, union, pagination, reset, deduplication, prioritized-beyond-page-one, and accessible-action coverage passing.
- [ ] Run the focused API suite: `cd frontend && CI=true corepack yarn test --watchAll=false --runInBand src/api/httpApi.test.ts src/api/mockApi.test.ts src/pages/memoryDigest.test.ts src/pages/ProjectMemoryPage.test.tsx`.
- [ ] Check the exact diff with `git diff --check -- frontend/src/types/devboard.ts frontend/src/api/devboardApi.ts frontend/src/api/httpApi.ts frontend/src/api/mockApi.ts frontend/src/api/mockData.ts frontend/src/pages/memoryDigest.ts frontend/src/pages/memoryDigest.test.ts frontend/src/pages/ProjectMemoryPage.tsx frontend/src/pages/ProjectMemoryPage.test.tsx`.
- [ ] Prepare the task commit command, without executing it during planning: `git add frontend/src/types/devboard.ts frontend/src/api/devboardApi.ts frontend/src/api/httpApi.ts frontend/src/api/mockApi.ts frontend/src/api/mockData.ts frontend/src/pages/memoryDigest.ts frontend/src/pages/memoryDigest.test.ts frontend/src/pages/ProjectMemoryPage.tsx frontend/src/pages/ProjectMemoryPage.test.tsx && git commit -m "feat(frontend): add human and machine memory views"`.

## Task 4: Add Wiki Human/Machine views and replace the unsafe Markdown renderer

**Files:**

- Modify `frontend/src/pages/WikiPageDetailPage.tsx` around the existing `renderMarkdown` function and edit textarea.
- Modify `frontend/src/pages/WikiPage.tsx`.
- Create `frontend/src/pages/WikiPageDetailPage.test.tsx`.
- Create `frontend/src/pages/WikiPage.test.tsx`.
- Modify `frontend/src/types/devboard.ts` Wiki provenance types and `frontend/src/api/mockData.ts` fixtures.

**Consumed/produced interfaces:**

    export interface SafeEvidenceRef { type: string; label: string; href: string | null; }
    export interface RelatedGraphNode { scope_type: string | null; scope_id: string | null; handle: string | null; label?: string | null; }

`SafeEvidenceRef` is recursively sanitized and contains no arbitrary nested object. `RelatedGraphNode` is exactly `{scope_type, scope_id, handle, label?}` and uses null scope/handle only for the bounded unresolved multi-scope case. `WikiPageSummary` and `WikiPageDetail` consume `slug`, `repository_id`, `current_revision_id`, `revision_id`, `producer`, `source_type`, `source_status`, `author_user_id`, `author_device_id`, `evidence_refs: SafeEvidenceRef[]`, `related_run_ids`, and `related_graph_nodes: RelatedGraphNode[]`. `WikiPageDetail` continues to provide `body_markdown`. Wiki index and detail both use `AudienceViewTabs` with Human default and `view=human|machine` in the same URL family.

- [ ] Add a failing detail-page test with headings, a GFM table, fenced code, a normal link, and hostile HTML; assert Human has semantic Markdown elements, hostile script/event input is not executable/raw DOM, and Edit still contains the original Markdown string.
- [ ] Run the RED command: `cd frontend && CI=true corepack yarn test --watchAll=false --runInBand src/pages/WikiPageDetailPage.test.tsx src/pages/WikiPage.test.tsx`; expected failure is the old unsafe renderer and missing audience controls.
- [ ] Replace `renderMarkdown` and its `dangerouslySetInnerHTML` call with `SafeMarkdown markdown={page.body_markdown}`. Keep the Edit dialog raw `Textarea value={contentMarkdown}` and submit path unchanged except for typed provenance fields.
- [ ] Add Wiki Human detail sections for title/slug, source status, producer, author/device, revision time, related runs/nodes, and safe evidence links. Related graph links use Plan 1 public handles/URLs and show a bounded unavailable state when a handle cannot resolve; missing scope on a multi-scope project stays unresolved without a scan.
- [ ] Add Wiki Machine detail sections for raw Markdown in a non-HTML code block, `JsonInspector` for complete page/revision metadata plus sanitized `SafeEvidenceRef[]`, related runs/nodes, and copy buttons for Markdown and the safe DTO. Machine never invokes `SafeMarkdown` for raw payload display and never serializes persisted evidence input.
- [ ] Add Wiki index Human cards with title, source status, producer, updated time, and evidence count; add Machine rows with slug, page/revision ids, source type/status, sanitized evidence summaries, and related links. Keep create/edit/refresh role checks and existing dashboard endpoints.
- [ ] Update mock Wiki pages to include `hades_wiki_refresh`, revision/producer/author/device fields, allow-listed `SafeEvidenceRef[]`, related runs, and related public node handles. Add nested forbidden-key fixtures and assert mock and live shapes match the recursive sanitizer contract.
- [ ] Run the GREEN command: `cd frontend && CI=true corepack yarn test --watchAll=false --runInBand src/pages/WikiPageDetailPage.test.tsx src/pages/WikiPage.test.tsx src/components/devboard/SafeMarkdown.test.tsx`; expected result is Human/Machine, safe Markdown, raw Edit, provenance, recursive evidence sanitization, unresolved multi-scope, mock parity, and copy-control tests passing.
- [ ] Run the safety check: `rg -n "dangerouslySetInnerHTML|renderMarkdown|rehype-raw" frontend/src/pages/WikiPageDetailPage.tsx frontend/src/pages/WikiPage.tsx frontend/src/components/devboard/SafeMarkdown.tsx`; expected result is no matches.
- [ ] Check the exact diff with `git diff --check -- frontend/src/pages/WikiPageDetailPage.tsx frontend/src/pages/WikiPage.tsx frontend/src/pages/WikiPageDetailPage.test.tsx frontend/src/pages/WikiPage.test.tsx frontend/src/types/devboard.ts frontend/src/api/mockData.ts`.
- [ ] Prepare the task commit command, without executing it during planning: `git add frontend/src/pages/WikiPageDetailPage.tsx frontend/src/pages/WikiPage.tsx frontend/src/pages/WikiPageDetailPage.test.tsx frontend/src/pages/WikiPage.test.tsx frontend/src/types/devboard.ts frontend/src/api/mockData.ts && git commit -m "feat(frontend): add safe wiki human and machine views"`.

## Task 5: Run full knowledge verification, deploy only after build success, and verify count/provenance invariants

**Files/configuration inspected only:** `backend/app/Dashboard/DashboardApiReader.php`, `backend/app/Http/Controllers/Dashboard/Api/DashboardMemoryController.php`, `frontend/package.json`, `frontend/yarn.lock`, `docs/runbooks/traefik-integration.md`, and the server `.env` Compose selection.

- [ ] Run focused backend coverage: `cd backend && php artisan test --filter='ProjectMemoryDashboardApiTest|WikiManualDashboardApiTest|WikiRefreshDashboardApiTest|DashboardGraphExplorerApiTest'`; expected result is zero failures.
- [ ] Run focused frontend coverage: `cd frontend && CI=true corepack yarn test --watchAll=false --runInBand src/components/devboard/SafeMarkdown.test.tsx src/components/devboard/AudienceViewTabs.test.tsx src/components/devboard/JsonInspector.test.tsx src/pages/memoryDigest.test.ts src/pages/ProjectMemoryPage.test.tsx src/pages/WikiPage.test.tsx src/pages/WikiPageDetailPage.test.tsx`.
- [ ] Run complete backend and frontend suites: `cd backend && php artisan test` and `cd frontend && CI=true corepack yarn test --watchAll=false --runInBand`; expected result is zero failures with no database reset.
- [ ] Build after all tests pass: `cd frontend && corepack yarn install --frozen-lockfile && CI=true corepack yarn build`; expected result is a successful production bundle using exact lockfile versions.
- [ ] Check the implementation diff with `git diff --check` and `git status --short`; confirm no migration, raw HTML renderer, dependency outside package manifest/lockfile, or unrelated persistence change is present.
- [ ] Deploy from `/home/ubuntu/dev-sandbox` with existing server-selected Compose files using `docker compose up -d --build --wait`; do not add or recreate Traefik and do not reset the database. Restart/reload backend PHP only if the changed backend image requires it; frontend deployment is allowed only after the build passes.
- [ ] Verify public app shells and deep links with `curl -fsS "$DEVBOARD_PUBLIC_BASE_URL/projects/<project-id>/memory?view=human"`, `curl -fsS "$DEVBOARD_PUBLIC_BASE_URL/projects/<project-id>/memory?view=machine"`, `curl -fsS "$DEVBOARD_PUBLIC_BASE_URL/projects/<project-id>/wiki?view=human"`, and `curl -fsS "$DEVBOARD_PUBLIC_BASE_URL/projects/<project-id>/wiki/pages/<page-id>?view=machine"` using the public base URL selected by the server `.env`.
- [ ] Verify authenticated API invariants in the browser/network inspector: for five returned rows and a larger dataset, `total` is greater than `returned`; Machine Load more advances `offset`; `has_more` and `truncated` agree; a Wiki row has `source:"wiki_revision"` and independent `source_status`; a refresh-produced page retains `source_type:"hades_wiki_refresh"`; no response contains raw HTML execution hooks.
- [ ] Prepare the final task commit command, without executing it during planning: `git add backend frontend && git commit -m "feat(knowledge): ship safe human and machine views"`.
