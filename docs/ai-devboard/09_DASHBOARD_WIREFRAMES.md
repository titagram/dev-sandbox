# AI DevBoard Dashboard Wireframes

This document defines the required V1 dashboard information architecture and low-fidelity wireframes.

The dashboard is an operational tool, not a marketing surface. It should be quiet, dense, scannable, and optimized for repeated PM/developer/sysadmin use.

## Design Principles

Required dashboard qualities:

- PM-first home;
- clear distinction between local plugin facts and server state;
- visible run status and risk;
- fast navigation from task to run to repository to wiki and graph;
- source status visible on technical knowledge;
- no code-write controls for PM role;
- Admin token controls separated from PM task flow.

V1 uses Inertia React inside Laravel.

## Navigation

Primary navigation:

```text
Projects
Kanban
Runs
Wiki
Graph
Artifacts
Admin
System
```

Role visibility:

```text
PM: Projects, Kanban, Runs, Wiki, Graph, Artifacts
Developer: Projects, Kanban, Runs, Wiki, Graph, Artifacts
Sysadmin: Projects, Runs, Artifacts, System
Admin: all sections
Agent: no dashboard navigation by default
```

## Wireframe 1 - Kanban PM Home

Purpose: show work status first, with operational context close at hand.

```text
+--------------------------------------------------------------------------------+
| DevBoard                                      Project: TherapyHub        User   |
+-------------------+------------------------------------------------------------+
| Projects          | Board: Delivery                                             |
| Kanban            | Filters: Owner  Risk  Repository  Run status  Source status |
| Runs              +------------------------------------------------------------+
| Wiki              | Backlog     Ready      In Progress   Blocked     Review     |
| Graph             | --------    --------   -----------   --------    --------   |
| Artifacts         | Task A      Task C     Task E        Task G      Task I     |
| Admin             | risk: med   risk: low  risk: high    risk: high risk: med   |
| System            | repo: api   repo: web  repo: api     repo: api  repo: web   |
|                   | run: none   run: ok    run: active   blocked    run: failed |
|                   |                                                            |
|                   | Task B      Task D     Task F                              |
|                   | risk: low   risk: med  risk: med                           |
+-------------------+------------------------------------------------------------+
| Recent Runs: run_102 Genesis active | run_101 Delta failed | run_100 finished  |
| Project Health: 3 repos | 2 initialized | 1 needs Genesis | 4 stale wiki pages |
+--------------------------------------------------------------------------------+
```

Required interactions:

- drag task between columns;
- filter by owner, repository, risk, and run status;
- open task detail;
- open linked run detail;
- open repository project detail;
- show blocked items without opening every card.

Required task card fields:

```text
title
owner
risk_level
repository badges
linked run status
wiki/source status indicator
blocked flag
```

PM role restrictions:

- can edit task title, description, owner, priority, and column;
- can edit business wiki sections;
- cannot run plugin commands;
- cannot create code-write commands;
- cannot change repository policy.

## Wireframe 2 - Project Detail

Purpose: show repository initialization, knowledge, artifacts, and graph state.

```text
+--------------------------------------------------------------------------------+
| DevBoard                                      Project: TherapyHub        User   |
+-------------------+------------------------------------------------------------+
| Projects          | TherapyHub                                                  |
| Kanban            | Status: active | Git mode: local_only | Workspace: implicit  |
| Runs              +------------------------------------------------------------+
| Wiki              | Repositories                                                |
| Graph             | +-------------+-------------+------------+----------------+ |
| Artifacts         | | backend-api | Genesis: ok | Graph: ok  | Wiki: 2 stale  | |
| Admin             | | web-client  | Genesis: ok | Graph: ok  | Wiki: current | |
| System            | | infra       | Genesis: no | Graph: no  | Wiki: none    | |
|                   | +-------------+-------------+------------+----------------+ |
|                   |                                                            |
|                   | Recent Activity                                             |
|                   | run_204 Delta Sync failed, security warning                 |
|                   | run_203 Genesis Import active                               |
|                   | run_202 Wiki updated from local_analyzer                    |
|                   |                                                            |
|                   | Artifacts                                                   |
|                   | latest manifest | graph snapshot | security report | metrics |
+-------------------+------------------------------------------------------------+
```

Required sections:

- project summary;
- repository table;
- Genesis/Delta status;
- graph import status;
- wiki freshness;
- recent runs;
- latest artifacts;
- policy summary.

Required repository row fields:

```text
name
default_branch
git_mode
last_local_snapshot
genesis_status
delta_status
graph_status
wiki_status
risk_level
latest_run
```

Source labels:

```text
local_plugin_snapshot
local_analyzer
server_history
user_manual
ai_generated
```

The UI must not label a local snapshot as remote Git truth.

## Wireframe 3 - Run Detail

Purpose: make plugin/agent activity auditable.

```text
+--------------------------------------------------------------------------------+
| Run run_204                                      Status: failed | Risk: high    |
+--------------------------------------------------------------------------------+
| Project: TherapyHub | Repo: backend-api | Branch: feature/auth                 |
| Type: delta_sync    | Device: Gabriele MBP | Source: local_plugin_snapshot      |
+--------------------------------------------------------------------------------+
| Timeline                         | Summary                                      |
| 15:30 started                    | Changed files: 37                           |
| 15:31 context pulled             | Additions: 840 | Deletions: 120              |
| 15:32 local snapshot received    | Tests: failed                               |
| 15:33 artifact uploaded          | Graph: not promoted                         |
| 15:34 security warning           | Wiki: 3 pages stale                         |
| 15:35 failed                     |                                              |
+----------------------------------+---------------------------------------------+
| Artifacts                                                                        |
| delta manifest | diff summary | risk report | security report | graph snapshot  |
+--------------------------------------------------------------------------------+
| Risk Triggers                                                                    |
| migrations_changed | test_failures | large_multi_file_diff                      |
+--------------------------------------------------------------------------------+
| Evidence                                                                         |
| file hashes | command output | graph nodes | wiki revisions                         |
+--------------------------------------------------------------------------------+
```

Required sections:

- run header;
- local source labels;
- timeline;
- summary metrics;
- risk triggers;
- safety results;
- artifacts;
- test output;
- graph status;
- wiki status;
- audit events.

Required run actions:

```text
retry failed import, Admin/Developer only
download validated artifact, permission-gated
open linked task
open affected wiki page
open graph view
mark run reviewed
```

The run detail page must display failed validation without overwriting previous active repository state.

## Wireframe 4 - Admin Token Panel

Purpose: token lifecycle for plugin onboarding.

```text
+--------------------------------------------------------------------------------+
| Admin / Plugin Tokens                                                           |
+--------------------------------------------------------------------------------+
| Create Token                                                                    |
| Name: [Gabriele local plugin] Expiry: [90 days] Scopes: [default plugin scopes] |
| [Create token]                                                                  |
+--------------------------------------------------------------------------------+
| Active Tokens                                                                   |
| token prefix        user       device       scopes      last used     revoke    |
| devb_live_tok_01J   gabriele   MBP          plugin      today         [x]       |
+--------------------------------------------------------------------------------+
```

Token creation behavior:

- show full token once;
- include copy button;
- warn that token will not be shown again;
- never show secret after modal closes.

## Wireframe 5 - Wiki Page Source Banner

Purpose: make fact provenance visible.

```text
+--------------------------------------------------------------------------------+
| Wiki / Technical / Routes                                                       |
+--------------------------------------------------------------------------------+
| Source status: verified_from_code                                               |
| Source type: local_analyzer                                                     |
| Evidence: run_203, artifact route-index.json, snapshot snap_01J                 |
| Last observed: 2026-06-16 15:30                                                 |
+--------------------------------------------------------------------------------+
| # Routes                                                                        |
| ...                                                                            |
+--------------------------------------------------------------------------------+
```

Required source states:

```text
verified_from_code
developer_provided
ai_generated
needs_verification
stale
conflict_with_code
```

The banner must be visible without opening a separate metadata drawer.

## Responsive Rules

Desktop:

- sidebar plus dense main content;
- Kanban columns horizontally scroll when needed;
- run detail can use two-column timeline/summary.

Tablet:

- collapsible sidebar;
- Kanban columns still horizontal;
- project repository table can become stacked rows.

Mobile:

- sidebar becomes top menu;
- Kanban defaults to one selected column at a time;
- run timeline becomes a single column;
- source status banners remain visible.

## Dashboard Acceptance Criteria

Dashboard V1 is accepted when:

- PM home opens on Kanban;
- project detail shows repository Genesis and graph status;
- run detail shows timeline, artifacts, risk, safety, and source labels;
- Admin can create and revoke plugin tokens;
- wiki pages visibly show source status and evidence;
- local plugin state is never presented as remote Git truth;
- PM role cannot access plugin token creation or code-write controls.

