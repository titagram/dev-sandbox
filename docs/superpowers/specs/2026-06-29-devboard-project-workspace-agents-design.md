# DevBoard Project Workspace, Agents, And Shared Memory Design

**Date:** 2026-06-29
**Status:** draft design awaiting developer review

## Purpose

Define the product and architecture direction for making DevBoard usable as a project workspace, shared operational memory, server-side assistant surface, and local-agent coordination system.

This design responds to the current product gap: DevBoard exposes important technical surfaces such as Kanban, Runs, Graph, Artifacts, Quality, and Admin, but the user cannot yet clearly understand project scope, create basic Kanban work, ask agents questions, coordinate local agents, or use technical evidence with a clear operational purpose.

## Source Status

- `developer_provided`: PM and developer workflows must coexist in the same product instead of becoming separate apps.
- `developer_provided`: DevBoard should use a Project Workspace direction, with some PM/developer mode separation inside that workspace.
- `developer_provided`: Ask/Socrates and direct agent interrogation should be included in the design.
- `developer_provided`: The server should expose a todo-like queue for local agents. Local agents fetch, claim, execute, and complete queued work when possible.
- `developer_provided`: The old project ancestor used a logbook as shared memory. DevBoard must emulate and improve that model.
- `developer_provided`: Shared memory records must explain what changed, why it changed, and which files/classes/methods or other code units were involved.
- `developer_provided`: Socrates is the agent that knows what it does not know and acts as the interface across wiki, memory, tasks, evidence, and project state.
- `developer_provided`: Platon is the Kanban/task clarity agent. It asks yes/no questions in task context when PM task text is vague.
- `developer_provided`: Aristoteles analyzes memory and KPIs to find inefficiencies, probable causes, and early warnings.
- `developer_provided`: Agents must be directly chat-able, may talk to each other, may be event-triggered, and should later receive versioned `soul.md` definitions.
- `verified_from_code`: `docs/ai-devboard/00_VISION.md` defines DevBoard as shared operational memory, policy engine, artifact registry, and role-based dashboard around local developer workflows.
- `verified_from_code`: `docs/ai-devboard/03_DOMAIN_MODEL.md` defines Artifacts as stored outputs from plugin, backend, analyzer, or command activity.
- `verified_from_code`: `docs/ai-devboard/09_DASHBOARD_WIREFRAMES.md` currently lists Projects, Kanban, Runs, Wiki, Graph, Artifacts, Admin, and System as primary navigation.
- `verified_from_code`: `docs/ai-devboard/12_SERVER_SIDE_AGENT_REGISTRY.md` records current server-side agents as controlled flows with Task Clarifier and Backlog Triage implemented, and Socrate/Wiki Query/Watchman still future work.
- `verified_from_code`: Current public React navigation exposes technical sections directly and does not include a first-class Ask, Memory, or Agent Work workspace surface.
- `verified_from_code`: Current dashboard routes include task clarification and backlog triage actions, but no general assistant chat endpoint, no project memory endpoint, and no agent work queue endpoint.
- `verified_from_code`: Current plugin MCP surface lives in the Python plugin and is used for local CLI/MCP operations near a repository.
- `inferred`: Laravel can host a future server-side MCP surface, but the current repository does not yet expose a DevBoard server MCP server for project interrogation.

## Problem Statement

DevBoard currently has many valuable backend concepts, but the product surface is too implementation-model-first:

- users see Artifacts before understanding that they are evidence;
- users see Graph before it is filtered or tied to a debugging question;
- users see Runs without a complete work loop;
- users cannot create basic Kanban work from the Kanban board;
- users cannot always tell whether a page is global or project-scoped;
- server-side agents exist as scattered actions, not as a coherent assistant layer;
- the local agent has no durable server-side work queue and no shared memory preflight;
- the old logbook concept is not yet a first-class DevBoard product surface.

The product needs a workspace model that makes the core operational loop obvious:

```text
understand task
-> clarify scope
-> inspect shared memory
-> request local work when needed
-> execute near the repository
-> upload evidence
-> write memory
-> summarize verified and unverified state
```

## Product Model

DevBoard becomes a Project Workspace.

The selected project is the default context for normal work. Global views remain available for administrators and cross-project review, but project-scoped pages are the normal operating mode.

Primary workspace areas:

- **Work**: Kanban, tasks, backlog, owners, priority, risk, attachments, task clarity, local-agent work requests.
- **Ask**: Socrates chat, direct agent chats, assistant conversations, delegations, cited answers, generated suggestions, work item creation.
- **Memory**: the shared operational logbook. It stores what happened, why it happened, who/what did it, and what evidence supports it.
- **Engineering**: runs, graph, evidence/artifacts, quality, debugging, technical metrics.
- **Settings**: project policy, repositories, local agent pairing, model/agent profile overrides, protocol checklist.

The workspace topbar must always answer:

```text
Which project am I looking at?
Which repository or repository group is in scope?
Which source/snapshot/context version is being used?
Are there active local-agent work items or warnings?
```

Artifacts should be presented as Evidence in normal UI language. A raw artifact table may remain available for advanced inspection, but user-facing views should explain:

- what produced the evidence;
- what the evidence proves;
- which run, task, wiki page, graph area, quality report, or memory entry uses it;
- whether it is downloadable, validated, imported, stale, invalid, or purged.

## Project Operating Protocol

DevBoard should encode an assistive Project Operating Protocol inspired by the original workspace rules.

The protocol is not initially a hard blocker for every action. It provides warnings, checklists, and evidence requirements. Hard stops are reserved for security, revoked credentials, archived/deleted projects, cancelled work items, and policy violations.

Protocol responsibilities:

- classify the request before action;
- identify project source of truth;
- require local architecture pattern matching before implementation;
- map impact before changes;
- encourage small changes;
- require verification before completion;
- require memory/logbook updates;
- preserve security boundaries;
- distinguish verified facts, inferred facts, assumptions, and unknowns.

Each Agent Work Item should carry protocol metadata:

```text
task_classification
source_of_truth_to_read
impact_map_targets
required_checks
required_memory_update
allowed_local_actions
done_definition
```

The protocol assists Socrates, server agents, local agents, and humans. It should make skipped checks visible instead of silently blocking all incomplete work.

## Agent Model

### Socrates

Socrates is the primary project interface.

Responsibilities:

- answer project-scoped questions across tasks, memory, wiki, runs, evidence, graph summaries, quality, and assistant history;
- distinguish verified facts, probable inferences, unknowns, and missing evidence;
- route to specialist agents when useful;
- create suggestions, task drafts, memory-linked notes, or Agent Work Items when direct answers are not enough;
- say explicitly when a local sync, new evidence, human decision, or local-agent work item is needed.

Socrates must not:

- claim access to current local source code unless the evidence was uploaded;
- execute local scans directly;
- mutate task, Kanban, wiki, project, code, or local-agent state without approval;
- treat local plugin snapshots as remote Git truth.

### Platon

Platon is the Kanban and task clarity agent.

Responsibilities:

- trigger when tasks are created or materially updated;
- detect vague task text;
- ask binary yes/no questions in the task context;
- convert answers into clearer task description, acceptance criteria, scope, risk, and test hints;
- mark task clarity state as assistive metadata.

Platon should avoid open-ended prompts such as "explain better" as its first move. The default behavior is to ask concrete yes/no questions that move the PM toward a verifiable task.

Example:

```text
Task: bug tabella dipendenti

Platon questions:
- Does the bug affect table rendering? yes/no
- Does it happen when filtering or searching? yes/no
- Is the affected area the HR admin section? yes/no
- Is the expected result to show all active employees? yes/no
- Is the bug reproducible in production? yes/no
```

### Aristoteles

Aristoteles is the analysis and inefficiency agent.

Responsibilities:

- analyze project memory, KPIs, task history, work item flow, run history, failed checks, stale wiki state, and repeated warnings;
- find likely causes of inefficiency;
- forecast early risks;
- create warnings, improvement suggestions, and periodic review summaries;
- support direct chat for questions about metrics, bottlenecks, and recurring failures.

Aristoteles should not be the first MVP dependency for day-to-day task execution. It becomes most useful after Memory contains enough real entries.

### Direct Agent Chat And Agent Conversations

Users must be able to:

- chat with Socrates project-wide;
- chat with Platon inside a task;
- chat with Aristoteles about project metrics and memory;
- inspect relevant agent-to-agent delegations and conversations.

Agent-to-agent communication must be persisted and visible when it affects user-facing output.

Persisted conversation data should include:

```text
conversation_id
project_id
participants
trigger_event
target_type
target_id
messages
delegations
evidence_refs
created_suggestions
created_work_items
result_summary
```

### Future soul.md

Each agent should later receive a versioned `soul.md` contract:

```text
agents/socrates/soul.md
agents/platon/soul.md
agents/aristoteles/soul.md
```

Each file should define:

- mission;
- what the agent knows;
- what it must not pretend to know;
- allowed tools;
- trigger events;
- output schema;
- style of questions and answers;
- mutation limits;
- delegation rules;
- human approval rules.

## Shared Memory / Project Logbook

Memory is the first-class shared operational logbook.

It is distinct from audit logs and run events:

- audit logs answer "who did a sensitive thing?";
- run events answer "what happened inside this execution?";
- memory answers "what changed, why, what was learned, and what should the next human or agent know?"

Memory entries are append-only by default.

Required memory entry content for local-agent completed work:

```text
what changed
why it changed
task/work item link
repository scope
files changed
classes changed
methods/functions changed
domain entities changed
tests/checks run
checks skipped and why
risks or uncertainty
evidence refs
run ids
artifact/evidence ids
```

If a local agent completes an Agent Work Item without an adequate memory entry, completion should be marked with warning state such as `completed_with_incomplete_memory` or `needs_review`, not silently treated as clean.

## Agent Work Queue

The Agent Work Queue is the server-side todo list for local agents.

Socrates, users, and future server agents may create work items. Local agents fetch and execute them when possible.

Core flow:

```text
Socrates / user / event creates Agent Work Item
-> optional human approval
-> queued
-> local agent fetches available work
-> local agent performs preflight shared memory sync
-> local agent claims item with a lease
-> local agent heartbeats while running
-> local agent uploads runs/evidence/memory
-> local agent completes or fails item
```

Initial statuses:

```text
draft
pending_approval
queued
leased
running
needs_local_approval
completed
completed_with_incomplete_memory
failed
cancelled
expired
```

Initial work types:

```text
genesis_import
delta_sync
safe_test_profile
git_state_probe
route_inventory
quality_check
memory_append
investigation_summary
```

The queue must not be arbitrary shell execution. Work items reference allowlisted local profiles and input payloads.

## Shared Memory Pack And Local Agent Preflight

Before starting a work item, the local agent must fetch a Shared Memory Pack.

The pack should include:

```text
project_context_version
project_summary
repository_status
active_work_items
recent_completed_work
recent_runs
latest_snapshots
recent_memory
open_suggestions
wiki_freshness
aristoteles_or_watchman_warnings
protocol_checklist
task_changes_since_work_item_creation
```

The local agent compares:

```text
work_item.context_version
server.current_project_context_version
local_workspace.observed_git_state
```

Preflight result values:

```text
clear_to_start
warning_stale_context
warning_parallel_work
requires_delta_sync
requires_human_review
blocked_by_policy
```

Assistive warnings:

- task changed after work item creation;
- another local agent recently completed work in the same repo or task area;
- another work item is active in the same repo or task area;
- wiki evidence is stale or conflicts with code;
- local workspace state is older than the latest DevBoard-observed snapshot;
- protocol-required checks are missing.

Hard stops:

- project is archived or deleted;
- token or device is revoked;
- work item is cancelled;
- policy forbids the requested action;
- a risky local action lacks required approval;
- blocked security findings are not explicitly approved.

## Backend And MCP Architecture

### Assistant Gateway

The Assistant Gateway is the single backend path for server-side agent interactions.

It is used by:

- frontend Ask;
- task-level Platon panels;
- Aristoteles analysis entrypoints;
- server MCP tools;
- event triggers;
- scheduled jobs.

Responsibilities:

- authorize user/device/project access;
- pack context;
- call the selected agent;
- persist conversations, messages, delegations, suggestions, and work items;
- attach evidence refs;
- emit memory entries or memory-linked suggestions when appropriate.

### Server MCP

DevBoard needs a server-side MCP surface separate from the Python plugin MCP.

The existing plugin MCP is local and repository-adjacent. It should continue to handle operations that must run near a local checkout, such as Genesis, Delta, artifact upload, wiki revision upload, and run lifecycle reporting.

The server MCP is control-plane oriented. It lets authorized external assistants interrogate DevBoard-held project state without pretending to see live local source code.

Initial server MCP tools:

```text
devboard_project_ask
devboard_get_project_memory
devboard_search_project_memory
devboard_list_agent_work_items
devboard_create_agent_work_item
devboard_get_task_context
devboard_run_platon
devboard_run_aristoteles
```

`devboard_project_ask` must use the same Assistant Gateway as the frontend Ask UI. UI and MCP must not become separate assistant systems.

### Dashboard API

Initial dashboard API directions:

```text
POST /api/dashboard/projects/{project}/ask
GET  /api/dashboard/projects/{project}/agent-conversations
GET  /api/dashboard/agent-conversations/{conversation}

GET  /api/dashboard/projects/{project}/memory
POST /api/dashboard/projects/{project}/memory

GET  /api/dashboard/projects/{project}/agent-work-items
POST /api/dashboard/projects/{project}/agent-work-items
POST /api/dashboard/agent-work-items/{item}/approve
POST /api/dashboard/agent-work-items/{item}/cancel

POST /api/dashboard/tasks/{task}/agents/platon
POST /api/dashboard/projects/{project}/agents/aristoteles/analyze
```

### Plugin / Local Agent API

Initial plugin API directions:

```text
GET  /api/plugin/v1/projects/{project}/shared-memory-pack
GET  /api/plugin/v1/agent-work-items
POST /api/plugin/v1/agent-work-items/{item}/claim
POST /api/plugin/v1/agent-work-items/{item}/heartbeat
POST /api/plugin/v1/agent-work-items/{item}/complete
POST /api/plugin/v1/agent-work-items/{item}/fail
```

Browser UI must not call `/api/plugin/v1`.

## UX Requirements

### Work

Kanban must support basic work management:

- create a task;
- edit title, description, owner, priority, risk, repository scope, and acceptance criteria;
- move task between columns;
- attach files and images;
- ask Platon for task clarity;
- create Agent Work Items from a task.

Task detail must clearly show:

- project;
- repository scope;
- clarity state;
- Platon questions and answers;
- linked work items;
- linked runs;
- linked memory entries;
- assistant suggestions;
- verification/evidence.

### Ask

Ask is project-scoped by default and powered by Socrates.

It must support:

- project-wide chat;
- optional target context such as task, repository, run, wiki page, memory entry, or evidence item;
- responses with evidence refs;
- explicit "verified / probable / unknown / needs local sync" sections;
- visible delegation to Platon or Aristoteles when used;
- creation of task drafts, suggestions, memory notes, and Agent Work Items.

### Memory

Memory is a first-class workspace tab.

It must support:

- filtering by repository, task, run, work item, actor, entry type, risk, and date;
- linking from tasks, runs, evidence, and Ask answers;
- appending manual notes;
- reading local-agent completion summaries;
- showing whether entries are human, local-agent, server-agent, plugin, analyzer, or system generated.

### Engineering

Engineering exists for metrics, debugging, and evidence inspection when something needs technical investigation.

It must include:

- Runs tied to tasks, work items, memory, and evidence;
- Graph with filters for project, repository, run, task, node type, source status, and risk;
- Evidence instead of raw Artifacts as the primary label;
- Quality and debugging panels.

Engineering views should answer "what can I diagnose here?" instead of presenting raw tables without purpose.

## MVP Order

### MVP 1: Project Workspace And Task Basics

Goal: users understand project scope and can perform basic Kanban work.

Include:

- project selector/topbar;
- project-scoped routing as default;
- Kanban create task;
- minimal task edit;
- clear global vs project view labels;
- readable breadcrumbs.

### MVP 2: Memory And Agent Work Queue

Goal: shared memory and server-to-local-agent coordination.

Include:

- `project_memory_entries`;
- Memory workspace UI;
- `agent_work_items`;
- Agent Work UI;
- dashboard create/approve/cancel endpoints;
- plugin fetch/claim/heartbeat/complete/fail endpoints;
- Shared Memory Pack;
- completion requires memory entry or warning state.

### MVP 3: Socrates And Ask

Goal: first-class project interrogation.

Include:

- Ask workspace tab;
- Socrates through Assistant Gateway;
- cited answers over memory/wiki/tasks/runs/evidence metadata;
- Agent Work Item creation from Ask;
- persisted conversations;
- server MCP `devboard_project_ask`.

### MVP 4: Platon Kanban Agent

Goal: prevent vague task handoff.

Include:

- trigger on task created/updated;
- Platon panel in task detail;
- yes/no questions;
- PM answers;
- clarity state;
- acceptance criteria suggestion;
- assistive warning on moving unclear tasks to Ready.

### MVP 5: Engineering With Purpose

Goal: make technical views useful for debugging and metrics.

Include:

- Graph filters and navigation;
- Evidence view replacing raw Artifacts as primary language;
- run-to-memory and run-to-work-item linking;
- quality/debug panels.

### MVP 6: Aristoteles Analytics

Goal: identify inefficiencies and early risk patterns.

Include:

- metrics from memory, task, run, and work item data;
- pattern detection;
- warnings and suggestions;
- periodic project review;
- direct Aristoteles chat.

### MVP 7: Agent soul.md Contracts

Goal: version and govern agent behavior.

Include:

- `agents/socrates/soul.md`;
- `agents/platon/soul.md`;
- `agents/aristoteles/soul.md`;
- prompt/template versioning;
- audit trail for soul changes.

## Recommended First Implementation Plan Scope

The first implementation plan should cover MVP 1 and MVP 2 only.

Rationale:

- without project context and task creation, the product remains hard to use;
- without Memory and Agent Work Queue, Socrates has no reliable shared operational substrate;
- Socrates and Platon become much safer and more useful after the workspace, memory, and work queue foundations exist.

The next plan can cover MVP 3 and MVP 4.

Aristoteles should wait until Memory has enough data to analyze.

## Acceptance Criteria For This Design

- DevBoard has a clear Project Workspace product direction.
- PM and developer workflows coexist without hiding either one.
- Artifacts are reframed as Evidence for normal user-facing workflows.
- Socrates, Platon, Aristoteles, and the Local Agent have distinct responsibilities.
- The Local Agent remains the only actor that sees live local source code.
- The server can request local work only through Agent Work Queue, not direct machine control.
- Local agents must fetch shared memory before work.
- Completed local work must write an exhaustive memory/logbook entry.
- Server MCP and plugin MCP are separate surfaces with separate responsibilities.
- Project Operating Protocol is assistive first, with hard stops only for policy and security.
- `soul.md` contracts are captured as a future design requirement.

## Open Decisions For Implementation Planning

- Exact schema names and migration breakdown for memory, work items, conversations, and Platon answers.
- Whether Project Workspace should be implemented first in the external React app only or also mirrored in Inertia pages.
- Whether the first Memory UI should support rich Markdown editing or read-first append-only notes.
- Exact local-agent allowlisted profiles for MVP 2.
- Exact algorithm for `project_context_version`.
- Whether work item completion without adequate memory should be allowed as a warning or require human review.
- Which server MCP package/runtime should host the first DevBoard server MCP surface.
- Whether `Task Clarifier` should be renamed/migrated to Platon immediately or kept as compatibility alias during transition.
