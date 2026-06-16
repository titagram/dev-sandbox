# AI DevBoard Vision

## Purpose

AI DevBoard is a centralized orchestration system for software teams working with human developers and AI coding agents such as Codex, Claude Code, and future local models.

The system is not meant to replace Git, the local developer workflow, or the developer's IDE/AI client. It acts as a shared operational memory, policy engine, artifact registry, and role-based dashboard around the existing development workflow.

## Core Idea

Developers continue to work locally on their own cloned repositories. Each developer uses a local DevBoard plugin/connector installed in their coding client or invoked as a CLI. The plugin reads the local working copy, runs deterministic analysis, generates artifacts, and communicates with the DevBoard backend.

The backend coordinates work, stores history, manages project knowledge, exposes dashboards, and distributes instructions/context packs to the plugin.

```text
Developer local repo
  -> Codex / Claude / local model
  -> DevBoard plugin
  -> DevBoard backend
  -> Git remote / PR / merge / deploy workflow
```

## Non-goals

DevBoard does not initially aim to:

- centrally run all coding agents;
- replace Git;
- require direct backend access to source code repositories;
- force every project to expose full source code to the backend;
- make LLMs the source of truth;
- turn local generated folders into the primary knowledge base.

## Guiding Principle

```text
Deterministic first.
Plugin reads local code.
Server stores shared memory.
Git certifies code history.
LLM assists but does not own the process.
```

## Main Components

- DevBoard backend
- DevBoard plugin / local connector
- Role-based dashboard
- PM Kanban board
- Central wiki / knowledge base
- Command registry
- Model registry
- Artifact storage
- Graph / code intelligence layer
- Genesis Import
- Delta Sync
- Audit log

## Backend Role

The backend acts as:

- orchestrator;
- hive mind;
- historical memory;
- policy engine;
- task/kanban manager;
- wiki and decision store;
- artifact registry;
- model/command registry;
- dashboard provider.

The backend does not need mandatory direct access to source code. It can operate from artifacts uploaded by plugins and optionally from Git metadata or read-only Git integrations.

## Plugin Role

The plugin acts as:

- authenticated local bridge;
- code reader;
- deterministic command runner;
- AST/code index generator;
- diff/snapshot collector;
- local AGENTS/context generator;
- reporting bridge for Codex/Claude/local models;
- artifact uploader.

The plugin is the only component that needs to inspect the developer's local realtime working copy.

## Knowledge Model

DevBoard maintains several kinds of knowledge:

- Git history and branch state, when available;
- Genesis Import snapshots;
- Delta Sync snapshots;
- wiki pages and revisions;
- task history;
- agent run history;
- local workspace snapshots;
- code intelligence artifacts;
- graph artifacts;
- model decisions and command outputs;
- audit records.

Every important fact should carry source metadata, such as:

- `remote_git`
- `local_plugin_snapshot`
- `local_plugin_diff`
- `local_analyzer`
- `server_history`
- `user_manual`
- `ai_generated`

## Multi-repository Support

A project may include multiple repositories, for example:

```text
therapyhub
  - backend-laravel
  - frontend-react
  - infra-deploy
  - docs
```

Tasks, runs, artifacts, and snapshots must therefore reference both a project and a repository.

## PM Experience

The PM primarily uses a Kanban-style dashboard.

The PM should see:

- tasks;
- status;
- blocked items;
- owner;
- linked branch/PR when available;
- agent runs;
- wiki status;
- risk level;
- what is in development versus what is in production.

The PM can edit business/wiki/task information but should not modify code.

## Developer Experience

The developer works locally with Codex/Claude and the plugin.

Typical flow:

```text
1. Authenticate plugin.
2. Link local repo to DevBoard project/repository.
3. Start a run from a task.
4. Plugin pulls instructions/context from backend.
5. AI client works on local code.
6. Plugin observes diff/snapshots/artifacts.
7. Plugin reports run result to backend.
8. Developer pushes branch through normal Git flow.
```

## Future Model Strategy

Not every task requires an LLM, and not every LLM task requires a frontier model.

The system should support:

- deterministic commands;
- compact/read-only model profiles;
- frontier/writer model profiles;
- configurable escalation rules;
- future local models powerful enough to receive broader permissions.

Model capability and permissions must be configurable by superadmin, not hardcoded permanently.
