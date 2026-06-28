# Server-Side Agent Registry

## Purpose

`developer_provided`: DevBoard server-side intelligence should use predefined controlled flows, not free-form runtime delegation.

This document records the first implementation slices for server-side agent hierarchy, model provider configuration, and the first SDK-backed specialist flow. DevBoard still does not execute local scans or mutate project state from assistants.

## Implemented Slice

- `verified_from_code`: `ai_model_providers` stores Admin-managed model provider configuration, including encrypted API keys.
- `verified_from_code`: `ai_model_profiles` stores the default text runtime profile used by controlled agents.
- `verified_from_code`: `ai_agent_profiles` stores controlled agent flow definitions.
- `verified_from_code`: `/admin/ai-agents` renders the React/Inertia Admin surface.
- `verified_from_code`: `/api/dashboard/admin/ai-agents` returns sanitized provider/model/agent registry data.
- `verified_from_code`: `/api/dashboard/admin/ai-model-providers/{provider}` updates provider display name, base URL, enabled state, and encrypted API key.
- `verified_from_code`: `/api/dashboard/admin/ai-model-profiles/{profile}` updates existing model profile runtime settings.
- `verified_from_code`: `/api/dashboard/admin/ai-agent-profiles/{agent}` assigns the default model profile and enabled state for controlled agent profiles.
- `verified_from_code`: `assistant_runs`, `assistant_messages`, and `assistant_suggestions` persist assistant executions and outputs.
- `verified_from_code`: `App\Assistants\Agents\TaskClarifierAgent` uses the official `laravel/ai` Agent contract with structured output.
- `verified_from_code`: `App\Assistants\AiAgentToolRegistry` exposes controlled Laravel AI SDK tools for `read_project_summary`, `read_task_detail`, and `search_wiki_revisions` when an agent profile allows them.
- `verified_from_code`: `App\Assistants\Tools\ReadProjectSummaryTool`, `ReadTaskDetailTool`, and `SearchWikiRevisionsTool` read only DevBoard database evidence and return bounded JSON payloads.
- `verified_from_code`: `/api/dashboard/tasks/{task}/assistant/clarify` invokes the Laravel AI SDK when the agent is faked in tests or when the configured provider is enabled with a stored key; otherwise it creates a deterministic fallback suggestion.
- `verified_from_code`: `/api/dashboard/assistant-suggestions/{suggestion}` lets Admin/PM users mark pending task clarification suggestions as `accepted` or `rejected`.
- `verified_from_code`: task detail pages render the latest task clarification suggestion and expose clarify/accept/reject actions to Admin/PM roles.
- `verified_from_code`: creating a newer Task Clarifier suggestion for the same task marks older pending task clarification suggestions as `superseded`, records resolver metadata, and writes `assistant.suggestion.superseded` audit logs.

## Agent Hierarchy

Seeded controlled profiles:

- `socrate_supervisor`: project-level read-only supervisor.
- `task_clarifier`: Kanban/task clarity specialist.
- `backlog_triage`: backlog quality and grouping specialist.
- `wiki_query`: wiki answer and freshness specialist.
- `watchman`: cross-signal warning and follow-up specialist.

`developer_provided`: the supervisor should route through controlled registry flows. It should not freely invent arbitrary sub-agent calls at runtime.

## Safety Boundary

Server-side agents may read DevBoard-held evidence and create suggestions. They must not directly:

- clone or read target repositories;
- execute shell, Git, scanner, or local developer-machine operations;
- mutate task, Kanban, wiki, project, backup, restore, local-agent, or scanner state without explicit human approval;
- expose provider API keys to the browser, prompts, logs, audit payloads, MCP responses, or downloaded artifacts.

`verified_from_code`: stored provider API keys are encrypted with Laravel `Crypt`; browser/API responses expose only `api_key_configured`, `api_key_last_four`, and `api_key_updated_at`.

`verified_from_code`: Admin model profile and agent profile updates write audit logs and do not expose encrypted provider API keys.

`verified_from_code`: current read-only SDK tools query DevBoard-held project, task, and wiki evidence only. They do not clone repositories, read local workspaces, call shells, call Git, or write project state.

`verified_from_code`: Task Clarifier test coverage uses `TaskClarifierAgent::fake([...])` and asserts the prompt sent to the Laravel AI SDK, so CI does not need a real model provider.

`verified_from_code`: accepting, rejecting, or superseding a Task Clarifier suggestion updates only the suggestion status/resolver metadata and writes an audit log. It does not mutate the task or Kanban board.

## Next Slice

`inferred`: the next implementation should broaden the same persistence and approval boundary:

1. PM approval workflow before task/Kanban mutation.
2. Additional specialist flows for backlog triage, wiki query, and watchman summaries.
3. Additional bounded read-only tools for run summaries, quality reports, artifacts, and agent profile registry reads.
