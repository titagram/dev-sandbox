# DevBoard Masterplan

**Purpose:** single index for the DevBoard work still ahead after the emergent frontend integration and Quality Center backend slice.

This document is the routing map. Detailed design and implementation tasks live in the focused files linked below.

## Fresh Codex Session Start

`developer_provided`: new Codex sessions must be able to resume from repository files without relying on chat context.

Start by reading:

1. `AGENTS.md`
2. `ai-sandbox/INIT.md`
3. `ai-sandbox/instructions/INDEX.md`
4. `ai-sandbox/config/project.yaml`
5. `ai-sandbox/instructions/policies/`
6. `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`
7. this masterplan
8. the focused roadmap file for the selected workstream

`verified_from_code`: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md` is the chronological project handoff and audit trail. This file is the current masterplan and should drive prioritization after the required sandbox entrypoint files are read.

## Planning Files

- Frontend and Quality Center: `docs/superpowers/plans/2026-06-23-devboard-frontend-quality-control.md`
- Multiproject dashboard and CRUD: `docs/superpowers/plans/2026-06-24-devboard-multiproject-dashboard.md`
- Local Node agent over Python plugin/analyzer: `docs/superpowers/plans/2026-06-24-devboard-local-agent.md`
- Project kickstart and Git state: `docs/superpowers/plans/2026-06-24-devboard-project-kickstart.md`
- Server-side intelligence assistants: `docs/superpowers/plans/2026-06-24-devboard-server-side-intelligence-agents.md`
- Project Logbook and Watchman: `docs/superpowers/plans/2026-06-24-devboard-project-logbook-watchman.md`
- Backup export and restore: `docs/superpowers/plans/2026-06-24-devboard-backup-restore.md`

## Core Architecture

- `verified_from_code`: DevBoard backend is the Laravel control plane with PostgreSQL, Neo4j, dashboard APIs, plugin APIs, reports, artifacts, run events, and audit logs.
- `verified_from_code`: the existing Python plugin and analyzer already handle local CLI/MCP flows, Genesis/Delta artifacts, and uploads through `/api/plugin/v1`.
- `developer_provided`: DevBoard backend must not contain or clone the target source repositories by default.
- `developer_provided`: target project checks and scanners must execute near the target code through a local plugin/agent.
- `developer_provided`: browser UI must use `/api/dashboard/...`; `/api/plugin/v1` remains reserved for local plugin/agent clients.

## Delivery Order

1. Keep the emergent frontend and dashboard API stable.
2. Normalize multiproject navigation: project CRUD, project-scoped Kanban, project-scoped resources, and global overview.
3. Build Project Kickstart as the onboarding gate for new projects.
4. Build the thin Node local agent over the existing Python plugin/analyzer path.
5. Add Project Logbook as a first-class timeline and integrate plugin/agent append/fetch.
6. Add Watchman over Logbook, runs, artifacts, graph, wiki, and reports.
7. Add server-side intelligence assistants: Task Clarifier, Backlog Triage Assistant, Wiki Query, and Socrate.
8. Add native Backup Export and Restore Backup flows before relying on DevBoard for production migration or disaster recovery.
9. Continue Quality Center backend/report/gate hardening.

## Product Surfaces To Add

- Projects CRUD and global overview.
- Project-scoped Kanban.
- Kanban task file and image attachments.
- Project Kickstart checklist.
- Local Agent pairing and workspace status.
- Project Logbook.
- Intelligence panel for model/provider/assistant configuration.
- Assistant chat/suggestion surfaces.
- Watchman suggestions and warnings.
- Backup Export and Restore Backup Admin/System surface.

## Non-Negotiable Boundaries

- Do not make the server silently execute local scans or shell commands.
- Do not let browser UI call `/api/plugin/v1`.
- Do not treat agent-reported pushed/remote Git state as independently verified unless a future read-only remote Git integration verifies it.
- Do not let assistants mutate tasks, wiki, project state, code, local agent operations, or scanner runs without explicit human approval.
- Do not imply DevBoard has source code beyond uploaded artifacts, normalized reports, graph data, and evidence already held by DevBoard.
- Do not include unencrypted secrets in downloadable backup bundles.
- Do not treat backups as target source repository backups; they cover DevBoard control-plane state and DevBoard-held evidence/artifacts.

## Open Design Specs Needed Before Implementation

- Database schema for project kickstart state, project logbook, assistant runs, suggestions, and messages.
- Attachment schema, storage policy, preview handling, permissions, and upload validation for Kanban task files/images.
- Dashboard API contracts for project CRUD, overview, logbook, kickstart, assistants, and local agent status.
- Plugin/agent API contracts for logbook append/fetch, kickstart answers, job leases, workspace probes, and Git state updates.
- Local agent package layout, probe scripts, UI flows, and verification matrix.
- Assistant provider registry, model/runtime registry, role permissions, approval UX, context packing, audit records, and rate/cost limits.
- Backup bundle schema, export/restore API contracts, secret-handling policy, retention policy, dry-run semantics, and restore verification matrix.
