# Frontend Agent Coordination - 2026-06-30

## Purpose

Prepare the backend/project-side context for an upcoming discussion with the frontend agent. The frontend agent will start with questions. First answer those questions using verified local evidence, then use the prepared questions below to close gaps and record decisions.

This is a coordination document, not an implementation plan.

## Resume Command

```bash
cd /home/ubuntu/dev-sandbox && codex resume --last
```

`codex resume --last` is verified from local `codex resume --help`: it resumes the most recent interactive session without opening the picker.

## Source Status Legend

- `verified_from_code`: confirmed from local files or commands in this workspace.
- `developer_provided`: stated by the developer in chat or committed docs.
- `inferred`: strongly suggested by structure/logs, but not directly proven.
- `needs_verification`: must be checked before implementation.

## Current State

- `developer_provided`: The frontend agent will ask first. We must coordinate questions, answers, improvements, and future implementation conclusions in this document.
- `verified_from_code`: `ai-sandbox/config/project.yaml` is initialized for existing project `DevBoard`, root `.`, with Laravel 13, Inertia React, Python CLI/MCP plugin, PostgreSQL, Neo4j, and Docker Compose.
- `verified_from_code`: Current local environment detection reported Linux/x86_64 and Docker linux/amd64. `project.yaml` still records Darwin/arm64 from an earlier environment.
- `verified_from_code`: `python3 ai-sandbox/scripts/bootstrap_dependencies.py` failed because the vendored wheel set lacks `rapidfuzz` for `graphifyy==0.8.19`.
- `verified_from_code`: `python3 ai-sandbox/scripts/discover_project.py` wrote `ai-sandbox/docs/discovery.json` and `ai-sandbox/docs/discovery.md`, but the file sample includes `ai-sandbox/.venv`; use it only as a rough inventory.
- `verified_from_code`: The main repo currently shows `ai-sandbox/logbooks/LOGBOOK_PROJECT.md` modified.
- `verified_from_code`: The external public React frontend repo is `/home/ubuntu/emergent_devboard_frontend/frontend` and has a broad dirty worktree with many modified, deleted, and untracked files.
- `verified_from_code`: Backend dashboard API routes include project-scoped Memory and Agent Work:
  - `GET /api/dashboard/projects/{project}/memory`
  - `POST /api/dashboard/projects/{project}/memory`
  - `GET /api/dashboard/projects/{project}/agent-work`
  - `POST /api/dashboard/projects/{project}/agent-work`
  - `POST /api/dashboard/agent-work/{workItem}/cancel`
- `verified_from_code`: Plugin/local-agent API routes include Shared Memory Pack and local-agent work polling:
  - `GET /api/plugin/v1/projects/{project}/shared-memory-pack`
  - `GET /api/plugin/v1/agent-work-items`
  - `POST /api/plugin/v1/agent-work-items/{workItem}/claim`
  - `POST /api/plugin/v1/agent-work-items/{workItem}/heartbeat`
  - `POST /api/plugin/v1/agent-work-items/{workItem}/complete`
  - `POST /api/plugin/v1/agent-work-items/{workItem}/fail`
- `verified_from_code`: Dashboard Memory allows PM, Developer, and Admin writes; Sysadmin can read.
- `verified_from_code`: Dashboard Agent Work allows PM, Developer, and Admin writes/cancel; Sysadmin can read.
- `verified_from_code`: Plugin work polling only returns items assigned to `local_agent`; Socrates, Platon, and Aristoteles work items are dashboard records in this slice, not executable local work.
- `verified_from_code`: The Python plugin exposes MCP/CLI helpers for shared memory pack and work item list/claim/heartbeat/complete/fail.
- `verified_from_code`: The Node `agent/` package currently handles auth check, device registration, workspace link/refresh, and Git probing. It does not yet implement Agent Work polling.
- `verified_from_code`: The React frontend currently has API methods and pages for Memory, Agent Work, Ask, Engineering, Kanban task create/edit, Platon task clarification, and Backlog Triage.
- `verified_from_code`: React primary navigation now exposes `Projects`, `Work`, `Ask`, `Memory`, `Engineering`, and `Settings` by role, with legacy technical pages hidden but still reachable.
- `verified_from_code`: Ask is not live chat. It queues an Agent Work item and points users to Agent Work and Memory for progress/results.
- `verified_from_code`: Existing server-side assistant flows include Task Clarifier/Platon and Backlog Triage suggestions. Socrates, Wiki Query, Watchman, richer supervisor routing, and `soul.md` files remain future work.
- `needs_verification`: Whether the frontend agent is working in the same external React repo and same dirty worktree.
- `needs_verification`: Whether public container state still matches the latest external React source after any frontend-agent changes.

## Hard Boundaries To Keep

- Browser React must use `/api/dashboard/...`, not `/api/plugin/v1/...`.
- Plugin and local agents use `/api/plugin/v1/...` with token/device headers.
- Backend does not read source repositories directly in V1; local plugin/agent uploads evidence.
- Local plugin facts are not remote Git truth.
- PM role can manage tasks, Memory, and Agent Work requests, but must not receive code-write/plugin-token controls.
- Do not clean or reset the external frontend dirty worktree without explicit developer approval.

## Questions To Ask The Frontend Agent

1. Which frontend repo and branch are you working in? Is it exactly `/home/ubuntu/emergent_devboard_frontend/frontend`?
2. Which files have you changed since the latest Project Workspace slice, and which changes are yours versus pre-existing dirty work?
3. Are you targeting only the external public React app, or also Laravel/Inertia pages under `backend/resources/js`?
4. Which user flow are you implementing next: Work/Kanban, Ask, Memory, Agent Work, Engineering/Evidence, or project settings?
5. Do you need live Socrates chat, or is the current queued Ask model acceptable for this slice?
6. If you need live chat, what response model should the UI expect: streaming messages, persisted conversation, assistant suggestion, or Agent Work result?
7. Are the current TypeScript contracts enough for Memory and Agent Work, or do you need backend fields for events, leases, assignee/device details, or conversation messages?
8. Confirm string enums before changing UI: code currently uses `canceled`, not `cancelled`; `local_agent`, not `local-agent`; `completed_with_incomplete_memory` for incomplete completion.
9. How should Agent Work items assigned to Socrates, Platon, or Aristoteles behave in the UI, since plugin polling only executes `local_agent` items today?
10. Should Ask create `local_agent` work only for local execution requests, and use Socrates/Platon/Aristoteles items as server-side/intake records?
11. What UX should expose the required Memory payload fields: what changed, why, files/classes/methods, tests, skipped checks, risks, evidence refs?
12. Do you need Memory filters/search by kind, agent, repository, task, completeness, or text in this slice?
13. Do you need Agent Work event history in the frontend? Backend records events, but the current frontend type only models work item rows.
14. Should Engineering rename Artifacts to Evidence in visible copy now, or wait for a dedicated evidence redesign?
15. What visual QA can you run? Current logs record no Playwright visual smoke for the last public frontend slice.
16. What exact verification commands will you run before handoff: frontend tests, build, public bundle grep, browser smoke, Docker rebuild?
17. Do you expect backend changes from me after this discussion? If yes, list endpoint, request, response, permissions, and tests.

## Likely Frontend Questions And Prepared Answers

### What backend endpoints can I call for Memory?

Use dashboard API only:

```text
GET  /api/dashboard/projects/{project}/memory
POST /api/dashboard/projects/{project}/memory
```

Create payload fields: `repository_id`, `task_id`, `run_id`, `agent_key`, `kind`, `completeness`, `summary`, `payload`.

### What backend endpoints can I call for Agent Work?

Use dashboard API only:

```text
GET  /api/dashboard/projects/{project}/agent-work
POST /api/dashboard/projects/{project}/agent-work
POST /api/dashboard/agent-work/{workItem}/cancel
```

Create payload fields: `repository_id`, `task_id`, `assigned_agent_key`, `priority`, `title`, `prompt`, `payload`, `requires_memory_entry`.

### Can the browser call plugin work queue endpoints?

No. `/api/plugin/v1/...` is for plugin/local agent tokens and device headers. Browser UI should not use it.

### Can Socrates answer live today?

No verified live Socrates chat endpoint exists in this slice. Current Ask queues an Agent Work item. A real Socrates chat requires a backend conversation endpoint and persistence contract.

### What can local agents execute today?

The Python plugin has work queue MCP/CLI helpers. Backend plugin polling only lists work assigned to `local_agent`. The Node `agent/` package is currently limited to auth/device/workspace/Git probing and needs more implementation before it can poll and execute Agent Work.

### Who can write Memory and Agent Work?

Dashboard PM, Developer, and Admin can write. Sysadmin can read. Archived/deleted projects are blocked by lifecycle guards.

### What are the biggest frontend risks?

- External frontend worktree is already dirty.
- Ask may look like chat but is queued work, not live conversation.
- Agent names are product-facing but backend execution is only partly implemented.
- No visual browser smoke has been recorded for the latest slice.
- Some source-of-truth docs still describe older navigation, while recent code uses Project Workspace navigation.

## Discussion Log

Fill this during the coordination chat.

| Time UTC | Agent | Question / Topic | Answer | Decision | Follow-up |
| --- | --- | --- | --- | --- | --- |
| 2026-06-30 | frontend | Pending | Pending | Pending | Pending |

## Frontend Answers

Use this section for direct answers from the frontend agent.

1. Pending.

## Decisions To Implement Later

Use this section only for agreed conclusions, not guesses.

1. Pending.

## Improvements Proposed During Discussion

Use this section for ideas that need triage before implementation.

1. Pending.

## Verification Notes

- `python3 ai-sandbox/scripts/detect_environment.py` passed and reported Linux/x86_64 plus Docker linux/amd64.
- `python3 ai-sandbox/scripts/bootstrap_dependencies.py` failed on missing `rapidfuzz` wheel for `graphifyy==0.8.19`.
- `python3 ai-sandbox/scripts/discover_project.py` passed and wrote discovery artifacts.
- No backend/frontend tests were run for this coordination prep because no application code was changed.
