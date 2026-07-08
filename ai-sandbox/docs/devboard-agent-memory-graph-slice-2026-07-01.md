# DevBoard Agent Memory Graph Slice - 2026-07-01

Status labels: `verified_from_code`, `developer_provided`, `inferred`, `needs_verification`.

## Scope

- `developer_provided`: Continue the DevBoard/Hades tranche without push/reset.
- `developer_provided`: Avoid the local Hades wiki push skill and avoid conflicts with delete-memory/manual-wiki-editor work.
- `verified_from_code`: This slice touches Laravel dashboard/Hades assistant surfaces and the internal Inertia React project page only.

## Implemented

- `verified_from_code`: `platon` and `aristoteles` agent-work now resolve to controlled backend profiles (`task_clarifier`, `backlog_triage`) instead of failing as unconfigured server agents.
- `verified_from_code`: Completed server-agent work writes both `project_memory_entries` and persistent `assistant_runs` / `assistant_messages` with `target_type = agent_work_item`.
- `verified_from_code`: Added `GET /api/dashboard/projects/{project}/agent-work/{workItem}` for clickable detail, including events, result memory, and persisted chat messages.
- `verified_from_code`: `GET /api/dashboard/projects/{project}/memory` accepts `domain=logbook|wiki|agent_notes` plus `q` / `query`, returns domain counts, and keeps the default memory-list behavior compatible.
- `verified_from_code`: Added controlled AI tools:
  - `search_project_memory`;
  - `query_project_graph`;
  - `write_wiki_revision`.
- `verified_from_code`: `write_wiki_revision` uses `WikiRevisionService`, so `verified_from_code` still requires evidence and writes `wiki.updated` audit logs.
- `verified_from_code`: A migration backfills the new memory/graph/wiki tools onto existing `socrate_supervisor` and `wiki_query` profiles.
- `verified_from_code`: The internal project React page now has agent chat submission, clickable agent-work detail, memory domain query, and graph links from repository rows.

## Verification

- `verified_from_code`: Focused Laravel tests passed: `39 passed / 280 assertions`.
- `verified_from_code`: Full Laravel suite passed: `282 passed / 2460 assertions`.
- `verified_from_code`: Pint `--test` passed for the tranche PHP files.
- `verified_from_code`: `npm run build` passed for the frontend.
- `verified_from_code`: `git diff --check` passed.

## Residuals

- `needs_verification`: No browser Playwright visual smoke was run for the internal Inertia project page.
- `needs_verification`: `backend/package.json` has no typecheck script and no local `tsc` binary, so frontend typecheck was not applicable; Vite production build was used.
- `needs_verification`: Backend agent responses still require a configured/enabled OpenAI-compatible provider and valid API key. Failed provider calls are persisted visibly as failed agent-work, not retried automatically.
- `inferred`: The current persistent chat base is implemented via agent-work records, not a standalone conversation inbox/threading product.
