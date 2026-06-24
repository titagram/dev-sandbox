# DevBoard Project Lifecycle Policy Design

**Date:** 2026-06-24
**Status:** approved design for implementation planning

## Purpose

Define the reversible project archive and reversible soft-delete policy for the multiproject DevBoard dashboard before implementation.

This spec covers lifecycle behavior only. It does not implement backend routes, migrations, frontend controls, hard-delete, purge jobs, repository CRUD, project kickstart, task attachments, backup export, or restore backup.

## Source Status

- `developer_provided`: Project lifecycle must use reversible archive plus reversible soft-delete.
- `developer_provided`: Admin and PM users can perform archive, restore, and soft-delete actions.
- `developer_provided`: Browser UI must use `/api/dashboard/...`; `/api/plugin/v1` remains reserved for CLI/MCP/local agent clients.
- `developer_provided`: DevBoard backend is a control plane and must not contain target repository source code.
- `verified_from_code`: Current project rows already have `projects.status`, `projects.slug`, and project-linked Kanban, runs, repositories, wiki pages, and artifacts.
- `verified_from_code`: Current dashboard project create/edit uses `/api/dashboard/projects` and `/api/dashboard/projects/{project}`.
- `verified_from_code`: Current code primarily uses Laravel query builder instead of a project Eloquent model, so lifecycle filtering must be explicit in each affected reader/controller.

## Lifecycle States

Projects use `projects.status` for lifecycle state:

- `active`: normal operational state. Visible in project lists and overview by default. Mutating project, Kanban, run, kickstart, and agent-driven operations may proceed according to their normal permissions.
- `archived`: reversible read-only state. Hidden from default project lists and operational overview. Visible through an archived filter. Project summary, historical runs, wiki, artifacts, and Kanban history remain readable. Operational mutations are blocked except restore.
- `deleted`: reversible trash state. Hidden from all default lists and normal resource views. Visible only to Admin and PM through a trash filter. Lifecycle restore remains available. Operational reads and mutations outside trash/lifecycle flows are blocked.

No hard-delete is exposed in the browser UI. No automatic purge or retention job is included in this policy slice.

## Data Model

Add explicit lifecycle metadata to `projects`:

- `archived_at` nullable timestamp
- `archived_by_user_id` nullable user id
- `deleted_at` nullable timestamp
- `deleted_by_user_id` nullable user id
- `restored_at` nullable timestamp
- `restored_by_user_id` nullable user id

`deleted_at` is lifecycle metadata, not a signal to add Laravel `SoftDeletes` global model behavior in this slice. Query builder filters remain explicit.

Lifecycle reasons are not stored in dedicated project columns. A request may include optional `reason`, and the backend stores that value in audit log payloads.

Project `slug` remains reserved for archived and deleted projects. A new project cannot reuse the key of an archived or deleted project because restore must not create URL, agent, audit, or historical-reference conflicts.

## Transitions

Allowed transitions:

- `active -> archived`
- `active -> deleted`
- `archived -> active`
- `archived -> deleted`
- `deleted -> active`

Rejected transitions:

- `archived -> archived`
- `deleted -> deleted`
- `deleted -> archived`
- `active -> active`

Rejected transitions return `409 Conflict` with the stable error code `invalid_project_lifecycle_transition`.

Restore from either `archived` or `deleted` sets `status = active` and records `restored_at` / `restored_by_user_id`. Historical `archived_at`, `archived_by_user_id`, `deleted_at`, and `deleted_by_user_id` values remain as audit-oriented metadata. Current lifecycle state is always determined by `projects.status`.

## Permissions

Admin and PM can:

- archive a project;
- restore an archived project;
- soft-delete an active or archived project;
- restore a deleted project from trash.

Viewer cannot mutate lifecycle state.

This lifecycle permission is intentionally broader than the current minimal project create/edit slice, where identity create/edit is Admin-only. Project-level permissions and role overrides remain a separate design gap.

## Active Work Blocks

Archive and soft-delete must be blocked when the project has active work in progress. Restore is always allowed.

The implementation should treat active work as:

- any `runs.status` not in `finished`, `failed`, or `aborted`;
- active upload/import rows tied to the project, including Genesis or Delta records in upload/active states if those records are not already represented by a non-terminal run.

Blocked archive/delete returns `409 Conflict` with the stable code `project_lifecycle_blocked` and a compact summary payload:

```json
{
  "error": {
    "code": "project_lifecycle_blocked",
    "message": "Project has active work.",
    "details": {
      "runs": 2,
      "uploads": 1
    }
  }
}
```

The response must not expose target source paths or source-code content.

## Dashboard API Contract

Browser lifecycle endpoints live under `/api/dashboard/...`:

- `POST /api/dashboard/projects/{project}/archive`
- `POST /api/dashboard/projects/{project}/restore`
- `POST /api/dashboard/projects/{project}/delete`

Payload:

```json
{
  "reason": "Optional human-readable reason."
}
```

Validation:

- `reason` is optional;
- when provided, `reason` is a string with a maximum length of 500 characters;
- unknown project ids return `404`;
- forbidden roles return `403`;
- invalid transitions or active-work blocks return `409`.

Project list filtering:

- `GET /api/dashboard/projects` defaults to `status=active`;
- `GET /api/dashboard/projects?status=archived` returns archived projects to authenticated dashboard users;
- `GET /api/dashboard/projects?status=deleted` returns trash projects only to Admin and PM;
- `status=all` is not needed for the first implementation slice.

Project detail and project-scoped resources:

- active projects behave normally;
- archived projects remain readable by direct dashboard links;
- deleted projects are not served by normal detail/resource endpoints. Trash list rows provide enough metadata for restore.

## Plugin And Local Agent Contract

`/api/plugin/v1` remains a local plugin/agent namespace, not a browser UI namespace.

Plugin/local agent behavior:

- project listing should expose active projects only by default;
- run start, heartbeat, artifact upload/finalize, repository instructions, wiki revision, and local workspace registration must reject archived or deleted projects;
- archived projects should return a clear conflict response because the project exists but is read-only;
- deleted projects return `404` to plugin/local agent mutating operations so deleted project detail is not exposed through the plugin namespace.

The backend must not delete or mutate target repository source code when a project is archived or soft-deleted.

## Frontend UX

The operational UI remains the external React/emergent frontend. No new Inertia page is the primary UI for this flow.

On `/projects`:

- default view shows active projects;
- status filter exposes Active, Archived, and Trash;
- Trash is visible only for Admin and PM;
- archived rows show Restore and Delete actions to Admin/PM;
- active rows show Archive and Delete actions to Admin/PM;
- deleted rows show Restore to Admin/PM;
- delete requires text confirmation using the project name or key;
- archive and restore use normal confirmation and optional reason.

Archived project detail:

- keeps historical resources readable;
- disables edit, Kanban mutations, kickstart actions, and new run/upload actions;
- shows a Restore action to Admin/PM.

Deleted project detail is not part of the first lifecycle UI. Trash rows are enough to restore deleted projects.

## Audit

Every lifecycle mutation writes an audit log entry.

Event names:

- `project.archived`
- `project.deleted`
- `project.restored`

Audit payload includes:

- actor user id, email, and role when available;
- project id, slug, and name;
- previous status;
- new status;
- reason when provided;
- blocked-work summary for failed archive/delete attempts if the existing audit policy records failed operations.

Audit logs are DevBoard control-plane records. They do not include target source code.

## Read Filtering And Metrics

Operational metrics count active projects by default.

Global overview excludes archived and deleted projects from active-project counts, task health, running runs, stale wiki counts, local agent readiness, and failing quality summaries unless a future explicit lifecycle filter is added.

Project list may include small lifecycle counts for Admin/PM, but lifecycle counts are not required for the first implementation slice.

## Out Of Scope

- Hard-delete or physical purge.
- Automatic trash retention.
- Backup export and restore semantics for deleted projects.
- Project-level custom permissions.
- Repository CRUD.
- Project Kickstart lifecycle coupling.
- Kanban task attachments.
- Inertia replacement UI.
- Deleting or modifying target source repositories.

## Implementation Plan Seed

A later implementation plan should split the work into at least these units:

1. Migration and lifecycle tests for `projects.status` metadata and reserved slugs.
2. Dashboard API lifecycle routes, permission guard, transition service, active-work blocker, route registry updates, and audit events.
3. Dashboard reader filtering for active, archived, and deleted views.
4. Plugin/local agent guards for archived/deleted projects on mutating project-scoped operations.
5. External React/emergent project list filters and lifecycle controls using `/api/dashboard/...`.
6. Focused backend, frontend adapter, and browser smoke verification.

## Acceptance Criteria

- Admin and PM can archive, restore, soft-delete, and restore from trash through `/api/dashboard/...`.
- Viewer cannot mutate lifecycle state.
- Archived projects are hidden from default lists but remain readable by direct dashboard links.
- Deleted projects are hidden from default lists and normal detail/resource routes, but visible in trash to Admin/PM.
- Archive/delete is blocked with `409` while active project work is in progress.
- Restore is allowed from archived and deleted states.
- Browser UI makes no `/api/plugin/v1` calls.
- Plugin/local agent operations cannot start or upload work for archived/deleted projects.
- Project slugs remain reserved across active, archived, and deleted states.
- Lifecycle mutations are audit logged.
- No hard-delete or purge behavior exists in this slice.
