# DevBoard Kanban Task Attachments

Date: 2026-06-24
Status: implementation slice

## Scope

This slice adds DevBoard-owned product files attached to Kanban tasks. These files are dashboard collaboration material: screenshots, PDFs, notes, JSON samples, CSVs, and similar task context.

Attachments are not analyzer artifacts, import manifests, source snapshots, target repository backups, or plugin evidence. They must not be stored in `project/`, target repositories, plugin workspaces, or `devboard/artifacts`.

## API Boundary

The browser frontend uses dashboard API routes only:

- `GET /api/dashboard/tasks/{task}`
- `POST /api/dashboard/tasks/{task}/attachments`
- `DELETE /api/dashboard/tasks/{task}/attachments/{attachment}`
- `GET /api/dashboard/tasks/{task}/attachments/{attachment}/download`

No browser code may call `/api/plugin/v1` for attachments. The plugin namespace remains reserved for CLI/MCP/local agent flows.

## Data Model

Use a dedicated `task_attachments` table with generated ULID IDs and generated storage paths.

Required metadata:

- `project_id`
- `task_id`
- `uploaded_by_user_id`
- original display name
- generated stored name
- private `storage_path`
- server-computed `sha256`
- `size_bytes`
- detected `mime_type`
- `kind` as `image` or `file`
- `status`, initially `available`
- `scan_status`, initially `not_scanned`
- JSON `metadata`
- nullable `deleted_at`
- nullable `deleted_by_user_id`
- timestamps

Soft-delete is reversible at the data layer: deleting an attachment marks the row as `status = deleted`, sets `deleted_at` and `deleted_by_user_id`, hides it from dashboard task detail/counts, and blocks download. Private storage bytes are preserved for a later restore/purge policy.

## Storage

Store bytes on Laravel's private local disk under:

`devboard/task-attachments/{project_id}/{task_id}/{attachment_id}/{stored_name}`

The original filename is display metadata only. It must not influence directories, traversal, or repository paths.

## Validation

Defaults for this slice:

- Maximum file size: 10 MiB.
- Maximum active attachments per task: 25.
- Single-file upload per request.

Allowed MIME types:

- `image/jpeg`
- `image/png`
- `image/webp`
- `image/gif`
- `application/pdf`
- `text/plain`
- `text/markdown`
- `text/csv`
- `application/json`

Reject SVG, HTML, JavaScript, executables, shell scripts, Office files, archives, database dumps, `.env`, key/cert files, source bundles, and unknown binary formats.

## Authorization And Lifecycle

Read/download:

- PM
- Developer
- Sysadmin
- Admin

Upload:

- PM
- Developer
- Admin

Soft-delete:

- PM
- Developer
- Admin

Project lifecycle:

- Active projects allow uploads and attachment soft-delete.
- Archived projects remain readable/downloadable but uploads and attachment soft-delete are blocked with `project_not_active`.
- Deleted projects return `404` through normal task and attachment routes.

## Response Contract

Task cards include:

- `attachment_count`
- `image_attachment_count`

Task detail includes:

- `attachments`, a list of active serialized attachment metadata. Soft-deleted attachments are omitted.

Attachment payload:

- `id`
- `task_id`
- `project_id`
- `name`
- `mime_type`
- `kind`
- `status`
- `scan_status`
- `size_bytes`
- `uploaded_at`
- `uploaded_by`
- `download_url`
- `preview_url` for images, otherwise `null`

Downloads stream authenticated private bytes and set `X-Content-Type-Options: nosniff`. Images may render inline; non-images should download as attachments.

## Audit

This slice records:

- `task_attachment.uploaded`
- `task_attachment.deleted`
- `task_attachment.downloaded`

Audit payloads include actor, project ID, task ID, attachment ID, display name, MIME, size, SHA-256, IP, and user-agent. Payloads never include file content, private paths from target repositories, or secrets.

## Backup Export / Restore Contract

Backup Export V1 must include:

- all `task_attachments` metadata rows, including active and soft-deleted rows;
- private storage bytes under `devboard/task-attachments/{project_id}/{task_id}/{attachment_id}/{stored_name}`;
- per-file checksums that can be reconciled with `task_attachments.sha256`;
- enough metadata to preserve `status`, `deleted_at`, and `deleted_by_user_id` exactly during restore.

Restore must preserve soft-deleted attachments as hidden/deleted records and must not make deleted attachments downloadable unless a later explicit attachment-restore action changes their state. Missing storage bytes, checksum mismatches, and orphaned attachment files should be reported during dry-run validation.

## Out Of Scope

- Attachment restore UI/API.
- Permanent purge.
- Backup/export implementation.
- Restore implementation.
- Quotas beyond the per-task count and upload size limits.
- OCR or image processing.
- Attaching files to runs, wiki pages, projects, repositories, or artifacts.
