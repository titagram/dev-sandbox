# DevBoard Operational Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert `claude_suggestions.md` into a small-task execution plan that closes P0 security/deploy risks, stabilizes P1 foundations, and sequences P2 semantic-memory work without building RAG on top of weak facts.

**Architecture:** Keep the plugin/analyzer as the only local source-code reader and keep the Laravel backend as the control plane for state, policy, artifacts, audit, and graph imports. Stabilize deterministic structure first: validated artifact identity, bounded uploads, signed device requests, typed persistence boundaries, resolved graph edges, and real graph queries before AI-generated wiki or vector search.

**Tech Stack:** Laravel 13, Pest/PHPUnit, PostgreSQL, Neo4j, Docker Compose, Python plugin/analyzer with pytest, Node local agent where explicitly required.

## Global Constraints

- Source status: facts imported from `claude_suggestions.md` are `developer_provided` unless verified again in code during the task.
- Project root: `/home/ubuntu/dev-sandbox`.
- Protected paths from `ai-sandbox/config/project.yaml`: none.
- Do not modify `project/`; current project root is `.`.
- Every project write must be logged in `ai-sandbox/logbooks/LOGBOOK_PROJECT.md` before editing.
- Use TDD for code tasks: add the failing test first, verify failure, implement, verify pass.
- Run formatting/static checks proportional to touched files before marking a task complete.
- Do not start P2 Level 3 or P2 Level 4 until P2 Level 1 and P2 Level 2 acceptance criteria pass.
- Prefer Postgres-backed verification for database features that depend on JSON, full-text search, or dialect-specific SQL.
- Keep `claude_suggestions.md` unchanged unless the developer explicitly asks to revise the analysis.

---

## Required Reads

- `AGENTS.md`
- `ai-sandbox/INIT.md`
- `ai-sandbox/instructions/INDEX.md`
- `ai-sandbox/instructions/policies/FILE_BOUNDARIES.md`
- `ai-sandbox/instructions/policies/SOURCE_OF_TRUTH.md`
- `ai-sandbox/instructions/policies/LOGBOOKS.md`
- `ai-sandbox/config/project.yaml`
- `claude_suggestions.md`
- `docs/ai-devboard/03_DOMAIN_MODEL.md`
- `docs/ai-devboard/04_PLUGIN_SERVER_CONTRACT.md`
- `docs/ai-devboard/05_GENESIS_IMPORT.md`
- `docs/ai-devboard/06_DELTA_SYNC.md`
- `docs/ai-devboard/07_SECURITY_MODEL.md`
- `docs/ai-devboard/10_RUNTIME_SEQUENCES.md`

## Execution Model

Run tasks in order inside each wave unless the task states it is parallel-safe.

- Wave 0: baseline and safety rails.
- Wave 1: P0 blockers before distributing external agent tokens.
- Wave 2: P1 foundations that reduce future feature cost.
- Wave 3: P2 deterministic semantic memory.
- Wave 4: P2 AI/RAG and P3 scale, only after deterministic gates pass.

Each task should be one commit when tests pass. Suggested commit messages are included.

## Wave 0 - Baseline

### Task 0.1: Confirm Runtime Baseline And Write Paths

**Files:**
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

**Interfaces:**
- Consumes: workspace policy files and `ai-sandbox/config/project.yaml`
- Produces: recorded intended write paths for the next implementation task

- [ ] **Step 1: Record intended write paths**

Append a logbook entry before code edits. Include the specific files for the next task only, not the whole roadmap.

- [ ] **Step 2: Capture current git state**

Run:

```bash
git status --short
```

Expected: no unrelated changes in files planned for the task. Stop and ask if user changes overlap.

- [ ] **Step 3: Capture baseline test commands**

Run the smallest relevant command for the first implementation task. For P0 plugin/backend tasks, use:

```bash
cd backend && php artisan test tests/Feature/PluginAuthTest.php tests/Feature/GenesisUploadTest.php tests/Feature/DeltaSyncTest.php --display-warnings
```

Expected: existing tests pass before changes, or failures are logged as pre-existing with exact output.

**Commit:** no commit unless this task changes only the logbook.

---

## Wave 1 - P0 Security And Deploy Blockers

### Task 1.1: Remove Default Secrets From Deploy Configuration

**Files:**
- Modify: `.gitignore`
- Modify: `docker-compose.devboard.yaml`
- Modify: `docker-compose.devboard.traefik.yaml`
- Modify: `docker-compose.devboard.prod.yaml`
- Modify: `backend/config/services.php`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`
- Optional if tracked: `backend/.env`

**Interfaces:**
- Consumes: Docker Compose environment variables.
- Produces: compose configs that fail fast when required secrets are missing.

- [ ] **Step 1: Write safety assertions**

Add a shell-based verification section to the logbook entry with these checks:

```bash
rg -n "<redacted-rotated-neo4j-password>|base64:|APP_KEY=.*fallback|NEO4J_PASSWORD.*<redacted>" docker-compose.devboard*.yaml backend/config/services.php backend/.env .gitignore
```

Expected before implementation: matches may exist. Expected after implementation: no matches for hardcoded live/default secrets outside documentation explaining required env names.

- [ ] **Step 2: Ensure `.env` is ignored**

Update `.gitignore` so Laravel env files are ignored:

```gitignore
backend/.env
backend/.env.*
!backend/.env.example
```

If `backend/.env` is tracked, run:

```bash
git rm --cached backend/.env
```

- [ ] **Step 3: Require secrets in compose**

Replace secret fallbacks with `${VAR:?message}` in runtime compose files. Required variables:

```text
APP_KEY
DB_PASSWORD
NEO4J_PASSWORD
```

- [ ] **Step 4: Make Traefik layer extend production behavior**

Change the public deployment instructions/config path so Traefik is layered over `docker-compose.devboard.prod.yaml`, not the development compose file.

- [ ] **Step 5: Verify compose renders only with explicit env**

Run:

```bash
APP_KEY=base64:test DB_PASSWORD=test NEO4J_PASSWORD=<test> docker compose -f docker-compose.devboard.prod.yaml -f docker-compose.devboard.traefik.yaml config >/tmp/devboard-compose-config.yaml
rg -n "<redacted-rotated-neo4j-password>|php artisan serve|composer install|:/workspace" /tmp/devboard-compose-config.yaml

<!-- credential rotated 2026-07-10 per remediation Task 0.2; values redacted -->
```

Expected: no default Neo4j password, no dev server command, no runtime composer install, no workspace bind mount for production.

**Commit:** `chore: require production secrets in compose`

### Task 1.2: Validate Artifact Identity Before Storage Paths

**Files:**
- Create: `backend/app/Support/DevBoardUlid.php`
- Create: `backend/tests/Feature/Plugin/ArtifactIdentityValidationTest.php`
- Modify: `backend/app/Http/Controllers/Plugin/GenesisStartController.php`
- Modify: `backend/app/Http/Controllers/Plugin/DeltaStartController.php`
- Modify: `backend/app/Services/ArtifactStorageService.php`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

**Interfaces:**
- Consumes: manifest artifact objects with `artifact_id`.
- Produces: strict ULID validation and storage path defense-in-depth.

- [ ] **Step 1: Write failing tests for traversal and invalid IDs**

Create `backend/tests/Feature/Plugin/ArtifactIdentityValidationTest.php` with tests that:

```php
it('rejects Genesis artifact ids that are not strict ULIDs');
it('rejects Delta artifact ids that are not strict ULIDs');
it('rejects storage path construction for unsafe artifact ids');
```

Use payload examples:

```php
'artifact_id' => '../outside'
'artifact_id' => '01J00000000000000000000000/evil'
'artifact_id' => strtolower((string) Str::ulid())
```

Expected status for API tests: `422`.

- [ ] **Step 2: Run tests and verify failure**

Run:

```bash
cd backend && php artisan test tests/Feature/Plugin/ArtifactIdentityValidationTest.php --display-warnings
```

Expected: failures prove invalid IDs are currently accepted or helper does not exist.

- [ ] **Step 3: Add strict ULID helper**

Create `backend/app/Support/DevBoardUlid.php`:

```php
<?php

namespace App\Support;

final class DevBoardUlid
{
    public const REGEX = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    public static function isStrict(string $value): bool
    {
        return preg_match(self::REGEX, $value) === 1;
    }

    public static function assertStrict(string $value, string $field = 'id'): void
    {
        if (! self::isStrict($value)) {
            throw new \InvalidArgumentException("Unsafe {$field}.");
        }
    }
}
```

- [ ] **Step 4: Apply validation rules in Genesis and Delta start**

Change both controller validation rules:

```php
'manifest.artifacts.*.artifact_id' => ['required', 'string', 'regex:'.\App\Support\DevBoardUlid::REGEX],
```

- [ ] **Step 5: Add storage defense-in-depth**

Call `DevBoardUlid::assertStrict($artifactId, 'artifact_id')` in `chunkPath()` and `artifactPath()` before interpolating the path.

- [ ] **Step 6: Verify focused tests**

Run:

```bash
cd backend && php artisan test tests/Feature/Plugin/ArtifactIdentityValidationTest.php tests/Feature/GenesisUploadTest.php tests/Feature/DeltaSyncTest.php --display-warnings
```

Expected: all pass.

**Commit:** `fix: validate artifact ids before storage`

### Task 1.3: Add Upload Size Limits And Stream Artifact Assembly

**Files:**
- Create: `backend/config/devboard.php`
- Create: `backend/tests/Feature/Plugin/ArtifactUploadLimitsTest.php`
- Modify: `backend/app/Http/Controllers/Plugin/GenesisStartController.php`
- Modify: `backend/app/Http/Controllers/Plugin/DeltaStartController.php`
- Modify: `backend/app/Http/Controllers/Plugin/GenesisChunkController.php`
- Modify: `backend/app/Http/Controllers/Plugin/DeltaChunkController.php`
- Modify: `backend/app/Services/ArtifactStorageService.php`
- Modify: `plugin/src/devboard_plugin/artifacts.py`
- Modify: `plugin/tests/test_artifact_upload.py`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

**Interfaces:**
- Consumes: configured upload limits.
- Produces: bounded chunk upload and append-only artifact assembly.

- [ ] **Step 1: Define limits**

Create `backend/config/devboard.php`:

```php
<?php

return [
    'artifacts' => [
        'disk' => env('DEVBOARD_ARTIFACT_DISK', 'local'),
        'max_chunk_bytes' => (int) env('DEVBOARD_MAX_ARTIFACT_CHUNK_BYTES', 8 * 1024 * 1024),
        'max_chunks' => (int) env('DEVBOARD_MAX_ARTIFACT_CHUNKS', 512),
        'max_artifact_bytes' => (int) env('DEVBOARD_MAX_ARTIFACT_BYTES', 512 * 1024 * 1024),
    ],
];
```

- [ ] **Step 2: Write failing limit tests**

Create tests that assert:

```php
it('rejects manifests whose size_bytes exceeds max_artifact_bytes');
it('rejects manifests whose chunk_count exceeds max_chunks');
it('rejects chunk bodies larger than max_chunk_bytes');
it('assembles chunks without concatenating the full artifact in a PHP string');
```

For the streaming assertion, use `Storage::fake('local')`, upload two chunks, finalize, and assert final file content and SHA are correct.

- [ ] **Step 3: Verify failure**

Run:

```bash
cd backend && php artisan test tests/Feature/Plugin/ArtifactUploadLimitsTest.php --display-warnings
```

Expected: failing validation/behavior before implementation.

- [ ] **Step 4: Enforce manifest limits**

Add validation to Genesis and Delta start:

```php
'manifest.artifacts.*.size_bytes' => ['required', 'integer', 'min:0', 'max:'.config('devboard.artifacts.max_artifact_bytes')],
'manifest.artifacts.*.chunk_count' => ['required', 'integer', 'min:1', 'max:'.config('devboard.artifacts.max_chunks')],
```

- [ ] **Step 5: Enforce chunk limits**

In both chunk controllers, reject when `strlen($request->getContent()) > config('devboard.artifacts.max_chunk_bytes')` with HTTP `413` and error code `artifact_chunk_too_large`.

- [ ] **Step 6: Stream artifact assembly**

Replace `$content .= ...` in `ArtifactStorageService::assembleArtifact()` with a write stream to the configured disk path. Maintain a running hash context:

```php
$context = hash_init('sha256');
foreach ($chunks as $chunk) {
    hash_update($context, $chunk);
    fwrite($target, $chunk);
}
$actualHash = hash_final($context);
```

Do not hold the full artifact in memory.

- [ ] **Step 7: Stream Python upload reads**

Replace `artifact_path.read_bytes()` in `plugin/src/devboard_plugin/artifacts.py` with chunked file reads:

```python
with artifact_path.open("rb") as artifact_file:
    chunk_index = 0
    while chunk := artifact_file.read(chunk_size):
        client.upload_genesis_chunk(import_id, artifact["artifact_id"], chunk_index, chunk)
        chunk_index += 1
```

Do the same for delta.

- [ ] **Step 8: Verify backend and plugin tests**

Run:

```bash
cd backend && php artisan test tests/Feature/Plugin/ArtifactUploadLimitsTest.php tests/Feature/GenesisUploadTest.php tests/Feature/DeltaSyncTest.php --display-warnings
cd plugin && python -m pytest -q tests/test_artifact_upload.py
```

Expected: all pass.

**Commit:** `fix: bound and stream artifact uploads`

### Task 1.4: Bind Plugin Tokens To Signed Device Requests

**Files:**
- Create: `backend/database/migrations/*_add_signing_secret_to_devices.php`
- Create: `backend/app/Services/PluginRequestSigner.php`
- Modify: `backend/app/Http/Controllers/Plugin/RegisterDeviceController.php`
- Modify: `backend/app/Services/PluginTokenService.php`
- Modify: `backend/app/Http/Middleware/AuthenticatePluginToken.php`
- Modify: `backend/tests/Feature/PluginAuthTest.php`
- Modify: `plugin/src/devboard_plugin/client.py`
- Modify: `plugin/src/devboard_plugin/config.py`
- Modify: `plugin/tests/test_client.py`
- Modify: `plugin/tests/test_config.py`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

**Interfaces:**
- Consumes: bearer token plus one-time device registration.
- Produces: per-device request signing using HMAC SHA-256.

- [ ] **Step 1: Define signing contract**

Canonical string:

```text
METHOD
/path?query
unix_timestamp
hex_sha256_body
```

Headers:

```text
X-DevBoard-Device-Id
X-DevBoard-Timestamp
X-DevBoard-Content-SHA256
X-DevBoard-Signature
```

Signature value:

```text
v1=<hex_hmac_sha256(canonical, device_secret)>
```

Allowed clock skew: 300 seconds.

- [ ] **Step 2: Write failing backend tests**

Extend `PluginAuthTest.php`:

```php
it('returns a device secret exactly when registering a new device');
it('rejects a bound token request without a device signature');
it('rejects a bound token request with a stale timestamp');
it('rejects a bound token request with a body hash mismatch');
it('accepts a bound token request with a valid device signature');
```

- [ ] **Step 3: Write failing Python tests**

Extend `plugin/tests/test_client.py` and `plugin/tests/test_config.py`:

```python
def test_client_adds_device_signature_headers_when_device_secret_is_configured()
def test_credentials_round_trip_preserves_device_secret()
```

- [ ] **Step 4: Verify failures**

Run:

```bash
cd backend && php artisan test tests/Feature/PluginAuthTest.php --display-warnings
cd plugin && python -m pytest -q tests/test_client.py tests/test_config.py
```

Expected: failing tests for missing signing.

- [ ] **Step 5: Add device secret storage**

Migration adds nullable `signing_secret_hash` to `devices`. Store only `hash('sha256', $plainSecret)`.

- [ ] **Step 6: Return one-time secret on new registration**

When `RegisterDeviceController` creates a device, generate a random secret and return:

```json
{
  "device_id": "...",
  "device_secret": "...",
  "status": "active",
  "server_time": "..."
}
```

For existing devices, do not return `device_secret`.

- [ ] **Step 7: Enforce signature in middleware**

After bearer token authentication, if the token has a `device_id`, verify the HMAC against the active device. Return `401` with error code `device_signature_required` or `device_signature_invalid`.

- [ ] **Step 8: Persist and send device secret in plugin**

Add `device_secret: str | None` to `Credentials`. Make `DevBoardClient` sign requests when `device_id` and `device_secret` are present.

- [ ] **Step 9: Verify focused tests**

Run:

```bash
cd backend && php artisan test tests/Feature/PluginAuthTest.php --display-warnings
cd plugin && python -m pytest -q tests/test_client.py tests/test_config.py
```

Expected: all pass.

**Commit:** `feat: sign plugin requests per device`

### Task 1.5: Centralize Audit Logging And Add Missing Security Events

**Files:**
- Create: `backend/app/Services/AuditLogger.php`
- Create: `backend/tests/Feature/AuditLoggerTest.php`
- Modify: `backend/app/Http/Middleware/AuthenticatePluginToken.php`
- Modify: `backend/app/Http/Middleware/AuthenticateHadesAgentToken.php`
- Modify: `backend/app/Http/Controllers/Dashboard/PluginTokenController.php`
- Modify: `backend/app/Http/Controllers/Plugin/GenesisStartController.php`
- Modify: `backend/app/Http/Controllers/Plugin/DeltaStartController.php`
- Modify: `backend/app/Services/ArtifactStorageService.php`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

**Interfaces:**
- Consumes: actor, action, target, payload.
- Produces: consistent `audit_logs` rows and denial/upload events.

- [ ] **Step 1: Write failing tests**

Create tests:

```php
it('records token created and revoked events');
it('records permission denied when plugin scope is missing');
it('records artifact upload rejection when validation fails after start');
it('chains audit rows with prev_hash and row_hash');
```

- [ ] **Step 2: Verify failure**

Run:

```bash
cd backend && php artisan test tests/Feature/AuditLoggerTest.php --display-warnings
```

Expected: service and events missing.

- [ ] **Step 3: Add service**

Create `AuditLogger::record(string $action, string $targetType, string $targetId, array $payload = [], array $actor = []): void`.

Hash-chain fields must be added in a migration if absent:

```text
prev_hash nullable string(64)
row_hash string(64)
```

- [ ] **Step 4: Replace new/critical raw audit writes**

Do not refactor all 15+ call sites in one pass. For P0, cover token creation/revocation, permission denied, artifact rejected/uploaded, and repository linked.

- [ ] **Step 5: Verify tests and grep**

Run:

```bash
cd backend && php artisan test tests/Feature/AuditLoggerTest.php tests/Feature/PluginAuthTest.php tests/Feature/GenesisUploadTest.php --display-warnings
rg -n "permission.denied|token.created|token.revoked|artifact.rejected|artifact.uploaded" backend/app backend/tests
```

Expected: tests pass and event names exist in production code and tests.

**Commit:** `feat: centralize security audit events`

### Task 1.6: Block SSRF In AI Provider Configuration

**Files:**
- Create: `backend/app/Assistants/ProviderEndpointPolicy.php`
- Modify: `backend/app/Assistants/AiAgentRegistry.php`
- Modify: `backend/tests/Feature/Dashboard/AiAgentRegistryDashboardTest.php`
- Modify: `backend/config/services.php`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

**Interfaces:**
- Consumes: provider `base_url`.
- Produces: allowlisted external endpoint validation.

- [ ] **Step 1: Write failing tests**

Add tests asserting provider validation rejects:

```text
http://127.0.0.1:8000
http://localhost:11434
http://169.254.169.254/latest/meta-data
http://10.0.0.5
http://172.16.0.5
http://192.168.1.2
```

Also assert configured provider hosts can pass when included in an allowlist.

- [ ] **Step 2: Verify failure**

Run:

```bash
cd backend && php artisan test tests/Feature/Dashboard/AiAgentRegistryDashboardTest.php --display-warnings
```

- [ ] **Step 3: Implement endpoint policy**

Validate scheme is `https` unless `APP_ENV=local` and host is explicitly allowlisted. Resolve host DNS and reject private, loopback, link-local, multicast, and unspecified IPs.

- [ ] **Step 4: Apply policy before HTTP calls**

Call the policy in provider create/update and validation paths before `Http::get()` or `Http::post()`.

- [ ] **Step 5: Verify focused tests**

Run:

```bash
cd backend && php artisan test tests/Feature/Dashboard/AiAgentRegistryDashboardTest.php --display-warnings
```

Expected: all pass.

**Commit:** `fix: validate ai provider endpoints`

---

## Wave 2 - P1 Foundations

### Task 2.1: Introduce Core Eloquent Models Without Big-Bang Refactor

**Files:**
- Create: `backend/app/Models/Project.php`
- Create: `backend/app/Models/Repository.php`
- Create: `backend/app/Models/Run.php`
- Create: `backend/app/Models/Artifact.php`
- Create: `backend/app/Models/Device.php`
- Create: `backend/app/Models/ApiToken.php`
- Create: `backend/tests/Feature/DomainModelTest.php`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

**Interfaces:**
- Consumes: existing database tables.
- Produces: typed models with casts and relationships for new code.

- [ ] **Step 1: Write model relationship tests**

Assert:

```php
Project::query()->first()->repositories()
Repository::query()->first()->runs()
Run::query()->first()->artifacts()
ApiToken::query()->first()->device()
Device::query()->first()->apiTokens()
```

- [ ] **Step 2: Verify failure**

Run:

```bash
cd backend && php artisan test tests/Feature/DomainModelTest.php --display-warnings
```

- [ ] **Step 3: Add models with casts**

Use existing table names. Cast JSON columns to `array`, timestamps to `datetime`, and IDs to strings where the schema uses ULIDs.

- [ ] **Step 4: Verify no behavior change**

Run:

```bash
cd backend && php artisan test tests/Feature/DomainModelTest.php tests/Feature/Dashboard/DashboardApiContractTest.php --display-warnings
```

**Commit:** `feat: add core domain models`

### Task 2.2: Move Plugin Write Validation Into Form Requests

**Files:**
- Create: `backend/app/Http/Requests/Plugin/StartGenesisImportRequest.php`
- Create: `backend/app/Http/Requests/Plugin/StartDeltaSyncRequest.php`
- Create: `backend/app/Http/Requests/Plugin/RegisterDeviceRequest.php`
- Modify: `backend/app/Http/Controllers/Plugin/GenesisStartController.php`
- Modify: `backend/app/Http/Controllers/Plugin/DeltaStartController.php`
- Modify: `backend/app/Http/Controllers/Plugin/RegisterDeviceController.php`
- Modify: `backend/tests/Feature/Plugin/ArtifactIdentityValidationTest.php`
- Modify: `backend/tests/Feature/PluginAuthTest.php`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

**Interfaces:**
- Consumes: request payloads.
- Produces: reusable validation contracts.

- [ ] **Step 1: Add tests for exact validation errors**

Assert invalid `artifact_id`, `chunk_count`, `size_bytes`, missing `fingerprint_hash`, and oversize `plugin_version` all return `422`.

- [ ] **Step 2: Extract validation rules**

Move inline `$request->validate()` arrays into FormRequest classes. `authorize()` returns `true`; authorization remains middleware/policy until Task 2.3.

- [ ] **Step 3: Verify focused tests**

Run:

```bash
cd backend && php artisan test tests/Feature/Plugin/ArtifactIdentityValidationTest.php tests/Feature/PluginAuthTest.php tests/Feature/GenesisUploadTest.php tests/Feature/DeltaSyncTest.php --display-warnings
```

**Commit:** `refactor: extract plugin write form requests`

### Task 2.3: Centralize Dashboard Authorization With Gates

**Files:**
- Create: `backend/app/Policies/ProjectPolicy.php`
- Create: `backend/app/Policies/PluginTokenPolicy.php`
- Modify: `backend/app/Providers/AppServiceProvider.php`
- Modify: `backend/app/Http/Controllers/Dashboard/PluginTokenController.php`
- Modify: selected dashboard write controllers touched by current tests
- Modify: `backend/tests/Feature/Dashboard/AuthDashboardApiTest.php`
- Modify: `backend/tests/Feature/Dashboard/DashboardApiContractTest.php`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

**Interfaces:**
- Consumes: authenticated dashboard user and role/permission tables.
- Produces: `Gate::authorize()` decisions and `permission.denied` audit events.

- [ ] **Step 1: Write failing tests**

Add tests proving a non-admin dashboard user cannot create/revoke plugin tokens and receives a logged denial.

- [ ] **Step 2: Register gates**

Read existing role/permission storage and define gates for `manage-plugin-tokens`, `write-project`, and `read-project`.

- [ ] **Step 3: Replace ad-hoc role checks in touched controllers**

Use `Gate::authorize('manage-plugin-tokens')` or equivalent.

- [ ] **Step 4: Verify tests**

Run:

```bash
cd backend && php artisan test tests/Feature/Dashboard/AuthDashboardApiTest.php tests/Feature/Dashboard/DashboardApiContractTest.php --display-warnings
```

**Commit:** `feat: centralize dashboard authorization`

### Task 2.4: Unify Genesis And Delta Graph Import Jobs

**Files:**
- Create: `backend/app/Jobs/ImportGraphToNeo4j.php`
- Modify: `backend/app/Jobs/ImportGenesisGraphToNeo4j.php`
- Modify: `backend/app/Services/GenesisFinalizeService.php`
- Modify: `backend/app/Services/DeltaFinalizeService.php`
- Modify: `backend/tests/Feature/GraphImportJobRetryTest.php`
- Modify: `backend/tests/Feature/DeltaSyncTest.php`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

**Interfaces:**
- Consumes: finalized genesis import or delta sync ID.
- Produces: queue-backed graph import path for both flows.

- [ ] **Step 1: Write failing Delta async test**

Assert finalizing a delta sync dispatches a graph import job instead of importing synchronously.

- [ ] **Step 2: Create unified job**

`ImportGraphToNeo4j` accepts:

```php
public function __construct(
    public readonly string $scope,
    public readonly string $importOrDeltaId,
)
```

Allowed scopes: `genesis`, `delta`.

- [ ] **Step 3: Keep legacy job as wrapper**

Keep `ImportGenesisGraphToNeo4j` if existing tests/routes reference it, but delegate to the unified job or service.

- [ ] **Step 4: Verify focused tests**

Run:

```bash
cd backend && php artisan test tests/Feature/GraphImportJobRetryTest.php tests/Feature/GenesisGraphImportTest.php tests/Feature/DeltaSyncTest.php --display-warnings
```

**Commit:** `refactor: unify graph import jobs`

### Task 2.5: Type The Neo4j Boundary And Raise Static Analysis One Level

**Files:**
- Create: `backend/app/Services/Neo4j/Neo4jClient.php`
- Create: `backend/app/Services/Neo4j/LaudisNeo4jClient.php`
- Create: `backend/app/Services/Neo4j/FakeNeo4jClient.php`
- Modify: `backend/app/Services/Neo4jClientFactory.php`
- Modify: `backend/app/Services/GenesisGraphImportService.php`
- Modify: `backend/app/Services/Neo4jRebuildService.php`
- Modify: `backend/tests/Unit/GenesisGraphCypherTest.php`
- Modify: `backend/phpstan.neon.dist`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

**Interfaces:**
- Consumes: Cypher query and parameters.
- Produces: typed Neo4j client adapter and static-analysis visibility.

- [ ] **Step 1: Write adapter tests**

Assert fake client records Cypher and parameters for graph import.

- [ ] **Step 2: Add interface**

Interface:

```php
namespace App\Services\Neo4j;

interface Neo4jClient
{
    /** @param array<string, mixed> $parameters */
    public function run(string $cypher, array $parameters = []): mixed;
}
```

- [ ] **Step 3: Update factory return type**

Change `Neo4jClientFactory::client()` from `object` to `Neo4jClient`.

- [ ] **Step 4: Raise PHPStan one level**

Set PHPStan from level `0` to `1`. Add precise ignores only for pre-existing unrelated issues and link each ignore to a follow-up.

- [ ] **Step 5: Verify**

Run:

```bash
cd backend && php artisan test tests/Unit/GenesisGraphCypherTest.php tests/Feature/GenesisGraphImportTest.php --display-warnings
cd backend && ./vendor/bin/phpstan analyse --configuration=phpstan.neon.dist
```

**Commit:** `refactor: type neo4j client boundary`

### Task 2.6: Make Python Client Reusable, Retryable, And Installable

**Files:**
- Modify: `plugin/pyproject.toml`
- Modify: `plugin/src/devboard_plugin/client.py`
- Modify: `plugin/src/devboard_plugin/config.py`
- Modify: `plugin/src/devboard_plugin/cli.py`
- Modify: `plugin/tests/test_client.py`
- Modify: `plugin/tests/test_config.py`
- Modify: `plugin/tests/test_cli_smoke.py`
- Modify: `analyzer/pyproject.toml` if dependency metadata needs alignment
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

**Interfaces:**
- Consumes: API calls and local credentials.
- Produces: pooled HTTP client, retries, clean CLI errors, declared analyzer dependency.

- [ ] **Step 1: Write failing tests**

Tests:

```python
def test_client_retries_timeout_then_success()
def test_client_wraps_connect_error_as_devboard_api_error()
def test_load_credentials_reports_missing_file_without_traceback()
def test_plugin_declares_analyzer_dependency()
```

- [ ] **Step 2: Verify failure**

Run:

```bash
cd plugin && python -m pytest -q tests/test_client.py tests/test_config.py tests/test_cli_smoke.py
```

- [ ] **Step 3: Add reusable client lifecycle**

Allow `DevBoardClient` to reuse a provided or lazily created `httpx.Client`. Keep tests using `MockTransport`.

- [ ] **Step 4: Add retries**

Retry `ConnectError`, `TimeoutException`, and HTTP `5xx` up to 3 attempts with exponential backoff. Do not retry `4xx`.

- [ ] **Step 5: Wire CLI error handling**

Ensure `DevBoardApiError` and credential load errors are rendered as clean CLI messages through the existing `handle_api_error` path.

- [ ] **Step 6: Declare analyzer dependency**

Add `devboard-analyzer` as a plugin dependency using the local packaging approach already used in this repo.

- [ ] **Step 7: Verify**

Run:

```bash
cd plugin && python -m pytest -q
cd analyzer && python -m pytest -q
```

**Commit:** `fix: harden python plugin client`

---

## Wave 3 - P2 Deterministic Semantic Memory

### Task 3.1: Reconcile Genesis And Hades Memory Planes

**Files:**
- Create: `docs/ai-devboard/13_MEMORY_GRAPH_RECONCILIATION.md`
- Modify: `docs/ai-devboard/README.md` if present
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

**Interfaces:**
- Consumes: Genesis/Plugin graph path and Hades graph artifact path.
- Produces: documented canonical graph source and API ownership.

- [ ] **Step 1: Inventory current graph paths**

Run:

```bash
rg -n "Neo4j|graph_snapshot|hades.*graph|GraphTraversal|QueryProjectGraphTool|code_graph" backend plugin analyzer docs/ai-devboard
```

- [ ] **Step 2: Write decision doc**

Document:

```text
Canonical structural graph: Neo4j rebuilt from Postgres + artifact storage.
Hades graph reads: query canonical graph, do not maintain a parallel artifact-only graph for new features.
Artifact JSON graph: import/rebuild input and compatibility fallback.
Search index: derived projection, not source of truth.
```

- [ ] **Step 3: Add migration rules**

List which existing Hades endpoints keep compatibility and which new endpoints must query Neo4j.

- [ ] **Step 4: Verify no unresolved markers**

Run:

```bash
rg -n "UNRESOLVED|FILL_ME|NOT_DECIDED" docs/ai-devboard/13_MEMORY_GRAPH_RECONCILIATION.md
```

Expected: no matches.

**Commit:** `docs: reconcile memory graph planes`

### Task 3.2: Resolve Python Internal CALLS Edges

**Files:**
- Modify: `analyzer/src/devboard_analyzer/code_graph.py`
- Modify: `analyzer/tests/test_code_graph.py`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

**Interfaces:**
- Consumes: Python source tree.
- Produces: `CALLS` edges to internal symbol IDs when resolvable; `external:<name>` only when unresolved.

- [ ] **Step 1: Write failing analyzer tests**

Add fixture code:

```python
def helper():
    return 1

def caller():
    return helper()
```

Assert graph contains a `CALLS` relationship from `caller` to the internal `helper` node ID.

Also test:

```python
import json

def caller():
    return json.dumps({})
```

Assert unresolved/external calls remain external.

- [ ] **Step 2: Verify failure**

Run:

```bash
cd analyzer && python -m pytest -q tests/test_code_graph.py
```

- [ ] **Step 3: Implement two-pass resolution**

Pass 1 builds symbol index by qualified name, local name, and file-local definitions. Pass 2 resolves call expressions to internal symbol IDs before falling back to `external:<name>`.

- [ ] **Step 4: Verify analyzer suite**

Run:

```bash
cd analyzer && python -m pytest -q
```

**Commit:** `feat: resolve python call graph edges`

### Task 3.3: Emit Real Neo4j Labels And Relationship Types

**Files:**
- Modify: `backend/app/Services/GenesisGraphImportService.php`
- Modify: `backend/tests/Unit/GenesisGraphCypherTest.php`
- Modify: `backend/tests/Feature/GenesisGraphImportTest.php`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

**Interfaces:**
- Consumes: analyzer graph nodes and relationships.
- Produces: typed Neo4j graph with labels such as `File`, `Class`, `Function` and relationships such as `DECLARES`, `CALLS`, `IMPORTS`.

- [ ] **Step 1: Write failing Cypher tests**

Assert generated Cypher contains:

```cypher
:Function
:File
[:CALLS]
[:DECLARES]
```

And does not flatten all edges into only:

```cypher
:CodeNode
[:RELATED]
```

- [ ] **Step 2: Verify failure**

Run:

```bash
cd backend && php artisan test tests/Unit/GenesisGraphCypherTest.php --display-warnings
```

- [ ] **Step 3: Map node kinds and relation types**

Use an allowlist. Unknown node kinds can keep `CodeNode`; unknown relationship types can keep `RELATED` with a `type` property for compatibility.

- [ ] **Step 4: Verify graph import tests**

Run:

```bash
cd backend && php artisan test tests/Unit/GenesisGraphCypherTest.php tests/Feature/GenesisGraphImportTest.php --display-warnings
```

**Commit:** `feat: import typed neo4j graph`

### Task 3.4: Add Structured Graph Query API And Tool

**Files:**
- Create: `backend/app/Services/Graph/GraphQueryService.php`
- Create: `backend/app/Http/Controllers/Plugin/GraphQueryController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/app/Assistants/Tools/QueryProjectGraphTool.php`
- Modify: `backend/tests/Feature/Plugin/GraphQueryApiTest.php`
- Modify: `backend/tests/Feature/Assistants/AiAgentReadToolsTest.php`
- Modify: `plugin/src/devboard_plugin/client.py`
- Modify: `plugin/src/devboard_plugin/mcp_tools.py`
- Modify: `plugin/tests/test_mcp_tools.py`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

**Interfaces:**
- Consumes: project/repository ID and structured query type.
- Produces: graph query results without exposing arbitrary Cypher to external agents.

- [ ] **Step 1: Define query contract**

Supported query types:

```json
{"type": "callers", "symbol_id": "...", "limit": 50}
{"type": "callees", "symbol_id": "...", "limit": 50}
{"type": "path", "from_symbol_id": "...", "to_symbol_id": "...", "max_depth": 5}
```

- [ ] **Step 2: Write failing backend tests**

Assert plugin token with `projects.read` can query callers and receives bounded results. Assert missing scope returns `403`.

- [ ] **Step 3: Write failing MCP tests**

Assert `devboard_query_graph` calls `/api/plugin/v1/projects/{project}/graph/query` and returns structured rows.

- [ ] **Step 4: Implement allowlisted Cypher**

Do not accept raw Cypher from plugin clients. Build Cypher from the query type allowlist.

- [ ] **Step 5: Verify**

Run:

```bash
cd backend && php artisan test tests/Feature/Plugin/GraphQueryApiTest.php tests/Feature/Assistants/AiAgentReadToolsTest.php --display-warnings
cd plugin && python -m pytest -q tests/test_mcp_tools.py
```

**Commit:** `feat: expose structured graph queries`

### Task 3.5: Fix Hades Search For PostgreSQL Before Vector Search

**Files:**
- Create: `backend/database/migrations/*_add_postgres_full_text_to_hades_search_documents.php`
- Modify: `backend/app/Services/Hades/HadesSearchDocumentIndexer.php`
- Modify: `backend/app/Http/Controllers/Hades/MemorySearchController.php`
- Modify: `backend/tests/Feature/Hades/HadesM3SharedMemoryTest.php`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

**Interfaces:**
- Consumes: Hades search documents.
- Produces: Postgres full-text search path instead of MySQL-only `MATCH ... AGAINST` fallback.

- [ ] **Step 1: Write Postgres-specific test plan**

Add a test that is skipped on SQLite with a clear message and runs on Postgres CI/local Docker:

```php
if (DB::connection()->getDriverName() !== 'pgsql') {
    $this->markTestSkipped('Postgres full-text search requires pgsql.');
}
```

- [ ] **Step 2: Add generated/search vector column**

Use Postgres `tsvector` and a GIN index. Keep SQLite-compatible fallback for regular unit tests.

- [ ] **Step 3: Replace MySQL query path**

Use `to_tsvector`, `plainto_tsquery`, or `websearch_to_tsquery` when driver is `pgsql`. Keep `LIKE` only as fallback for non-Postgres local tests.

- [ ] **Step 4: Verify with Postgres**

Run in the app container or equivalent Postgres-backed environment:

```bash
cd backend && php artisan test tests/Feature/Hades/HadesM3SharedMemoryTest.php --display-warnings
```

Expected: Postgres full-text test passes when driver is `pgsql`; SQLite suite remains green.

**Commit:** `fix: use postgres full text for hades search`

---

## Wave 4 - AI/RAG And Scale Gates

### Task 4.1: Generate Behavior Wiki Only From Evidence References

**Files:**
- Create: `backend/app/Assistants/BehaviorWikiDraftService.php`
- Create: `backend/tests/Feature/Assistants/BehaviorWikiDraftServiceTest.php`
- Modify: `backend/app/Assistants/AiAgentRegistry.php` only if provider access is required
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

**Interfaces:**
- Consumes: resolved graph query results and artifact/wiki evidence references.
- Produces: draft wiki revisions with `source_status = needs_verification`.

- [ ] **Step 1: Gate on deterministic prerequisites**

Before starting, verify Task 3.2, 3.3, and 3.4 are merged and passing.

- [ ] **Step 2: Write tests**

Assert generated drafts include:

```text
symbol_id
summary
preconditions
side_effects
evidence_refs
source_status = needs_verification
```

Assert drafts are not published as verified code facts.

- [ ] **Step 3: Implement draft-only service**

No automatic mutation to verified wiki content. Human/agent review must promote status separately.

- [ ] **Step 4: Verify**

Run:

```bash
cd backend && php artisan test tests/Feature/Assistants/BehaviorWikiDraftServiceTest.php --display-warnings
```

**Commit:** `feat: draft behavior wiki from graph evidence`

### Task 4.2: Add Pgvector Retrieval After Wiki Draft Evidence Exists

**Files:**
- Create: `backend/database/migrations/*_add_embeddings_to_memory_search.php`
- Create: `backend/app/Services/Search/EmbeddingIndexService.php`
- Create: `backend/tests/Feature/Search/EmbeddingIndexServiceTest.php`
- Modify: `backend/app/Http/Controllers/Hades/MemorySearchController.php`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

**Interfaces:**
- Consumes: verified/draft wiki revisions, memory entries, run summaries, causal packs.
- Produces: vector search as a secondary ranking signal, never the only evidence source.

- [ ] **Step 1: Gate on Task 4.1**

Do not start until behavior wiki drafts with evidence refs exist.

- [ ] **Step 2: Add migration with extension check**

Enable `vector` extension only when driver is `pgsql`; document local setup if extension is missing.

- [ ] **Step 3: Write tests**

Assert search results include both similarity score and evidence refs. Assert entries with no evidence refs are marked `needs_verification`.

- [ ] **Step 4: Implement embedding index service**

Index only:

```text
wiki revisions
project memory entries
run summaries
artifact summaries
hades causal packs
```

- [ ] **Step 5: Verify**

Run:

```bash
cd backend && php artisan test tests/Feature/Search/EmbeddingIndexServiceTest.php --display-warnings
```

**Commit:** `feat: add evidence-backed vector retrieval`

### Task 4.3: Plan Multi-Tenancy As A Separate Migration Program

**Files:**
- Create: `docs/superpowers/plans/YYYY-MM-DD-devboard-multitenancy.md`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

**Interfaces:**
- Consumes: current project/user/token model.
- Produces: separate plan for organizations, memberships, tenant scopes, token scoping, and graph partitioning.

- [ ] **Step 1: Inventory tenant-sensitive tables**

Run:

```bash
cd backend && php artisan schema:dump --prune --database=pgsql
rg -n "project_id|user_id|organization|tenant|workspace" backend/database backend/app
```

- [ ] **Step 2: Write separate plan**

Do not mix tenant migration into P0/P1/P2 tasks. The plan must include backfill, migration safety, query scoping, graph partitioning, and rollout.

- [ ] **Step 3: Verify no code is changed**

Run:

```bash
git diff --name-only -- backend app plugin analyzer
```

Expected: no application code changes for this planning task.

**Commit:** `docs: plan devboard multitenancy`

---

## Quality Gates Per Wave

### After Wave 1

Run:

```bash
cd backend && php artisan test tests/Feature/PluginAuthTest.php tests/Feature/GenesisUploadTest.php tests/Feature/DeltaSyncTest.php tests/Feature/Dashboard/AiAgentRegistryDashboardTest.php --display-warnings
cd plugin && python -m pytest -q tests/test_client.py tests/test_config.py tests/test_artifact_upload.py
git diff --check
```

Exit criteria:

- Invalid artifact IDs return `422`.
- Oversize artifacts/chunks are rejected.
- Bound plugin tokens require valid device signatures.
- Production compose has no hardcoded default secrets.
- Critical security audit events exist.
- Provider endpoints reject private/link-local targets.

### After Wave 2

Run:

```bash
cd backend && php artisan test --display-warnings
cd backend && ./vendor/bin/pint --test
cd backend && ./vendor/bin/phpstan analyse --configuration=phpstan.neon.dist
cd plugin && python -m pytest -q
cd analyzer && python -m pytest -q
```

Exit criteria:

- Core Eloquent models exist and are used by new code.
- Plugin write validation is in FormRequest classes.
- Delta graph imports are queued like Genesis imports.
- Neo4j client boundary is typed.
- Python plugin installs and handles network/config errors cleanly.

### After Wave 3

Run:

```bash
cd analyzer && python -m pytest -q tests/test_code_graph.py
cd backend && php artisan test tests/Unit/GenesisGraphCypherTest.php tests/Feature/GenesisGraphImportTest.php tests/Feature/Plugin/GraphQueryApiTest.php --display-warnings
cd plugin && python -m pytest -q tests/test_mcp_tools.py
```

Exit criteria:

- Internal Python calls resolve to internal symbol IDs.
- Neo4j import uses typed labels and relationships for known node/edge kinds.
- Agents can ask structured graph questions without raw Cypher.
- Hades/Genesis graph ownership is documented.

### Before Wave 4

Block Wave 4 if any are false:

- `docs/ai-devboard/13_MEMORY_GRAPH_RECONCILIATION.md` exists and is accepted.
- Analyzer emits internal `CALLS` edges for at least Python.
- Neo4j import preserves real relationship types.
- A structured graph query API/tool exists.
- Hades search no longer depends on MySQL-only full-text syntax in Postgres runtime.

## Self-Review

- Spec coverage: all P0 items from `claude_suggestions.md` map to Wave 1 tasks; P1 foundations map to Wave 2; P2 deterministic graph/query/search gates map to Wave 3; AI/RAG and multi-tenancy are deferred to Wave 4 with explicit prerequisites.
- Red-flag scan: no unresolved-marker text is used as task content.
- Type consistency: request-signing headers, ULID helper, Neo4j interface, and graph query contract are named once and reused consistently.
- Scope control: broad refactors such as replacing all `DB::table()` calls are deliberately incremental; Task 2.1 adds models for new code first.
