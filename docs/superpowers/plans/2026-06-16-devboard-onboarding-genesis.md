# DevBoard Onboarding Genesis Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the first working DevBoard vertical slice: Admin creates a plugin token, Python plugin registers a device and links a repository, plugin generates a Genesis bundle, backend receives chunked artifacts, imports graph data into Neo4j, writes wiki revisions, and the dashboard shows project/run status.

**Architecture:** Use a monorepo with `backend/` for Laravel + Inertia React, `plugin/` for the Python CLI/MCP-local core, and `analyzer/` for reusable Python analysis code migrated from `ai-sandbox`. The backend remains the orchestrator and source of server state; the plugin remains the only component reading local working copies.

**Tech Stack:** Laravel + Inertia React, PostgreSQL, filesystem artifact storage, Neo4j, Python 3, pytest, tree-sitter/Graphify-compatible analyzer adapters.

## Execution Status - 2026-06-16

The first vertical slice has been implemented through Task 12 on branch `fase-1`.

Verified locally:

- backend domain, plugin auth, repository linkage, run lifecycle, Genesis artifact upload/finalize, wiki revisions, graph import service, dashboard auth/slice, and onboarding Genesis E2E;
- analyzer Genesis bundles now emit backend-compatible `artifact_id` and `chunk_count` values;
- `scripts/devboard_e2e_bootstrap.sh` runs the full happy path against isolated SQLite and records graph acceptance in fake mode when live Neo4j is not available.

Remaining deployment acceptance:

- run the same flow against Docker Compose PostgreSQL and a live Neo4j service on the target Ubuntu x64 environment.

---

## Required Specs

Read these before starting implementation:

- `docs/ai-devboard/03_DOMAIN_MODEL.md`
- `docs/ai-devboard/04_PLUGIN_SERVER_CONTRACT.md`
- `docs/ai-devboard/05_GENESIS_IMPORT.md`
- `docs/ai-devboard/07_SECURITY_MODEL.md`
- `docs/ai-devboard/08_IMPLEMENTATION_STEPS.md`
- `docs/ai-devboard/09_DASHBOARD_WIREFRAMES.md`
- `docs/ai-devboard/10_RUNTIME_SEQUENCES.md`

## Target File Structure

Create these top-level modules:

```text
backend/
  app/
  bootstrap/
  config/
  database/
  resources/js/
  routes/
  tests/
plugin/
  pyproject.toml
  src/devboard_plugin/
  tests/
analyzer/
  pyproject.toml
  src/devboard_analyzer/
  tests/
docker/
  devboard/
fixtures/
  repos/simple-python/
docs/ai-devboard/
docs/superpowers/plans/
docker-compose.devboard.yaml
docker-compose.devboard.amd64.yaml
```

Keep `ai-sandbox/` unchanged except for logbooks until its logic is deliberately migrated into `analyzer/` or `plugin/`.

## Commit Strategy

Commit after each task that leaves tests passing.

Use commit messages:

```text
chore: scaffold devboard backend and plugin workspace
feat: add devboard domain schema
feat: add plugin token and device auth
feat: add project repository policy endpoints
feat: add plugin auth and repo link commands
feat: add run lifecycle
feat: add genesis analyzer bundle
feat: add artifact upload and genesis finalize
feat: import genesis graph into neo4j
feat: write genesis wiki revisions
feat: add devboard dashboard slice
test: add onboarding genesis e2e coverage
```

## Task 1: Scaffold Backend, Plugin, Analyzer, and Fixtures

**Files:**

- Create: `backend/**`
- Create: `plugin/pyproject.toml`
- Create: `plugin/src/devboard_plugin/__init__.py`
- Create: `plugin/src/devboard_plugin/cli.py`
- Create: `plugin/tests/test_cli_smoke.py`
- Create: `analyzer/pyproject.toml`
- Create: `analyzer/src/devboard_analyzer/__init__.py`
- Create: `analyzer/tests/test_analyzer_smoke.py`
- Create: `docker-compose.devboard.yaml`
- Create: `docker-compose.devboard.amd64.yaml`
- Create: `docker/devboard/backend.Dockerfile`
- Create: `docker/devboard/php.ini`
- Create: `docker/devboard/README.md`
- Create: `fixtures/repos/simple-python/pyproject.toml`
- Create: `fixtures/repos/simple-python/src/simple_app/__init__.py`
- Create: `fixtures/repos/simple-python/src/simple_app/routes.py`
- Modify: `ai-sandbox/config/project.yaml`
- Modify: `ai-sandbox/config/dependencies.lock.yaml`
- Modify: `.gitignore`

- [x] **Step 1: Create an isolated implementation workspace**

Run:

```bash
git status --short --untracked-files=all
```

Expected: only previously agreed documentation files are modified or untracked. Stop if unrelated user changes appear in files this plan will edit.

- [x] **Step 2: Scaffold Laravel backend**

Run:

```bash
composer create-project laravel/laravel backend
cd backend
composer require inertiajs/inertia-laravel
composer require laravel/sanctum
composer require laudis/neo4j-php-client
composer require pestphp/pest --dev --with-all-dependencies
php artisan pest:install
npm install @inertiajs/react react react-dom vite @vitejs/plugin-react lucide-react
```

Expected: Laravel app exists under `backend/`; `composer test` or `php artisan test` can run.

- [x] **Step 3: Add backend environment defaults**

Update `backend/.env.example` with PostgreSQL, artifact disk, and Neo4j variables:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=devboard
DB_USERNAME=devboard
DB_PASSWORD=devboard

DEVBOARD_ARTIFACT_DISK=local
DEVBOARD_ARTIFACT_ROOT=artifacts

NEO4J_URI=bolt://localhost:7687
NEO4J_USER=neo4j
NEO4J_PASSWORD=graphify-sandbox
```

- [x] **Step 4: Write backend smoke test**

Create `backend/tests/Feature/HealthTest.php`:

```php
<?php

use Illuminate\Support\Facades\Route;

it('loads the application test environment', function () {
    Route::get('/_test/health', fn () => response()->json(['ok' => true]));

    $this->getJson('/_test/health')
        ->assertOk()
        ->assertJson(['ok' => true]);
});
```

- [x] **Step 5: Verify backend smoke test fails before route registration is fixed if needed**

Run:

```bash
cd backend
php artisan test tests/Feature/HealthTest.php
```

Expected: PASS. This is a scaffold smoke check, not a product behavior test.

- [x] **Step 6: Scaffold Python plugin**

Create `plugin/pyproject.toml`:

```toml
[project]
name = "devboard-plugin"
version = "0.1.0"
requires-python = ">=3.11"
dependencies = [
  "httpx>=0.27",
  "pydantic>=2",
  "typer>=0.12",
]

[project.scripts]
devboard = "devboard_plugin.cli:app"

[tool.pytest.ini_options]
pythonpath = ["src"]
testpaths = ["tests"]
```

Create `plugin/src/devboard_plugin/cli.py`:

```python
import typer

app = typer.Typer(help="DevBoard local plugin")


@app.command()
def version() -> None:
    typer.echo("devboard-plugin 0.1.0")
```

Create `plugin/tests/test_cli_smoke.py`:

```python
from typer.testing import CliRunner

from devboard_plugin.cli import app


def test_version_command_outputs_version():
    result = CliRunner().invoke(app, ["version"])

    assert result.exit_code == 0
    assert "devboard-plugin 0.1.0" in result.output
```

- [x] **Step 7: Scaffold analyzer package**

Create `analyzer/pyproject.toml`:

```toml
[project]
name = "devboard-analyzer"
version = "0.1.0"
requires-python = ">=3.11"
dependencies = [
  "pydantic>=2",
]

[tool.pytest.ini_options]
pythonpath = ["src"]
testpaths = ["tests"]
```

Create `analyzer/tests/test_analyzer_smoke.py`:

```python
def test_analyzer_package_imports():
    import devboard_analyzer

    assert devboard_analyzer.__name__ == "devboard_analyzer"
```

- [x] **Step 8: Run scaffold tests**

Run:

```bash
cd plugin && python3 -m pytest -q
cd ../analyzer && python3 -m pytest -q
cd ../backend && php artisan test
```

Expected: all scaffold tests pass. If `pytest` is missing, create local virtualenvs and install test dependencies from `pyproject.toml` before rerunning.

- [x] **Step 9: Add Docker compose scaffolding**

Add a Docker Compose runtime for `app`, `node`, `postgres`, and `neo4j`.

Rules:

- Use `docker info --format '{{.OSType}}/{{.Architecture}}'` to record the current Docker platform.
- Keep the base compose file multi-arch and do not infer image architecture from the Mac host.
- Add an amd64 override with `platform: linux/amd64` for Ubuntu x64 deployment validation.
- Keep PostgreSQL and Neo4j credentials aligned with `backend/.env.example`.

Verify:

```bash
docker compose -f docker-compose.devboard.yaml config
docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.amd64.yaml config
```

- [x] **Step 10: Commit**

Run:

```bash
git add backend plugin analyzer fixtures docker docker-compose.devboard.yaml docker-compose.devboard.amd64.yaml .gitignore ai-sandbox/config/project.yaml ai-sandbox/config/dependencies.lock.yaml docs/superpowers/plans/2026-06-16-devboard-onboarding-genesis.md
git commit -m "chore: scaffold devboard backend and plugin workspace"
```

## Task 2: Add Backend Domain Schema and Seed Data

**Files:**

- Create: `backend/database/migrations/*_create_devboard_core_tables.php`
- Create: `backend/database/seeders/DevBoardSeeder.php`
- Modify: `backend/database/seeders/DatabaseSeeder.php`
- Create: `backend/app/Enums/RunStatus.php`
- Create: `backend/app/Enums/SourceStatus.php`
- Create: `backend/app/Enums/SourceType.php`
- Create: `backend/tests/Feature/DomainSchemaTest.php`

- [x] **Step 1: Write failing schema test**

Create `backend/tests/Feature/DomainSchemaTest.php`:

```php
<?php

use Illuminate\Support\Facades\Schema;

it('creates the devboard core tables', function () {
    $tables = [
        'roles',
        'permissions',
        'api_tokens',
        'devices',
        'projects',
        'repositories',
        'local_workspaces',
        'tasks',
        'kanban_boards',
        'kanban_columns',
        'runs',
        'run_events',
        'artifacts',
        'snapshots',
        'genesis_imports',
        'wiki_pages',
        'wiki_revisions',
        'audit_logs',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue($table);
    }
});

it('seeds the required role names and default kanban columns', function () {
    $this->seed(\Database\Seeders\DevBoardSeeder::class);

    expect(DB::table('roles')->pluck('name')->all())
        ->toEqualCanonicalizing(['Admin', 'PM', 'Developer', 'Sysadmin', 'Agent']);

    expect(DB::table('kanban_columns')->pluck('name')->all())
        ->toEqualCanonicalizing(['Backlog', 'Ready', 'In Progress', 'Blocked', 'Review', 'Done']);
});
```

- [x] **Step 2: Verify test fails**

Run:

```bash
cd backend
php artisan test tests/Feature/DomainSchemaTest.php
```

Expected: FAIL because the DevBoard tables do not exist yet.

- [x] **Step 3: Implement migrations**

Create migrations matching `docs/ai-devboard/03_DOMAIN_MODEL.md`. Use ULID or UUID primary keys consistently. Required JSON columns:

```text
roles.permissions
api_tokens.scopes
repositories.protected_paths
repositories.excluded_paths
repositories.stack_hints
run_events.payload
artifacts.metadata
wiki_revisions.evidence_refs
audit_logs.payload
```

Required indexes:

```text
api_tokens.token_prefix
devices.user_id
repositories.project_id
local_workspaces.repository_id
tasks.project_id
runs.project_id
runs.repository_id
runs.task_id
artifacts.run_id
snapshots.repository_id
genesis_imports.repository_id
wiki_pages.project_id
wiki_pages.repository_id
audit_logs.actor_type
audit_logs.action
```

- [x] **Step 4: Implement enums**

Create enum values exactly:

```php
<?php

namespace App\Enums;

enum SourceStatus: string
{
    case VerifiedFromCode = 'verified_from_code';
    case DeveloperProvided = 'developer_provided';
    case AiGenerated = 'ai_generated';
    case NeedsVerification = 'needs_verification';
    case Stale = 'stale';
    case ConflictWithCode = 'conflict_with_code';
}
```

Create equivalent enums for `SourceType` and `RunStatus` using values from `03_DOMAIN_MODEL.md`.

- [x] **Step 5: Implement seed data**

`DevBoardSeeder` must create:

```text
roles: Admin, PM, Developer, Sysadmin, Agent
permissions from 03_DOMAIN_MODEL.md
one project: Demo Project
one repository: demo-repository, git_mode local_only, code_exposure full_code_artifacts
one default Kanban board
columns: Backlog, Ready, In Progress, Blocked, Review, Done
```

- [x] **Step 6: Verify schema tests pass**

Run:

```bash
cd backend
php artisan test tests/Feature/DomainSchemaTest.php
```

Expected: PASS.

- [x] **Step 7: Commit**

Run:

```bash
git add backend/database backend/app/Enums backend/tests/Feature/DomainSchemaTest.php
git commit -m "feat: add devboard domain schema"
```

## Task 3: Plugin Token and Device Authentication

**Files:**

- Create: `backend/app/Services/PluginTokenService.php`
- Create: `backend/app/Http/Middleware/AuthenticatePluginToken.php`
- Create: `backend/app/Http/Controllers/Plugin/AuthCheckController.php`
- Create: `backend/app/Http/Controllers/Plugin/RegisterDeviceController.php`
- Modify: `backend/routes/api.php`
- Create: `backend/tests/Feature/PluginAuthTest.php`

- [x] **Step 1: Write failing auth tests**

Create tests for:

```text
POST /api/plugin/v1/auth/check rejects missing token with 401
POST /api/plugin/v1/auth/check accepts devb_live_<id>|<secret>
POST /api/plugin/v1/devices/register binds active device to token
revoked token returns token_revoked
wrong secret returns unauthorized
```

Use token hash storage from `04_PLUGIN_SERVER_CONTRACT.md`.

- [x] **Step 2: Verify auth tests fail**

Run:

```bash
cd backend
php artisan test tests/Feature/PluginAuthTest.php
```

Expected: FAIL because routes and services do not exist.

- [x] **Step 3: Implement token service**

`PluginTokenService` must:

```text
parse token prefix and secret
find token row by id/prefix
verify hash using hash_equals
reject expired or revoked tokens
update last_used_at on success
return authenticated token, user, device when present
```

Use SHA-256 or HMAC-SHA256 for secret hash as specified.

- [x] **Step 4: Implement middleware and controllers**

Routes:

```php
Route::prefix('plugin/v1')->group(function () {
    Route::post('/auth/check', AuthCheckController::class);
    Route::post('/devices/register', RegisterDeviceController::class);
});
```

Responses must follow the error shape in `04_PLUGIN_SERVER_CONTRACT.md`.

- [x] **Step 5: Verify auth tests pass**

Run:

```bash
cd backend
php artisan test tests/Feature/PluginAuthTest.php
```

Expected: PASS.

- [x] **Step 6: Commit**

Run:

```bash
git add backend/app backend/routes backend/tests/Feature/PluginAuthTest.php
git commit -m "feat: add plugin token and device auth"
```

## Task 4: Project, Repository, Policy, and Local Workspace API

**Files:**

- Create: `backend/app/Http/Controllers/Plugin/ListProjectsController.php`
- Create: `backend/app/Http/Controllers/Plugin/ListRepositoriesController.php`
- Create: `backend/app/Http/Controllers/Plugin/RegisterLocalWorkspaceController.php`
- Create: `backend/app/Http/Controllers/Plugin/RepositoryPolicyController.php`
- Create: `backend/app/Http/Controllers/Plugin/RepositoryInstructionsController.php`
- Create: `backend/tests/Feature/PluginRepositoryApiTest.php`
- Modify: `backend/routes/api.php`

- [x] **Step 1: Write failing repository API tests**

Tests must cover:

```text
authenticated plugin can list projects
authenticated plugin can list repositories for a project
plugin can register local workspace
repository policy returns local_only, full_code_artifacts, graph_required true
instructions response excludes token-like content
missing scope returns scope_missing
```

- [x] **Step 2: Verify repository API tests fail**

Run:

```bash
cd backend
php artisan test tests/Feature/PluginRepositoryApiTest.php
```

Expected: FAIL because endpoints do not exist.

- [x] **Step 3: Implement controllers**

Add routes:

```text
GET  /api/plugin/v1/projects
GET  /api/plugin/v1/projects/{project}/repositories
POST /api/plugin/v1/repositories/{repository}/local-workspaces
GET  /api/plugin/v1/repositories/{repository}/policy
GET  /api/plugin/v1/repositories/{repository}/instructions
```

Policy response must match `RepositoryPolicy` in `04_PLUGIN_SERVER_CONTRACT.md`.

- [x] **Step 4: Verify repository API tests pass**

Run:

```bash
cd backend
php artisan test tests/Feature/PluginRepositoryApiTest.php
```

Expected: PASS.

- [x] **Step 5: Commit**

Run:

```bash
git add backend/app/Http/Controllers/Plugin backend/routes/api.php backend/tests/Feature/PluginRepositoryApiTest.php
git commit -m "feat: add project repository policy endpoints"
```

## Task 5: Python Plugin Auth, Device, and Repo Link Commands

**Files:**

- Create: `plugin/src/devboard_plugin/config.py`
- Create: `plugin/src/devboard_plugin/client.py`
- Create: `plugin/src/devboard_plugin/state.py`
- Create: `plugin/src/devboard_plugin/git_local.py`
- Modify: `plugin/src/devboard_plugin/cli.py`
- Create: `plugin/tests/test_config.py`
- Create: `plugin/tests/test_client.py`
- Create: `plugin/tests/test_repo_link.py`

- [x] **Step 1: Write failing plugin tests**

Tests must cover:

```text
credentials path resolves to ~/.config/devboard/credentials.json
credentials serialization never writes token into .devboard/state.json
client sends X-DevBoard-Protocol v1
repo link creates .devboard/state.json with project/repository/local_workspace ids
.devboard is added to .git/info/exclude when .git exists
```

- [x] **Step 2: Verify plugin tests fail**

Run:

```bash
cd plugin
python3 -m pytest -q
```

Expected: FAIL because modules do not exist.

- [x] **Step 3: Implement plugin modules**

Responsibilities:

```text
config.py: credential paths and token loading
client.py: httpx client, headers, error shape handling
state.py: .devboard/state.json read/write without secrets
git_local.py: branch/head/dirty status and .git/info/exclude helper
cli.py: auth check, auth register-device, projects list, repos link, repos policy, context pull
```

- [x] **Step 4: Verify plugin tests pass**

Run:

```bash
cd plugin
python3 -m pytest -q
```

Expected: PASS.

- [x] **Step 5: Commit**

Run:

```bash
git add plugin
git commit -m "feat: add plugin auth and repo link commands"
```

## Task 6: Run Lifecycle Backend and Plugin Commands

**Files:**

- Create: `backend/app/Http/Controllers/Plugin/RunStartController.php`
- Create: `backend/app/Http/Controllers/Plugin/RunHeartbeatController.php`
- Create: `backend/app/Http/Controllers/Plugin/RunEventController.php`
- Create: `backend/app/Http/Controllers/Plugin/RunFinishController.php`
- Create: `backend/tests/Feature/RunLifecycleTest.php`
- Modify: `backend/routes/api.php`
- Modify: `plugin/src/devboard_plugin/cli.py`
- Create: `plugin/tests/test_runs.py`

- [x] **Step 1: Write failing backend run lifecycle tests**

Cover:

```text
POST /api/plugin/v1/runs creates started run
heartbeat appends heartbeat status/event
run event append is immutable
finish sets finished status
failed finish records risk summary
finished run rejects new artifact_uploaded event
```

- [x] **Step 2: Write failing plugin run tests**

Cover:

```text
devboard runs start sends RunStartPayload
devboard runs heartbeat sends run id
devboard runs finish sends status and summary
```

- [x] **Step 3: Run failing tests**

Run:

```bash
cd backend && php artisan test tests/Feature/RunLifecycleTest.php
cd ../plugin && python3 -m pytest tests/test_runs.py -q
```

Expected: FAIL.

- [x] **Step 4: Implement backend run controllers**

Use statuses from `03_DOMAIN_MODEL.md`. Every mutating call writes a `run_events` row. Denied or invalid transitions use the standard error shape.

- [x] **Step 5: Implement plugin run commands**

Commands:

```text
devboard runs start
devboard runs heartbeat
devboard runs finish
```

Read ids from `.devboard/state.json` unless explicitly passed.

- [x] **Step 6: Verify run lifecycle tests pass**

Run:

```bash
cd backend && php artisan test tests/Feature/RunLifecycleTest.php
cd ../plugin && python3 -m pytest tests/test_runs.py -q
```

Expected: PASS.

- [x] **Step 7: Commit**

Run:

```bash
git add backend plugin
git commit -m "feat: add run lifecycle"
```

## Task 7: Genesis Analyzer Bundle

**Files:**

- Create: `analyzer/src/devboard_analyzer/file_inventory.py`
- Create: `analyzer/src/devboard_analyzer/file_hashes.py`
- Create: `analyzer/src/devboard_analyzer/safety.py`
- Create: `analyzer/src/devboard_analyzer/genesis_bundle.py`
- Create: `analyzer/tests/test_file_inventory.py`
- Create: `analyzer/tests/test_safety.py`
- Create: `analyzer/tests/test_genesis_bundle.py`
- Modify: `plugin/src/devboard_plugin/cli.py`
- Create: `plugin/tests/test_genesis_command.py`

- [x] **Step 1: Write failing analyzer tests**

Cover:

```text
file inventory excludes .git and node_modules
file hashes produce stable sha256
.env is hard-blocked
private key text is hard-blocked
vendor/cache/build paths warn or exclude
Genesis bundle contains required artifact filenames
manifest contains protocol_version v1 and code_exposure full_code_artifacts
wiki page artifact includes source_status and evidence_refs
```

- [x] **Step 2: Verify analyzer tests fail**

Run:

```bash
cd analyzer
python3 -m pytest -q
```

Expected: FAIL.

- [x] **Step 3: Implement analyzer modules**

Implement:

```text
iter_repository_files(root)
hash_file(path)
scan_safety(root, paths)
build_genesis_bundle(root, output_dir, context)
```

Initial Graphify integration can use a graph artifact adapter that emits valid `graph-snapshot.json` from file and symbol placeholders for the fixture repository. It must keep the public artifact schema stable for later Graphify replacement.

- [x] **Step 4: Add plugin genesis command**

Command:

```text
devboard genesis run --output .devboard/artifacts/genesis/<run_id>
```

The command must:

```text
start run if no run id is supplied
pull policy
build local bundle
store bundle path in .devboard/state.json
not upload yet
```

- [x] **Step 5: Verify analyzer and plugin genesis tests pass**

Run:

```bash
cd analyzer && python3 -m pytest -q
cd ../plugin && python3 -m pytest tests/test_genesis_command.py -q
```

Expected: PASS.

- [x] **Step 6: Commit**

Run:

```bash
git add analyzer plugin fixtures
git commit -m "feat: add genesis analyzer bundle"
```

## Task 8: Artifact Upload and Genesis Finalize

**Files:**

- Create: `backend/app/Services/ArtifactStorageService.php`
- Create: `backend/app/Services/GenesisFinalizeService.php`
- Create: `backend/app/Http/Controllers/Plugin/GenesisStartController.php`
- Create: `backend/app/Http/Controllers/Plugin/GenesisChunkController.php`
- Create: `backend/app/Http/Controllers/Plugin/GenesisFinalizeController.php`
- Create: `backend/app/Http/Controllers/Plugin/GenesisStatusController.php`
- Create: `backend/tests/Feature/GenesisUploadTest.php`
- Modify: `backend/routes/api.php`
- Modify: `plugin/src/devboard_plugin/cli.py`
- Create: `plugin/tests/test_artifact_upload.py`

- [x] **Step 1: Write failing backend upload tests**

Cover:

```text
Genesis start creates genesis_import and artifact rows
chunk upload stores chunk by import/artifact/index
duplicate same chunk succeeds
duplicate different hash fails
finalize missing chunk fails
finalize hash mismatch fails
finalize valid bundle creates snapshot and marks import active
secret_scan_blocked security report fails finalize
```

- [x] **Step 2: Write failing plugin upload tests**

Cover:

```text
plugin reads manifest
plugin requests Genesis start
plugin uploads chunks with chunk hash headers
plugin calls finalize
retry duplicate chunk is treated as success
```

- [x] **Step 3: Run failing tests**

Run:

```bash
cd backend && php artisan test tests/Feature/GenesisUploadTest.php
cd ../plugin && python3 -m pytest tests/test_artifact_upload.py -q
```

Expected: FAIL.

- [x] **Step 4: Implement artifact storage**

Storage layout:

```text
storage/app/devboard/artifacts/genesis/<import_id>/<artifact_id>/chunks/<chunk_index>
storage/app/devboard/artifacts/genesis/<import_id>/<artifact_id>/artifact
```

Use generated server paths only. Do not use client filenames as storage paths.

- [x] **Step 5: Implement Genesis finalize**

Finalize must validate:

```text
manifest schema
required artifact presence
chunk count
chunk hashes
full artifact hashes
security-report safe_to_upload
repository policy
```

On success:

```text
artifact status validated/imported
snapshot created
genesis_import status active
run event appended
audit log written
```

- [x] **Step 6: Implement plugin upload/finalize command**

Command:

```text
devboard artifacts upload --genesis
```

It reads `.devboard/state.json` and the latest Genesis bundle path.

- [x] **Step 7: Verify upload tests pass**

Run:

```bash
cd backend && php artisan test tests/Feature/GenesisUploadTest.php
cd ../plugin && python3 -m pytest tests/test_artifact_upload.py -q
```

Expected: PASS.

- [x] **Step 8: Commit**

Run:

```bash
git add backend plugin
git commit -m "feat: add artifact upload and genesis finalize"
```

## Task 9: Neo4j Import

**Files:**

- Create: `backend/app/Services/Neo4jClientFactory.php`
- Create: `backend/app/Services/GenesisGraphImportService.php`
- Create: `backend/app/Jobs/ImportGenesisGraphToNeo4j.php`
- Create: `backend/tests/Feature/GenesisGraphImportTest.php`
- Create: `backend/tests/Unit/GenesisGraphCypherTest.php`
- Modify: `backend/config/services.php`

- [x] **Step 1: Write failing graph import tests**

Cover:

```text
valid graph-snapshot artifact creates DevBoardSnapshot node command payload
file and function nodes include snapshot_id
relations include run_id and repository_id
failed Neo4j import leaves genesis_import failed and snapshot not active
```

Use a fake Neo4j client in tests. Do not require a live Neo4j service for unit tests.

- [x] **Step 2: Verify graph tests fail**

Run:

```bash
cd backend
php artisan test tests/Feature/GenesisGraphImportTest.php tests/Unit/GenesisGraphCypherTest.php
```

Expected: FAIL.

- [x] **Step 3: Implement graph import service**

Service must:

```text
read validated graph-snapshot artifact
create DevBoardSnapshot node
upsert code intelligence nodes
upsert relationships
record graph import run event
mark graph status imported only after all writes succeed
```

- [x] **Step 4: Verify graph tests pass**

Run:

```bash
cd backend
php artisan test tests/Feature/GenesisGraphImportTest.php tests/Unit/GenesisGraphCypherTest.php
```

Expected: PASS.

- [x] **Step 5: Commit**

Run:

```bash
git add backend/app/Services backend/app/Jobs backend/config backend/tests
git commit -m "feat: import genesis graph into neo4j"
```

## Task 10: Genesis Wiki Revisions

**Files:**

- Create: `backend/app/Services/WikiRevisionService.php`
- Create: `backend/app/Http/Controllers/Plugin/WikiRevisionController.php`
- Create: `backend/tests/Feature/WikiRevisionTest.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/app/Services/GenesisFinalizeService.php`

- [x] **Step 1: Write failing wiki tests**

Cover:

```text
plugin can create wiki revision with source_status verified_from_code and evidence
verified_from_code without evidence is rejected
needs_verification without evidence is accepted
Genesis finalize writes wiki-pages artifact into revisions
older revision remains accessible
current_revision_id updates
```

- [x] **Step 2: Verify wiki tests fail**

Run:

```bash
cd backend
php artisan test tests/Feature/WikiRevisionTest.php
```

Expected: FAIL.

- [x] **Step 3: Implement wiki revision service**

Rules:

```text
append-only revisions
evidence required for verified_from_code
source_status visible on page
audit log wiki.updated
plugin writes allowed with wiki.write scope
```

- [x] **Step 4: Wire Genesis finalize to wiki artifact**

When `wiki-pages.json` exists and Genesis validation succeeds, create or update pages by slug.

- [x] **Step 5: Verify wiki tests pass**

Run:

```bash
cd backend
php artisan test tests/Feature/WikiRevisionTest.php
```

Expected: PASS.

- [x] **Step 6: Commit**

Run:

```bash
git add backend/app backend/routes backend/tests/Feature/WikiRevisionTest.php
git commit -m "feat: write genesis wiki revisions"
```

## Task 11: Inertia Dashboard Slice

**Files:**

- Create: `backend/resources/js/Layouts/AppLayout.jsx`
- Create: `backend/resources/js/Pages/Kanban/Index.jsx`
- Create: `backend/resources/js/Pages/Projects/Show.jsx`
- Create: `backend/resources/js/Pages/Runs/Show.jsx`
- Create: `backend/resources/js/Pages/Admin/Tokens.jsx`
- Create: `backend/app/Http/Controllers/Dashboard/KanbanController.php`
- Create: `backend/app/Http/Controllers/Dashboard/ProjectShowController.php`
- Create: `backend/app/Http/Controllers/Dashboard/RunShowController.php`
- Create: `backend/app/Http/Controllers/Dashboard/PluginTokenController.php`
- Create: `backend/tests/Feature/DashboardSliceTest.php`
- Modify: `backend/routes/web.php`

- [x] **Step 1: Write failing dashboard feature tests**

Cover:

```text
authenticated PM sees Kanban home
project detail shows repositories and Genesis status
run detail shows artifacts, risk, safety, source labels
PM cannot access Admin token page
Admin can create token and full token is returned once
```

- [x] **Step 2: Verify dashboard tests fail**

Run:

```bash
cd backend
php artisan test tests/Feature/DashboardSliceTest.php
```

Expected: FAIL.

- [x] **Step 3: Implement dashboard controllers and routes**

Routes:

```text
GET /kanban
GET /projects/{project}
GET /runs/{run}
GET /admin/plugin-tokens
POST /admin/plugin-tokens
DELETE /admin/plugin-tokens/{token}
```

- [x] **Step 4: Implement React pages**

Pages must match `09_DASHBOARD_WIREFRAMES.md` at low fidelity:

```text
Kanban PM home
Project detail
Run detail
Admin token panel
Wiki source status banner where wiki data appears
```

Use dense operational UI. Do not build a landing page.

- [x] **Step 5: Verify dashboard tests pass**

Run:

```bash
cd backend
php artisan test tests/Feature/DashboardSliceTest.php
npm run build
```

Expected: PASS and frontend build succeeds.

- [x] **Step 6: Commit**

Run:

```bash
git add backend/app/Http/Controllers/Dashboard backend/resources/js backend/routes/web.php backend/tests/Feature/DashboardSliceTest.php
git commit -m "feat: add devboard dashboard slice"
```

## Task 12: End-to-End Onboarding Genesis Test

**Files:**

- Create: `tests/e2e/test_onboarding_genesis.py`
- Create: `scripts/devboard_e2e_bootstrap.sh`
- Modify: `README.md`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

- [x] **Step 1: Write failing E2E test**

The E2E test should:

```text
start backend test server or use Laravel test process
seed Admin/project/repository/token
run plugin auth check
run plugin device registration
run plugin repo link against fixture repo
run plugin genesis bundle
upload Genesis artifacts
assert backend marks Genesis active
assert graph import status is imported or fake-imported in test mode
assert dashboard project endpoint includes repository initialized status
```

- [x] **Step 2: Verify E2E test fails before wiring**

Run:

```bash
python3 -m pytest tests/e2e/test_onboarding_genesis.py -q
```

Expected: FAIL until bootstrap wiring is complete.

- [x] **Step 3: Implement E2E bootstrap script**

`scripts/devboard_e2e_bootstrap.sh` must:

```text
install backend dependencies when missing
install plugin/analyzer editable packages in local virtualenv
prepare test database
run migrations and seeders
run E2E command sequence
```

- [x] **Step 4: Add README instructions**

Document:

```text
backend setup
plugin setup
PostgreSQL requirement
Neo4j requirement
how to run the onboarding Genesis E2E test
```

- [x] **Step 5: Verify all tests**

Run:

```bash
cd backend && php artisan test
cd ../plugin && python3 -m pytest -q
cd ../analyzer && python3 -m pytest -q
cd .. && python3 -m pytest tests/e2e/test_onboarding_genesis.py -q
```

Expected: all tests pass in a prepared local environment. If Neo4j is unavailable, run the E2E in fake graph import mode and record live Neo4j verification as a required manual acceptance check.

- [x] **Step 6: Update logbook**

Append a logbook entry with:

```text
request
context read
work performed
verification commands and results
files changed
residual risks
```

- [x] **Step 7: Commit**

Run:

```bash
git add tests/e2e scripts README.md ai-sandbox/logbooks/LOGBOOK_PROJECT.md
git commit -m "test: add onboarding genesis e2e coverage"
```

## Final Verification

Before claiming implementation complete, run:

```bash
git status --short --untracked-files=all
cd backend && php artisan test && npm run build
cd ../plugin && python3 -m pytest -q
cd ../analyzer && python3 -m pytest -q
cd .. && python3 -m pytest tests/e2e/test_onboarding_genesis.py -q
git diff --check
```

Expected:

```text
backend tests pass
frontend build passes
plugin tests pass
analyzer tests pass
E2E test passes or explicitly documents missing live Neo4j dependency
git diff --check exits 0
```

## Acceptance Checklist

- [x] Admin can create a plugin token and see the full token once.
- [x] Plugin stores token outside the repository.
- [x] Plugin registers a device.
- [x] Plugin links a local repository.
- [x] `.devboard/state.json` exists without secrets.
- [x] Plugin starts a Genesis run.
- [x] Analyzer produces required Genesis artifacts.
- [x] Secret hard-block prevents unsafe finalize.
- [x] Artifact upload uses manifest, chunks, finalize, and hash validation.
- [x] Backend creates active snapshot after successful finalize.
- [x] Neo4j graph import runs from validated artifact data.
- [x] Wiki revisions are written with source status and evidence.
- [x] Kanban home, project detail, and run detail expose the imported state.
- [x] PM cannot create plugin tokens or access code-write controls.
- [x] Local plugin snapshot is labeled as local state, not remote Git truth.
