# Hades Backend Implementation Plan - 2026-06-30

## Scope

Questo piano allinea il backend Laravel DevBoard al contratto MVP gia' implementato lato Hades.

Status labels usati dal sandbox:

- `verified_from_code`: verificato leggendo codice, route, migration, test o output locale.
- `developer_provided`: fornito dal developer/Hades in chat.
- `inferred`: decisione tecnica derivata dal codice e dai vincoli noti.
- `needs_verification`: dettaglio da confermare prima o durante implementazione.

## 1. Conferma contratto e correzioni indispensabili

- `developer_provided`: Hades usa Bearer auth e namespace provvisorio `/api/hades/v1`.
- `developer_provided`: setup Hades chiama `POST token/verify` con `project_id`, poi `POST agents/register` con `project_id`, `agent_id`, `label`, `platform`, `version`, `capabilities`.
- `developer_provided`: Laravel deve rispondere a `agents/register` con `agent_token` derivato. Hades lo salva localmente e lo usa per le chiamate successive.
- `developer_provided`: project linking usa `POST workspaces/bind` e deve ricevere un `workspace_binding_id` stabile/idempotente.
- `developer_provided`: job sync usa `GET agent/jobs` con `project_id`, `agent_id`, `workspace_binding_id`, `capabilities[]`.
- `developer_provided`: Hades esegue solo allow-list read-only: `read_files`, `project_inspection`, `sync_git_tree`, `populate_backend_ast`.
- `developer_provided`: shared memory passa da `GET memory/snapshot` e `POST memory/proposals`; Laravel resta autoritativo.
- `developer_provided`: Persephone fase 1 e' inbox persistente con SSE/polling fallback; non e' canale primario per job.
- `developer_provided`: spelling API pubblico: `cancelled`.

Correzioni indispensabili:

- `inferred`: `POST /api/hades/v1/token/verify` deve accettare solo project token bootstrap, non agent token. Dopo `agents/register`, tutte le route operative devono richiedere agent token.
- `inferred`: `agent_id` inviato da Hades deve essere trattato come external/local agent id, non come primary key trusted. Laravel deve creare un proprio `hades_agents.id` e mantenere `external_agent_id`.
- `inferred`: `workspace_binding_id` deve essere generato dal backend e non accettato dal client, per evitare spoofing o binding cross-project.
- `inferred`: `capabilities[]` nel pull job e in register sono dichiarazioni Hades, ma Laravel deve sempre intersectarle con policy backend e token grants.
- `inferred`: per job status/result, Laravel deve validare che `project_id`, `agent_id`, `workspace_binding_id` e job appartengano allo stesso project scope.
- `inferred`: per `populate_backend_ast`, il contratto iniziale deve essere uno schema summary versionato, non full tree-sitter JSON e non raw source upload di default.

## 2. Decisioni concordate

- `developer_provided`: non estendere distruttivamente `/api/plugin/v1`; introdurre `/api/hades/v1`.
- `developer_provided`: project token e' bootstrap/project-scoped/revocabile; agent token derivato e' persistente, revocabile e auditabile.
- `developer_provided`: path assoluto locale non serve; usare `display_path` redatto, fingerprint/hash e git metadata.
- `developer_provided`: Hades fa parsing/probing locale per `sync_git_tree` e `populate_backend_ast`; backend riceve manifest/artifact bounded.
- `developer_provided`: memory create puo' essere auto-accepted da policy low-risk; update/delete richiedono etag/version/provenance e gating piu' forte.
- `inferred`: usare middleware separato `AuthenticateHadesToken`, non riusare direttamente il binding device dell'attuale plugin token service.

## 3. Milestones Laravel M1-M4

### M1 - Identity, token bootstrap, registration, capabilities

Obiettivo: rendere funzionante setup Hades end-to-end fino a `agent_token`.

Route:

- `POST /api/hades/v1/token/verify`
- `POST /api/hades/v1/agents/register`
- `GET /api/hades/v1/health`
- `GET /api/hades/v1/capabilities`
- Dashboard: `POST /api/dashboard/projects/{project}/hades-tokens`
- Dashboard: `POST /api/dashboard/hades-tokens/{token}/revoke`
- Dashboard: `POST /api/dashboard/hades-tokens/{token}/rotate`

Migrations/models:

- `hades_project_tokens`: `id`, `project_id`, `created_by_user_id`, `name`, `token_prefix`, `token_hash`, `capabilities`, `expires_at`, `revoked_at`, `last_used_at`, timestamps.
- `hades_agents`: `id`, `project_id`, `project_token_id`, `external_agent_id`, `label`, `platform`, `version`, `reported_capabilities`, `effective_capabilities`, `status`, `last_seen_at`, timestamps.
- `hades_agent_tokens`: `id`, `agent_id`, `token_prefix`, `token_hash`, `capabilities`, `expires_at`, `revoked_at`, `last_used_at`, timestamps.
- `hades_project_policies`: `project_id`, `capabilities`, `job_policy`, `memory_policy`, `git_tree_limits`, `ast_limits`, `persephone_limits`, timestamps.

Controllers/services:

- `App\Http\Controllers\Hades\TokenVerifyController`
- `App\Http\Controllers\Hades\RegisterAgentController`
- `App\Http\Controllers\Hades\HealthController`
- `App\Http\Controllers\Hades\CapabilitiesController`
- `App\Http\Controllers\Dashboard\HadesTokenController`
- `App\Services\Hades\HadesTokenService`
- `App\Services\Hades\HadesCapabilityService`
- `App\Http\Middleware\AuthenticateHadesToken`
- `App\Http\Middleware\EnsureHadesProjectScope`

Auth/token policy:

- Project token format: `hades_live_<ulid>|<secret>`.
- Agent token format: `hades_agent_<ulid>|<secret>`.
- Store only SHA-256 hash of secret; expose full token only once.
- Revoking project token revokes future bootstrap and should mark derived agents/tokens disabled unless policy explicitly keeps existing agents.
- Operational routes accept only agent token.
- Dashboard token creation requires project write/admin permission.

Feature tests:

- project token verify success/failure/revoked/expired.
- registration returns derived agent token once.
- repeated register with same `external_agent_id` is idempotent and rotates or reissues according to chosen policy.
- agent token can call `health` and `capabilities`; project token cannot call operational endpoints.
- token scope cannot cross project.

### M2 - Workspace binding and shared memory proposals

Obiettivo: supportare `workspaces/bind`, `memory/snapshot`, `memory/proposals`.

Route:

- `POST /api/hades/v1/workspaces/bind`
- `POST /api/hades/v1/workspaces/{workspace_binding_id}/unlink`
- `GET /api/hades/v1/memory/snapshot`
- `POST /api/hades/v1/memory/proposals`
- Dashboard: `GET /api/dashboard/projects/{project}/memory-proposals`
- Dashboard: `POST /api/dashboard/memory-proposals/{proposal}/accept`
- Dashboard: `POST /api/dashboard/memory-proposals/{proposal}/refuse`

Migrations/models:

- `hades_workspace_bindings`: `id`, `project_id`, `repository_id` nullable, `agent_id`, `local_project_id`, `workspace_fingerprint`, `display_path`, `git_remote_display`, `git_remote_hash`, `head_commit`, `status`, `last_seen_at`, `unlinked_at`, timestamps.
- Add to `project_memory_entries`: `version`, `etag`, `supersedes_memory_entry_id`, `deleted_at` nullable, `deleted_reason` nullable.
- `project_memory_proposals`: `id`, `project_id`, `workspace_binding_id`, `agent_id`, `action`, `target_memory_entry_id`, `base_version`, `base_etag`, `status`, `summary`, `payload`, `provenance`, `reason`, `reviewed_by_user_id`, `reviewed_at`, timestamps.
- `project_memory_events`: `id`, `project_id`, `memory_entry_id`, `proposal_id`, `event_type`, `version`, `etag`, timestamps.

Controllers/services:

- `WorkspaceBindingController`
- `MemorySnapshotController`
- `MemoryProposalController`
- `Dashboard\MemoryProposalReviewController`
- `WorkspaceBindingService`
- `MemorySnapshotService`
- `MemoryProposalService`
- `MemoryPolicyService`

Contract details:

- Binding idempotency key: `(project_id, agent_id, local_project_id, workspace_fingerprint)`.
- Binding response: `workspace_binding_id`, `status`, `policy_revision`, `capabilities`.
- Snapshot returns active memory only plus `snapshot_cursor`, `etag`, `entries[]`.
- Proposal result returns `accepted`, `refused`, `conflicted`, or `pending` plus `reason`.
- Create proposal may auto-accept if policy allows low-risk capability.
- Update/delete require `base_version` or `base_etag`; mismatch returns `conflicted`.

Feature tests:

- bind creates stable id and repeated bind returns same `workspace_binding_id`.
- bind cannot cross project or use revoked agent token.
- memory snapshot excludes deleted/superseded entries according to policy.
- create proposal auto-accepts only under configured low-risk rule.
- update/delete conflict on stale etag.
- refused proposal includes reason and does not mutate memory.

### M3 - Agent jobs, read-only dispatcher, status/result

Obiettivo: supportare il sync Hades read-only allow-list.

Route:

- `GET /api/hades/v1/agent/jobs`
- `POST /api/hades/v1/agent/jobs/{job}/status`
- `POST /api/hades/v1/agent/jobs/{job}/result`
- Dashboard: `POST /api/dashboard/projects/{project}/hades-jobs`
- Dashboard: `POST /api/dashboard/hades-jobs/{job}/cancel`

Migrations/models:

- `hades_jobs`: `id`, `project_id`, `repository_id` nullable, `workspace_binding_id`, `assigned_agent_id` nullable, `requested_by_user_id`, `job_type`, `capability`, `status`, `priority`, `payload`, `policy`, `requires_confirmation`, `attempt_count`, `max_attempts`, `available_at`, `lease_expires_at`, `started_at`, `completed_at`, `failed_at`, `cancelled_at`, `expired_at`, `result`, `result_metadata`, `error`, timestamps.
- `hades_job_events`: `id`, `job_id`, `actor_type`, `actor_id`, `event_type`, `status_from`, `status_to`, `message`, `payload`, timestamps.

Controllers/services:

- `AgentJobController`
- `Dashboard\HadesJobController`
- `HadesJobDispatchService`
- `HadesJobLeaseService`
- `HadesJobPolicyService`
- `HadesJobResultValidator`

Lifecycle:

- Public statuses: `received`, `waiting_confirmation`, `started`, `completed`, `failed`, `expired`, `cancelled`, `unlinked`.
- Pull returns jobs matching project, binding, agent status, effective capabilities and allow-list.
- If job policy is `confirm`, `manual`, `approval_required`, or `requires_confirmation=true`, Hades may report `waiting_confirmation`.
- Result must be bounded/redacted and include metadata: `truncated`, `redactions`, `artifact_refs`, `producer`, `workspace_binding_id`, `head_commit`.
- Backend stores `cancelled` externally; if it reuses existing legacy queue internally, map to current `canceled` spelling at the boundary.

Allowed MVP job types:

- `read_files`
- `project_inspection`
- `sync_git_tree`
- `populate_backend_ast`

Feature tests:

- pull only returns read-only allow-listed jobs.
- pull respects capabilities intersection and workspace binding.
- status cannot move terminal jobs.
- waiting confirmation is accepted only for jobs whose policy allows it.
- result rejects oversized/unredacted payloads.
- cancel uses public spelling `cancelled`.
- retry/expiry does not re-deliver completed or cancelled jobs.

### M4 - Git tree, AST summary, Persephone inbox

Obiettivo: completare sync backend bounded e realtime MVP non-job.

Route:

- `POST /api/hades/v1/workspaces/{workspace_binding_id}/git-tree/sync`
- `POST /api/hades/v1/workspaces/{workspace_binding_id}/ast/summaries`
- `GET /api/hades/v1/persephone/inbox`
- `POST /api/hades/v1/persephone/events`

Migrations/models:

- `hades_git_tree_snapshots`: `id`, `project_id`, `workspace_binding_id`, `head_commit`, `tree_hash`, `observed_at`, `file_count`, `total_bytes`, `truncated`, timestamps.
- `hades_git_tree_files`: `snapshot_id`, `path_hash`, `display_path`, `language`, `size_bytes`, `content_hash`, `status`, `metadata`.
- `hades_ast_imports`: `id`, `project_id`, `workspace_binding_id`, `schema_version`, `head_commit`, `producer`, `status`, `summary`, `limits`, timestamps.
- `hades_ast_symbols`: `import_id`, `symbol_key`, `kind`, `name`, `language`, `path_hash`, `range_start`, `range_end`, `signature_hash`, `metadata`.
- `hades_ast_relations`: `import_id`, `from_symbol_key`, `to_symbol_key`, `relation_type`, `confidence`, `metadata`.
- `persephone_events`: `id`, `project_id`, `sender_agent_id`, `target_agent_id` nullable, `channel`, `event_type`, `payload`, `cursor`, `expires_at`, timestamps.
- `persephone_deliveries`: `event_id`, `agent_id`, `delivered_at`, `acked_at`.

AST MVP schema:

- `schema_version`: `hades.ast.summary.v1`.
- `files[]`: `path_hash`, `display_path`, `language`, `content_hash`, `size_bytes`.
- `symbols[]`: `symbol_key`, `kind`, `name`, `file_path_hash`, `range`, `signature_hash`, `metadata`.
- `relations[]`: `from_symbol_key`, `to_symbol_key`, `type`, `confidence`, `metadata`.
- No raw source by default. Source snippets require explicit backend policy later.

Persephone MVP:

- Inbox persistent by project and target agent.
- `GET persephone/inbox` accepts `project_id`, `agent_id`, `cursor`, optional `wait_seconds`.
- SSE can be added as response mode later; polling fallback works first.
- Events are not authoritative job dispatch.

Feature tests:

- git tree sync rejects wrong project/binding/head mismatch.
- git tree limits enforce max files/max bytes and mark truncation.
- AST summary accepts versioned minimal schema and rejects raw source fields by default.
- Persephone inbox returns only scoped events and supports cursor replay.
- Persephone cannot be used by revoked agent tokens.

## 4. Backend files/API/migrations/jobs/tests

Files to create:

- `backend/app/Http/Controllers/Hades/TokenVerifyController.php`
- `backend/app/Http/Controllers/Hades/RegisterAgentController.php`
- `backend/app/Http/Controllers/Hades/HealthController.php`
- `backend/app/Http/Controllers/Hades/CapabilitiesController.php`
- `backend/app/Http/Controllers/Hades/WorkspaceBindingController.php`
- `backend/app/Http/Controllers/Hades/MemorySnapshotController.php`
- `backend/app/Http/Controllers/Hades/MemoryProposalController.php`
- `backend/app/Http/Controllers/Hades/AgentJobController.php`
- `backend/app/Http/Controllers/Hades/GitTreeSyncController.php`
- `backend/app/Http/Controllers/Hades/AstSummaryController.php`
- `backend/app/Http/Controllers/Hades/PersephoneInboxController.php`
- `backend/app/Http/Controllers/Dashboard/HadesTokenController.php`
- `backend/app/Http/Controllers/Dashboard/HadesJobController.php`
- `backend/app/Http/Controllers/Dashboard/MemoryProposalReviewController.php`
- `backend/app/Http/Middleware/AuthenticateHadesToken.php`
- `backend/app/Http/Middleware/EnsureHadesProjectScope.php`
- `backend/app/Services/Hades/*.php`
- `backend/app/Http/Requests/Hades/*.php`
- `backend/app/Http/Resources/Hades/*.php`
- `backend/tests/Feature/Hades/*.php`

Files to modify:

- `backend/routes/api.php`: add `/api/hades/v1` group and dashboard Hades routes.
- `backend/bootstrap/app.php`: register middleware aliases if the project pattern requires it.
- `backend/app/Providers/AppServiceProvider.php`: add Hades rate limiters.
- `backend/app/Services/ProjectLifecycleService.php`: reuse or expose active/archived/deleted guards if needed.
- Existing dashboard readers may later expose agents/bindings/jobs, but M1-M4 can ship API-first.

Laravel queued/background work:

- `ExpireHadesJobs` or scheduled command `hades:expire-jobs`.
- `PruneHadesTokens` for expired/revoked token cleanup metadata, not hard delete.
- `PrunePersephoneEvents` by TTL.
- Optional queued import jobs for large git tree / AST summaries after M4.

Recommended test command:

```bash
docker compose -f docker-compose.devboard.yaml exec -T app sh -lc 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test tests/Feature/Hades --display-warnings'
```

## 5. Hades-side requirements

- Persist derived `agent_token` securely after register and redact it in logs/status.
- Treat Laravel-generated `workspace_binding_id` as authoritative.
- Continue sending `project_id`, `agent_id`, `workspace_binding_id` on operational calls for backend consistency checks.
- Do not upload raw source by default for AST; send bounded summaries/artifact metadata.
- For memory update/delete proposals, include `base_version` or `base_etag` and provenance.
- For job results, include redaction/truncation metadata even when nothing was redacted or truncated.
- For confirmation jobs, report `waiting_confirmation` with `reason` and later `started`, `failed`, or `cancelled`.
- Use public spelling `cancelled`.

## 6. Rischi

- `verified_from_code`: existing `/api/plugin/v1` auth is device-bound; reusing it directly would conflict with reusable project bootstrap token and derived multi-agent tokens.
- `inferred`: stale or spoofed `agent_id` can cause cross-binding bugs unless Laravel treats it as external id and validates project scope every time.
- `inferred`: AST and git tree payloads can grow quickly; enforce max bytes, max files, max symbols and truncation metadata from M4.
- `inferred`: auto-accept memory create can pollute authoritative memory if policy is too broad; start with narrow low-risk rules and audit events.
- `inferred`: Persephone can become an accidental job channel; keep job lifecycle exclusively on `agent/jobs` in M1-M4.
- `needs_verification`: final retention/TTL for agent tokens, job events, Persephone events and doctor reports.

## 7. Open questions residue

- `needs_verification`: should repeated `agents/register` rotate the derived `agent_token` every time, or only when Hades sends an explicit rotate flag?
- `needs_verification`: exact default limits for git tree and AST payloads: proposed starting point is 5 MB JSON, 20k files, 50k symbols, configurable per project policy.
- `needs_verification`: which dashboard roles may create Hades project tokens and approve/refuse memory proposals.
- `needs_verification`: whether M4 should expose SSE immediately or ship polling first with the same inbox cursor contract.
- `needs_verification`: whether job result artifacts should reuse existing `artifacts` tables immediately or start as bounded JSON in `hades_jobs.result`.

