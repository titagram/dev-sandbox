# DevBoard Backend Blockers Report - 2026-07-01

## Scope

Documentation-only backend diagnosis and implementation proposal for five Hades/React blockers. No code or data changes were made.

Status labels: `verified_from_code`, `developer_provided`, `inferred`, `needs_verification`.

## 1. Wiki access

### Exists today

- `verified_from_code`: Laravel dashboard API exposes wiki reads:
  - `GET /api/dashboard/wiki`
  - `GET /api/dashboard/projects/{project}/wiki`
  - `GET /api/dashboard/wiki/pages/{page}`
  - Files: `backend/routes/web.php`, `backend/app/Http/Controllers/Dashboard/Api/DashboardResourceController.php`, `backend/app/Dashboard/DashboardApiReader.php`.
- `verified_from_code`: Laravel legacy/Inertia web pages still exist:
  - `GET /wiki`
  - `GET /wiki/pages/{page}`
  - Files: `backend/app/Http/Controllers/Dashboard/WikiIndexController.php`, `backend/app/Http/Controllers/Dashboard/WikiShowController.php`.
- `verified_from_code`: React has route-level support:
  - `/wiki`
  - `/wiki/:pageId`
  - `/projects/:projectId/wiki`
  - Files: external React `src/App.tsx`, `src/pages/WikiPage.tsx`, `src/pages/WikiPageDetailPage.tsx`.
- `verified_from_code`: React nav config marks `wiki` as `hidden`, so it is reachable by guard but not shown in the primary menu. Project detail has a `Wiki` action link.

### Missing or ambiguous

- `inferred`: wiki is not discoverable from the primary menu because `wiki` is hidden in React nav.
- `inferred`: project-scoped wiki access depends on reaching the project detail page and seeing the `Wiki` action.
- `inferred`: there is no explicit project API link payload such as `links.wiki`, so frontend must hardcode `/projects/{id}/wiki`.
- `needs_verification`: public proxy routing must keep `/wiki` and `/projects/{id}/wiki` on the React SPA, not Laravel legacy web pages.

### Proposed contract

- No new backend data model is required for basic access.
- Add API affordance in project payload:
  - `GET /api/dashboard/projects/{project}`
  - include `links: { wiki: "/projects/{project}/wiki", wiki_api: "/api/dashboard/projects/{project}/wiki" }`.
- Optional status endpoint:
  - `GET /api/dashboard/projects/{project}/wiki/status`
  - response: `page_count`, `stale_count`, `last_revision_at`, `latest_refresh_job_id`.

### Backend tests to add

- `DashboardWikiApiTest`: project-scoped wiki list excludes deleted/other projects.
- `DashboardProjectApiTest`: project payload includes `links.wiki` and `links.wiki_api`.
- Route smoke registry entry for `/api/dashboard/projects/{project}/wiki`.

## 2. Wiki population/update

### Exists today

- `verified_from_code`: plugin can write wiki revisions:
  - `POST /api/plugin/v1/runs/{run}/wiki/revisions`
  - File: `backend/app/Http/Controllers/Plugin/WikiRevisionController.php`.
- `verified_from_code`: `WikiRevisionService` upserts `wiki_pages`, inserts `wiki_revisions`, updates current revision, and audits `wiki.updated`.
- `verified_from_code`: `GenesisFinalizeService` and `DeltaFinalizeService` import wiki pages when a finalized artifact has `artifact_type = wiki_pages`.
- `verified_from_code`: Hades job infrastructure exists:
  - dashboard create job: `POST /api/dashboard/admin/hades/jobs`
  - Hades pull/status/result: `/api/hades/v1/agent/jobs`, `/status`, `/result`.

### Missing or ambiguous

- `verified_from_code`: no React-consumable project wiki refresh route exists.
- `verified_from_code`: Hades dashboard job creation currently allows `read_files`, `sync_git_tree`, `populate_backend_ast`; no `populate_project_wiki` or `update_project_wiki` capability.
- `inferred`: there is no service that validates a Hades job result and writes wiki revisions through `WikiRevisionService`.
- `inferred`: status is visible only in generic Hades admin/job lists, not on project wiki.

### Proposed contract

Dashboard request:

- `POST /api/dashboard/projects/{project}/wiki/refresh-requests`
- Required role: Admin, Developer, or PM.
- Payload:
  - `workspace_binding_id` required unless project has exactly one linked binding.
  - `repository_id` nullable.
  - `scope`: `project|repository`.
  - `reason` string.
  - `sections` optional list, e.g. `overview`, `architecture`, `runbook`, `risks`.
  - `policy`: `manual|confirm|approval_required`, default `manual`.
- Creates `hades_agent_jobs` row:
  - `capability`: `populate_project_wiki`.
  - `status`: `queued`.
  - `requires_confirmation`: true by default.
  - `payload.schema`: `devboard.wiki_refresh_request.v1`.

Status:

- `GET /api/dashboard/projects/{project}/wiki/refresh-requests`
- Returns latest jobs filtered by `capability = populate_project_wiki`.

Hades result:

- Reuse `POST /api/hades/v1/agent/jobs/{job}/result`.
- Result payload:
  - `schema`: `devboard.wiki_refresh_result.v1`.
  - `pages[]`: `slug`, `title`, `page_type`, `source_status`, `content_markdown`, `evidence_refs`.
  - `redactions`, `truncated`, `provenance`.
- Backend validates and writes via `WikiRevisionService`.
- Do not accept raw source by default.

Schema changes:

- Minimal: reuse `hades_agent_jobs`.
- Recommended: add `requested_by_user_id`, `job_type`, and `result_applied_at` to `hades_agent_jobs`.
- Optional: `wiki_refresh_requests` table if UI needs richer audit/status than job rows.

### Backend tests to add

- `DashboardWikiRefreshRequestTest`: creates a queued Hades job with `populate_project_wiki`.
- `DashboardWikiRefreshRequestTest`: rejects archived/deleted projects and missing/unlinked workspace binding.
- `HadesWikiResultTest`: valid result writes wiki pages/revisions through `WikiRevisionService`.
- `HadesWikiResultTest`: `verified_from_code` result without evidence is rejected.
- `HadesWikiResultTest`: result cannot cross project/workspace binding.

## 3. Manual memory source: user_inserted

### Exists today

- `verified_from_code`: dashboard memory API exists:
  - `GET /api/dashboard/projects/{project}/memory`
  - `POST /api/dashboard/projects/{project}/memory`
  - File: `backend/app/Http/Controllers/Dashboard/Api/DashboardMemoryController.php`.
- `verified_from_code`: current manual dashboard inserts set `source = dashboard_user`.
- `verified_from_code`: allowed `kind` values are `decision`, `implementation`, `clarification`, `risk`, `verification`, `handoff`, `incident`, `agent_note`.
- `verified_from_code`: React `ProjectMemoryPage` already has an Add Memory dialog and posts to the dashboard memory endpoint.
- `verified_from_code`: Hades memory snapshot returns `project_memory_entries.source` as-is.

### Missing or ambiguous

- `developer_provided`: product wants a clear user-created category/source like `inserito dall'utente` / `user_inserted`.
- `inferred`: `dashboard_user` is too implementation-oriented and does not distinguish manual human notes from other dashboard-originated system events.
- `inferred`: source labels in frontend do not yet include `user_inserted`.

### Proposed contract

Endpoint:

- Keep `POST /api/dashboard/projects/{project}/memory`.
- Server sets `source = user_inserted` for this manual form flow.
- Keep `author_user_id` populated.
- Keep `payload.created_from = dashboard_memory_form`.

Payload fields:

- Existing required: `kind`, `summary`, `payload`.
- Existing optional: `repository_id`, `task_id`, `run_id`, `agent_key`, `completeness`.
- Recommended optional additions:
  - `category` nullable string if UI wants a label separate from `kind`.
  - `visibility`: default `project`.
  - `provenance`: server-added object with `created_by = dashboard_user`, `source = user_inserted`.

Shared memory impact:

- `GET /api/hades/v1/memory/snapshot` should include `user_inserted` entries by default.
- Hades should treat `user_inserted` as authoritative human context and not as agent-generated memory.

### Backend tests to add

- `ProjectMemoryDashboardApiTest`: manual create stores `source = user_inserted`.
- `ProjectMemoryDashboardApiTest`: response includes `source = user_inserted`.
- `HadesMemorySnapshotTest`: snapshot includes user-inserted entries and preserves source.
- Regression: old entries with `dashboard_user` still render/read correctly.

## 4. OpenCode API keys

### Exists today

- `verified_from_code`: AI model provider registry exists:
  - tables: `ai_model_providers`, `ai_model_profiles`, `ai_agent_profiles`.
  - migration: `backend/database/migrations/2026_06_28_000001_create_ai_agent_registry_tables.php`.
- `verified_from_code`: provider credentials are stored in `ai_model_providers.encrypted_api_key`, with only `api_key_last_four` exposed.
- `verified_from_code`: admin API exists:
  - `GET /api/dashboard/admin/ai-agents`
  - `PUT /api/dashboard/admin/ai-model-providers/{provider}`
  - `PUT /api/dashboard/admin/ai-model-profiles/{profile}`
  - `PATCH /api/dashboard/admin/ai-agent-profiles/{agent}`.
- `verified_from_code`: `AiAgentRegistry::updateProvider()` can create arbitrary provider keys matching `[a-z0-9][a-z0-9_.-]*`.
- `verified_from_code`: server-side assistants configure Laravel AI dynamically using provider key, base URL, encrypted key, and selected model profile.
- `verified_from_code`: seed data creates `openai`, not `opencode` or `opencode_go`.
- `verified_from_code`: there is no provider validation/test-call endpoint.

### OpenCode docs check

- `verified_from_web`: OpenCode docs say provider keys are normally configured in OpenCode via `/connect` or `opencode auth login`, stored locally in OpenCode auth storage, and OpenCode Go works like another OpenCode provider.
- `needs_verification`: exact OpenCode Go HTTP base URL and whether it is directly OpenAI-compatible for Laravel AI SDK usage.

### Missing or ambiguous

- `inferred`: DevBoard backend can store a generic provider key, but it does not have first-class OpenCode Go presets.
- `inferred`: configuring OpenCode locally does not configure Laravel server-side assistants.
- `inferred`: there is no "validate credentials" flow, so UI can save a key but cannot prove it works.
- `inferred`: `PUT /api/dashboard/admin/ai-model-profiles/{profile}` updates existing profiles only; there is no create-profile endpoint for `opencode_go_default`.

### Proposed contract

Provider credentials:

- `PUT /api/dashboard/admin/ai-model-providers/opencode_go`
- Payload:
  - `display_name`: `OpenCode Go`.
  - `base_url`: required after verification.
  - `api_key`: optional on update, encrypted at rest.
  - `enabled`: boolean.
- Response must never return full key.

Model profile:

- Add `POST /api/dashboard/admin/ai-model-profiles`
- Payload:
  - `provider_key`, `profile_key`, `display_name`, `model_name`, `runtime_profile`, `max_output_tokens`, `temperature`, `timeout_seconds`, `enabled`.

Validation:

- `POST /api/dashboard/admin/ai-model-providers/{provider}/validate`
- Behavior:
  - decrypt key server-side;
  - perform a bounded provider-specific validation call or Laravel AI fake in tests;
  - return `status: valid|invalid|unknown`, model list if safe, and redacted error.
  - audit without key material.

### Backend tests to add

- `AiModelProviderDashboardApiTest`: upsert `opencode_go` stores encrypted key and exposes only configured/last-four metadata.
- `AiModelProviderDashboardApiTest`: clear key removes encrypted key.
- `AiModelProviderDashboardApiTest`: validation endpoint redacts key from response and audit log.
- `AiModelProfileDashboardApiTest`: create profile for new provider and assign to agent profile.
- Assistant service test with fake provider config, no real external call.

## 5. Transfer/import memory from another folder or workspace

### Exists today

- `verified_from_code`: Hades workspace bindings exist in `hades_workspace_bindings`.
- `verified_from_code`: Hades memory proposals exist in `hades_memory_proposals`.
- `verified_from_code`: accepted Hades create proposals currently create `project_memory_entries` with `source = hades_agent`.
- `verified_from_code`: `project_memory_entries` does not have `workspace_binding_id`; workspace provenance is currently in Hades proposal payload/provenance, not first-class on memory entries.
- `verified_from_code`: Dashboard Hades admin can list workspaces through `DashboardHadesController::index()`, but there is no project-scoped workspace list route for normal memory import UX.

### Missing or ambiguous

- `developer_provided`: frontend/Hades/CLI can help identify source folder/workspace and send/import existing memory.
- `inferred`: backend needs import batch tracking, deduplication, conflict policy, and provenance preservation.
- `inferred`: imports should default to proposals/review, not direct accepted memory writes.

### Proposed contract

Workspace source list:

- `GET /api/dashboard/projects/{project}/workspace-bindings`
- Response:
  - `id`, `status`, `display_path`, `workspace_fingerprint`, `git_remote_hash`, `head_commit`, `agent_label`, `external_agent_id`, `last_seen_at`, `memory_counts`.

Import request for memory already known to backend:

- `POST /api/dashboard/projects/{project}/memory/imports`
- Payload:
  - `source_workspace_binding_id`
  - `target_workspace_binding_id`
  - `mode`: `copy_as_proposals|copy_accepted`
  - `filters`: `kinds`, `since`, `limit`
  - `dedupe_strategy`: `summary_payload_hash|provenance_hash`
  - `conflict_policy`: `skip|proposal|mark_conflicted`
  - `reason`
- Default: `copy_as_proposals`.

Import bundle for memory supplied by Hades/local folder:

- `POST /api/hades/v1/memory/import-bundles`
- Requires agent token and target `workspace_binding_id`.
- Payload:
  - `schema`: `devboard.memory_import_bundle.v1`
  - `source`: redacted display/fingerprint/git metadata
  - `entries[]`: `local_id`, `kind`, `summary`, `payload`, `occurred_at`, `source_hash`, `provenance`
  - `dry_run`: boolean.
- Backend creates import batch and memory proposals, not direct entries, unless policy allows.

Tables:

- `memory_import_batches`:
  - `id`, `project_id`, `source_workspace_binding_id`, `target_workspace_binding_id`, `requested_by_user_id`, `requested_by_hades_agent_id`, `status`, `mode`, `dedupe_strategy`, `conflict_policy`, `reason`, timestamps.
- `memory_import_items`:
  - `id`, `batch_id`, `source_local_id`, `source_hash`, `proposal_id`, `target_memory_entry_id`, `status`, `conflict_reason`, `provenance`.

Policy:

- Preserve source workspace fingerprint/hash, never raw absolute path unless explicitly allowed.
- Deduplicate before proposal creation.
- Cross-project import requires Admin and explicit confirmation.
- Existing entries with same source hash become `skipped_duplicate`.
- Conflicts become `hades_memory_proposals.status = conflicted` or import item `conflicted`.

Status UI:

- `GET /api/dashboard/projects/{project}/memory/imports`
- `GET /api/dashboard/projects/{project}/memory/imports/{batch}`
- Show on Memory page as Import History plus pending proposals.

### Backend tests to add

- `WorkspaceBindingDashboardApiTest`: lists only project-visible workspace bindings.
- `MemoryImportDashboardApiTest`: creates batch from source to target binding.
- `MemoryImportDashboardApiTest`: dedupes by source hash and marks skipped duplicates.
- `MemoryImportDashboardApiTest`: creates proposals with provenance for non-duplicates.
- `HadesMemoryImportBundleTest`: accepts bounded import bundle, rejects cross-project binding, rejects raw path if policy forbids it.

## Recommended implementation order

1. Fix discoverability: expose wiki link in project payload and ensure React menu/project page routes use existing wiki API.
2. Add `user_inserted` source for manual memory and update tests/snapshot behavior.
3. Add wiki refresh request job: dashboard route creates `populate_project_wiki` Hades job and project wiki page shows status.
4. Add Hades wiki result application through `WikiRevisionService`.
5. Add OpenCode provider UX backend pieces: provider preset/profile creation/validation endpoint, with no secret exposure.
6. Add workspace memory import model: workspace list, import batch tables, dashboard import route, then Hades import bundle route.

## Files likely to change later

- `backend/routes/web.php`
- `backend/app/Dashboard/DashboardApiReader.php`
- `backend/app/Http/Controllers/Dashboard/Api/DashboardMemoryController.php`
- `backend/app/Http/Controllers/Dashboard/Api/DashboardHadesController.php`
- `backend/app/Http/Controllers/Dashboard/Api/DashboardResourceController.php`
- `backend/app/Http/Controllers/Hades/AgentJobResultController.php`
- `backend/app/Services/WikiRevisionService.php`
- `backend/app/Assistants/AiAgentRegistry.php`
- `backend/app/Http/Controllers/Dashboard/Api/DashboardAiAgentController.php`
- New controllers/services for wiki refresh, workspace binding list, memory import, provider validation.
- New migrations for memory import batches/items and optional Hades job columns.

