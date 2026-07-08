# DevBoard Project Kickstart Plan

**Purpose:** define how a new project becomes operational without server-side repository access.

## Decisions

- `developer_provided`: DevBoard must not require direct server-side access to the target source repository.
- `developer_provided`: repository linking is handled by the local plugin or future Node agent, not by a server-side Git clone.
- `developer_provided`: local agent precision matters; use deterministic probes/scripts instead of loose LLM inference.
- `developer_provided`: every push or relevant local Git state change must update server-side Git/workspace state.
- `verified_from_code`: current schema separates logical `repositories` from device-bound `local_workspaces`.
- `verified_from_code`: `local_workspaces` already store `display_path`, `current_branch`, `last_head_sha`, `dirty_status`, and `last_seen_at`.

## State Model

- `draft`: project shell exists.
- `awaiting_project_intake`: business/product details are missing.
- `awaiting_repository_declaration`: logical repositories are missing.
- `awaiting_local_workspace_link`: logical repository exists, but no local folder/device binding exists.
- `awaiting_genesis`: workspace is linked and ready for first analysis.
- `analyzing`: Genesis/import work is running.
- `knowledge_review`: generated wiki/graph/report state needs human review.
- `active`: project is usable.

## Responsibility Split

- PM/Admin: project goals, domain, users, constraints, priorities, risks, logical repository declaration.
- Local plugin/agent: folder selection, `.git` validation, branch/head/dirty probes, stack detection, command detection, protected/excluded path discovery, local workspace registration.
- DevBoard server: checklist state, validation, policy, project status, dashboard visibility, and coordination.
- Analyzer: deterministic Genesis/Delta artifacts after local workspace link.

## Local Probe Requirements

- Git: repository validity, branch, head SHA, dirty status, remote metadata when locally available.
- Runtime: package manager, framework hints, language versions.
- Commands: detected install/build/test/lint commands.
- Filesystem: protected paths, excluded paths, large/generated directories.
- Safety: secret/path preflight before uploads.

Probe outputs must distinguish `verified_from_code`, `developer_provided`, `inferred`, and `needs_verification`.

## Git State Synchronization

- update DevBoard when `HEAD` changes;
- update on branch changes;
- update on dirty-state changes;
- update after completed push;
- first implementation can use polling plus explicit refresh;
- later implementation can install opt-in local hooks such as `post-commit`, `post-checkout`, `post-merge`, and `post-push`.

## Spec Gaps

- database schema for kickstart checklist state;
- dashboard UX for PM/Admin and developer handoff;
- exact plugin/agent API endpoints for answers and probes;
- Git-state payload and validation rules;
- concurrency handling for multiple local workspaces bound to one logical repository.

