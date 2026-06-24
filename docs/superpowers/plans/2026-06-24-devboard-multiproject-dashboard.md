# DevBoard Multiproject Dashboard Plan

**Purpose:** define the multiproject product model before implementation.

## Decisions

- `developer_provided`: DevBoard Server needs CRUD for projects in the frontend.
- `developer_provided`: each project has its own Kanban.
- `developer_provided`: Kanban tasks must support file and image uploads.
- `developer_provided`: projects must be clearly separated, with an additional cross-project "in a glance" view for primary metrics.
- `verified_from_code`: the current schema already has `projects`, `kanban_boards`, `kanban_columns`, `tasks`, `runs`, `repositories`, `wiki_pages`, and artifacts tied to projects.
- `verified_from_code`: several current dashboard readers/controllers still default to the first project, so project scoping must be made explicit.

## Target UX

- `/overview`: cross-project operational view.
- `/projects`: project CRUD and project list.
- `/projects/{project}`: project summary.
- `/projects/{project}/kanban`: project-specific Kanban.
- `/projects/{project}/tasks/{task}`: task detail with comments, file attachments, and image attachments.
- `/projects/{project}/runs`: project-specific runs.
- `/projects/{project}/wiki`: project-specific wiki.
- `/projects/{project}/quality`: project-specific Quality Center.
- `/projects/{project}/logbook`: project-specific logbook.

## Global Overview Metrics

- active projects;
- projects blocked or waiting for kickstart;
- task counts by state and risk;
- blocked tasks;
- failed or running runs;
- repositories awaiting Genesis;
- local agents online/offline;
- stale wiki pages;
- failing quality gates.

## Implementation Notes

- Replace implicit "first project" selection with explicit project id or overview mode.
- Keep cross-project views read-optimized and avoid mixing all project tasks into a single Kanban.
- Store Kanban task attachments as project/task-scoped product files, distinct from analyzer artifacts and source-code evidence artifacts.
- Support image previews in task detail and compact attachment indicators on Kanban cards.
- Apply upload validation, size limits, permission checks, retention/export policy, and optional malware/content scanning before broad rollout.
- Use `/api/dashboard/...` for browser UI only.
- Keep `/api/plugin/v1` reserved for plugin/local agent clients.

## Spec Gaps

- Exact project CRUD fields and validation.
- Project archive/delete policy.
- Project-level permissions and role overrides.
- Attachment schema, storage disk/path policy, allowed MIME types, size limits, previews, deletion rules, and audit events.
- Cross-project filters and saved views.
