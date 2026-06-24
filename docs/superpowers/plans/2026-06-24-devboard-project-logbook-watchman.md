# DevBoard Project Logbook And Watchman Plan

**Purpose:** define the project logbook product surface and Watchman, the assistant that correlates changes with DevBoard evidence.

## Decisions

- `developer_provided`: DevBoard Server should expose a Logbook section in the frontend.
- `developer_provided`: the local plugin/agent should update the project logbook as work happens.
- `developer_provided`: the local plugin/agent should fetch previous logbook entries for context.
- `developer_provided`: the change-correlation assistant is named Watchman.
- `verified_from_code`: DevBoard currently has `run_events` and `audit_logs`, but no dedicated frontend Project Logbook product surface.
- `inferred`: Project Logbook should be distinct from `audit_logs`, `run_events`, and workspace Markdown logbooks.

## Project Logbook

The logbook is the readable project history: changes, decisions, risks, analysis, test results, follow-ups, and evidence links.

It should support:

- global view: `/logbook`;
- project view: `/projects/{project}/logbook`;
- contextual slices from task, run, repository, wiki page, and artifact views;
- append-only entries;
- browser reads through `/api/dashboard/...`;
- plugin/local agent append and fetch through `/api/plugin/v1/...`.

## Entry Shape

- project id;
- optional repository id;
- optional task id;
- optional run id;
- actor: user, plugin, agent, system, assistant;
- source: dashboard, codex, node-agent, python-plugin, analyzer, system;
- type: change, decision, test_result, risk, analysis, manual_note;
- title;
- body markdown;
- structured payload;
- evidence references;
- severity or risk;
- timestamps.

## Watchman

Watchman reads new logbook entries and correlates them with DevBoard-held evidence:

- diff summaries;
- analyzer output;
- AST/code graph imports;
- test results;
- quality reports;
- run events;
- wiki state;
- artifacts metadata.

It emits suggestions and warnings such as:

- missing tests;
- task intent does not match observed change;
- wiki likely stale;
- risky touched area;
- recurrent failure pattern;
- proposed follow-up task;
- proposed wiki update.

Watchman never mutates task, wiki, project state, code, local agent operations, or scanner runs without explicit human approval.

## Spec Gaps

- `project_logbook_entries` schema;
- logbook dashboard UI;
- plugin append/fetch endpoints;
- incremental fetch semantics;
- Watchman context packing;
- suggestion persistence and approval flow;
- retention and export policy.

