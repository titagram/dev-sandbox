# DevBoard Git Workspace Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the first live-testable Git/workspace state refresh slice so a linked local workspace can push current branch, HEAD, dirty state, upstream, and ahead/behind metadata into DevBoard without the backend cloning or reading the target repository.

**Architecture:** Keep DevBoard Server as the control plane. The browser UI reads project/workspace state only through `/api/dashboard/...`; the local Node agent probes Git near the target repository and writes workspace state through `/api/plugin/v1/...`. Remote Git data remains agent-reported local state, not independently verified remote truth.

**Tech Stack:** Laravel 13, PostgreSQL, Pest/PHPUnit, Docker Compose, dependency-free Node ESM agent, React/emergent dashboard, Jest/CRACO frontend tests.

---

## Current State

- `verified_from_code`: `agent/` exists and provides `auth-check`, `register-device`, and `link-workspace`.
- `verified_from_code`: `agent/src/probe.js` currently reports `local_root_hash`, `display_path`, `current_branch`, `last_head_sha`, and `dirty_status`.
- `verified_from_code`: backend `local_workspaces` currently stores `display_path`, `current_branch`, `last_head_sha`, `dirty_status`, `last_snapshot_id`, and `last_seen_at`.
- `verified_from_code`: `RegisterLocalWorkspaceController` updates an existing workspace row when the same `repository_id`, `device_id`, and `local_root_hash` are posted again.
- `developer_provided`: DevBoard backend must not contain or clone target source repositories.
- `developer_provided`: browser UI must use `/api/dashboard/...`; `/api/plugin/v1` is reserved for CLI/MCP/local agent.

## Scope

Implement this first:

- Add local Git metadata fields to `local_workspaces`.
- Extend the existing plugin local-workspace registration/update payload to accept sanitized Git metadata.
- Extend dashboard project detail repository payloads to expose the metadata.
- Extend the Node agent probe to compute sanitized remote/upstream/ahead-behind values without network fetch.
- Add `devboard-agent refresh-workspace` as an explicit manual refresh command that reuses the local-workspace link/update path.
- Update Project Detail UI to make Git state visible and clearly label it as local agent state.

Do not implement in this slice:

- Agent daemon/watch loop.
- Git hooks.
- Server-side clone, fetch, or source repository access.
- Server verification of pushed remote state.
- Genesis/Delta execution from the Node agent.
- Job leases.
- Graph UI improvements.

## File Map

Backend:

- Create migration: `backend/database/migrations/2026_06_25_000001_add_git_state_to_local_workspaces.php`
- Create test: `backend/tests/Feature/PluginGitWorkspaceStateTest.php`
- Modify: `backend/app/Http/Controllers/Plugin/RegisterLocalWorkspaceController.php`
- Modify: `backend/app/Dashboard/DashboardApiReader.php`

Agent:

- Modify: `agent/src/probe.js`
- Modify: `agent/src/client.js`
- Modify: `agent/bin/devboard-agent.js`
- Modify: `agent/test/probe.test.js`
- Optional create: `agent/test/client.test.js`

Frontend:

- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/types/devboard.ts`
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/pages/ProjectDetailPage.tsx`
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/mockData.ts`
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/mockApi.ts`
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/httpApi.test.ts`

Documentation/logbook:

- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`
- Optional modify: `README.md`

## Data Contract

Add these nullable columns to `local_workspaces`:

```text
remote_name string nullable
remote_url_host string nullable
remote_url_hash string nullable
upstream_branch string nullable
ahead_count unsigned integer nullable
behind_count unsigned integer nullable
git_state_observed_at timestamp nullable
```

Do not store raw remote URLs. Raw remote URLs can contain credentials or private host/path information. The local agent may send a host and a hash only.

Plugin payload extension:

```json
{
  "protocol_version": "v1",
  "local_root_hash": "sha256:...",
  "display_path": "/Users/gabriele/Dev/sinervis/carnovali",
  "current_branch": "main",
  "last_head_sha": "abc123",
  "dirty_status": "clean",
  "remote_name": "origin",
  "remote_url_host": "github.com",
  "remote_url_hash": "sha256:...",
  "upstream_branch": "origin/main",
  "ahead_count": 1,
  "behind_count": 0,
  "git_state_observed_at": "2026-06-25T16:30:00Z"
}
```

Dashboard `local_workspace` extension:

```json
{
  "status": "linked",
  "id": "01...",
  "device_id": "01...",
  "display_path": "/Users/gabriele/Dev/sinervis/carnovali",
  "current_branch": "main",
  "last_head_sha": "abc123",
  "dirty_status": "clean",
  "last_seen_at": "2026-06-25 16:30:00",
  "remote_name": "origin",
  "remote_url_host": "github.com",
  "remote_url_hash": "sha256:...",
  "upstream_branch": "origin/main",
  "ahead_count": 1,
  "behind_count": 0,
  "git_state_observed_at": "2026-06-25T16:30:00Z",
  "source_truth": "local_agent_reported"
}
```

## Task 1: Backend Git Metadata Persistence

**Files:**
- Create: `backend/database/migrations/2026_06_25_000001_add_git_state_to_local_workspaces.php`
- Create: `backend/tests/Feature/PluginGitWorkspaceStateTest.php`
- Modify: `backend/app/Http/Controllers/Plugin/RegisterLocalWorkspaceController.php`
- Modify: `backend/app/Dashboard/DashboardApiReader.php`

- [ ] **Step 1: Write the failing backend test**

Create `backend/tests/Feature/PluginGitWorkspaceStateTest.php` using the existing helper style from `ProjectKickstartDashboardApiTest.php`. The test must:

- seed DevBoard;
- create Admin and PM users;
- create a project through `/api/dashboard/projects`;
- declare a repository through `/api/dashboard/projects/{project}/repositories`;
- create a plugin token with a registered device;
- post to existing `/api/plugin/v1/repositories/{repository}/local-workspaces` with the new Git metadata;
- assert that the row stores sanitized metadata;
- assert that `/api/dashboard/projects/{project}` exposes the metadata under `repositories.0.local_workspace`;
- assert that no raw `remote_url` exists in the response.

Use these key assertions:

```php
$this->postJson("/api/plugin/v1/repositories/{$repositoryId}/local-workspaces", [
    'protocol_version' => 'v1',
    'local_root_hash' => 'sha256:git-state-root',
    'display_path' => '/Users/gabriele/Dev/sinervis/carnovali',
    'current_branch' => 'feature/git-sync',
    'last_head_sha' => 'abc123',
    'dirty_status' => 'dirty',
    'remote_name' => 'origin',
    'remote_url_host' => 'github.com',
    'remote_url_hash' => 'sha256:'.hash('sha256', 'https://token@example.test/org/repo.git'),
    'upstream_branch' => 'origin/feature/git-sync',
    'ahead_count' => 2,
    'behind_count' => 1,
    'git_state_observed_at' => '2026-06-25T16:30:00Z',
], kickstartPluginHeaders($token))->assertOk();

$this->actingAs($pm)
    ->getJson("/api/dashboard/projects/{$projectId}")
    ->assertOk()
    ->assertJsonPath('repositories.0.local_workspace.current_branch', 'feature/git-sync')
    ->assertJsonPath('repositories.0.local_workspace.dirty_status', 'dirty')
    ->assertJsonPath('repositories.0.local_workspace.remote_name', 'origin')
    ->assertJsonPath('repositories.0.local_workspace.remote_url_host', 'github.com')
    ->assertJsonPath('repositories.0.local_workspace.upstream_branch', 'origin/feature/git-sync')
    ->assertJsonPath('repositories.0.local_workspace.ahead_count', 2)
    ->assertJsonPath('repositories.0.local_workspace.behind_count', 1)
    ->assertJsonPath('repositories.0.local_workspace.source_truth', 'local_agent_reported')
    ->assertJsonMissingPath('repositories.0.local_workspace.remote_url');
```

- [ ] **Step 2: Run the backend test to verify it fails**

Run:

```bash
docker exec devboard-app-1 sh -lc 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test tests/Feature/PluginGitWorkspaceStateTest.php --display-warnings'
```

Expected: failure because the new columns/fields are missing.

- [ ] **Step 3: Add migration**

Create migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_workspaces', function (Blueprint $table): void {
            $table->string('remote_name')->nullable()->after('dirty_status');
            $table->string('remote_url_host')->nullable()->after('remote_name');
            $table->string('remote_url_hash')->nullable()->after('remote_url_host');
            $table->string('upstream_branch')->nullable()->after('remote_url_hash');
            $table->unsignedInteger('ahead_count')->nullable()->after('upstream_branch');
            $table->unsignedInteger('behind_count')->nullable()->after('ahead_count');
            $table->timestamp('git_state_observed_at')->nullable()->after('behind_count');
        });
    }

    public function down(): void
    {
        Schema::table('local_workspaces', function (Blueprint $table): void {
            $table->dropColumn([
                'remote_name',
                'remote_url_host',
                'remote_url_hash',
                'upstream_branch',
                'ahead_count',
                'behind_count',
                'git_state_observed_at',
            ]);
        });
    }
};
```

- [ ] **Step 4: Extend plugin validation and persistence**

In `RegisterLocalWorkspaceController`, extend validation:

```php
'remote_name' => ['nullable', 'string', 'max:255'],
'remote_url_host' => ['nullable', 'string', 'max:255'],
'remote_url_hash' => ['nullable', 'string', 'max:255', 'regex:/^sha256:[a-f0-9]{64}$/'],
'upstream_branch' => ['nullable', 'string', 'max:255'],
'ahead_count' => ['nullable', 'integer', 'min:0'],
'behind_count' => ['nullable', 'integer', 'min:0'],
'git_state_observed_at' => ['nullable', 'date'],
```

Add the same fields to both update and insert arrays. Never accept or store a `remote_url` field.

- [ ] **Step 5: Extend dashboard reader local workspace payload**

In `DashboardApiReader::localWorkspaceState()`, include null defaults for missing workspaces and real values for linked workspaces:

```php
'remote_name' => null,
'remote_url_host' => null,
'remote_url_hash' => null,
'upstream_branch' => null,
'ahead_count' => null,
'behind_count' => null,
'git_state_observed_at' => null,
'source_truth' => 'local_agent_reported',
```

For linked workspaces cast counts with nullable integers:

```php
'ahead_count' => $workspace->ahead_count === null ? null : (int) $workspace->ahead_count,
'behind_count' => $workspace->behind_count === null ? null : (int) $workspace->behind_count,
```

- [ ] **Step 6: Run backend verification**

Run:

```bash
docker exec devboard-app-1 sh -lc 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test tests/Feature/PluginGitWorkspaceStateTest.php tests/Feature/Dashboard/ProjectKickstartDashboardApiTest.php --display-warnings'
docker exec devboard-app-1 php -l app/Http/Controllers/Plugin/RegisterLocalWorkspaceController.php
docker exec devboard-app-1 php -l app/Dashboard/DashboardApiReader.php
```

Expected: tests pass; PHP lint reports no syntax errors.

## Task 2: Agent Git Probe Remote Metadata

**Files:**
- Modify: `agent/src/probe.js`
- Modify: `agent/test/probe.test.js`

- [ ] **Step 1: Add failing probe tests**

Extend `agent/test/probe.test.js` with tests for remote host/hash and ahead/behind counts. Use a local bare remote; do not require network.

Key expected assertions:

```js
assert.equal(result.remote_name, "origin");
assert.equal(result.remote_url_host, "local");
assert.equal(result.remote_url_hash.startsWith("sha256:"), true);
assert.equal(result.upstream_branch.endsWith("/main") || result.upstream_branch.endsWith("/master"), true);
assert.equal(result.ahead_count, 1);
assert.equal(result.behind_count, 0);
assert.match(result.git_state_observed_at, /^\d{4}-\d{2}-\d{2}T/);
```

Also add a URL sanitization test:

```js
const sanitized = sanitizeRemoteUrl("https://token@example.test/org/repo.git");
assert.equal(sanitized.host, "example.test");
assert.equal(sanitized.hash.startsWith("sha256:"), true);
assert.equal(JSON.stringify(sanitized).includes("token"), false);
```

- [ ] **Step 2: Run tests to verify failure**

Run:

```bash
cd agent && npm test
```

Expected: failure because `sanitizeRemoteUrl`, remote metadata, and ahead/behind values do not exist.

- [ ] **Step 3: Implement probe helpers**

In `agent/src/probe.js`, add exports:

```js
export function sanitizeRemoteUrl(remoteUrl) {
  if (!remoteUrl) {
    return { host: null, hash: null };
  }

  let host = "local";
  try {
    const parsed = new URL(remoteUrl);
    host = parsed.hostname || "local";
  } catch {
    const scpLike = remoteUrl.match(/^[^@]+@([^:]+):/);
    host = scpLike?.[1] || "local";
  }

  return {
    host,
    hash: `sha256:${createHash("sha256").update(remoteUrl).digest("hex")}`
  };
}

function aheadBehind(root) {
  const value = tryGit(root, ["rev-list", "--left-right", "--count", "HEAD...@{u}"]);
  if (!value) {
    return { ahead_count: null, behind_count: null };
  }

  const [ahead, behind] = value.split(/\s+/).map((part) => Number.parseInt(part, 10));
  return {
    ahead_count: Number.isFinite(ahead) ? ahead : null,
    behind_count: Number.isFinite(behind) ? behind : null
  };
}
```

Then extend `probeGitWorkspace()`:

```js
const remoteName = tryGit(resolved, ["remote"])?.split(/\s+/).filter(Boolean)[0] || null;
const remoteUrl = remoteName ? tryGit(resolved, ["remote", "get-url", remoteName]) : null;
const sanitizedRemote = sanitizeRemoteUrl(remoteUrl);
const upstreamBranch = tryGit(resolved, ["rev-parse", "--abbrev-ref", "--symbolic-full-name", "@{u}"]);
const counts = aheadBehind(resolved);

return {
  local_root_hash: `sha256:${localRootHash}`,
  display_path: resolved,
  current_branch: branch || "HEAD",
  last_head_sha: headSha || null,
  dirty_status: status.length === 0 ? "clean" : "dirty",
  remote_name: remoteName,
  remote_url_host: sanitizedRemote.host,
  remote_url_hash: sanitizedRemote.hash,
  upstream_branch: upstreamBranch,
  ...counts,
  git_state_observed_at: new Date().toISOString()
};
```

- [ ] **Step 4: Run agent verification**

Run:

```bash
cd agent && npm test
node --check agent/src/probe.js
```

Expected: all agent tests pass; syntax check exits 0.

## Task 3: Agent Manual Refresh Command

**Files:**
- Modify: `agent/src/client.js`
- Modify: `agent/bin/devboard-agent.js`
- Optional create: `agent/test/client.test.js`

- [ ] **Step 1: Add or update tests for payload forwarding**

If creating `agent/test/client.test.js`, mock `global.fetch` and assert that `linkWorkspace()` forwards the new Git metadata to `/api/plugin/v1/repositories/{repository}/local-workspaces` with `X-DevBoard-Device-Id`.

Minimal expectation:

```js
assert.equal(calls[0].url, "https://devboard.test/api/plugin/v1/repositories/repo-1/local-workspaces");
assert.equal(calls[0].options.headers["X-DevBoard-Device-Id"], "device-1");
assert.equal(JSON.parse(calls[0].options.body).remote_url_host, "github.com");
assert.equal(JSON.parse(calls[0].options.body).ahead_count, 1);
```

- [ ] **Step 2: Add `refresh-workspace` command**

In `agent/bin/devboard-agent.js`, import no new backend path. Reuse `probeGitWorkspace()` and `linkWorkspace()`:

```js
if (command === "refresh-workspace") {
  const workspace = probeGitWorkspace(requireOption(options, "path"));

  return linkWorkspace({
    server: requireOption(options, "server"),
    token: requireOption(options, "token"),
    deviceId: requireOption(options, "device-id"),
    repositoryId: requireOption(options, "repository-id"),
    workspace
  });
}
```

Update usage:

```text
devboard-agent refresh-workspace --server URL --token TOKEN --device-id ID --repository-id ID --path PATH
```

- [ ] **Step 3: Run agent verification**

Run:

```bash
cd agent && npm test
node --check agent/bin/devboard-agent.js
node --check agent/src/client.js
```

Expected: tests pass; syntax checks exit 0.

## Task 4: Frontend Git State Visibility

**Files:**
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/types/devboard.ts`
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/pages/ProjectDetailPage.tsx`
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/mockData.ts`
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/mockApi.ts`
- Modify: `/home/ubuntu/emergent_devboard_frontend/frontend/src/api/httpApi.test.ts`

- [ ] **Step 1: Extend frontend types**

Add optional fields to `LocalWorkspace`:

```ts
remote_name?: string | null;
remote_url_host?: string | null;
remote_url_hash?: string | null;
upstream_branch?: string | null;
ahead_count?: number | null;
behind_count?: number | null;
git_state_observed_at?: string | null;
source_truth?: "local_agent_reported" | string | null;
```

- [ ] **Step 2: Extend mock data**

In mock repository workspace data, include:

```ts
remote_name: "origin",
remote_url_host: "github.com",
remote_url_hash: "sha256:mock",
upstream_branch: "origin/main",
ahead_count: 0,
behind_count: 0,
git_state_observed_at: iso(5),
source_truth: "local_agent_reported",
```

- [ ] **Step 3: Update Project Detail UI**

In `WorkspaceSummary`, show:

- branch and dirty state;
- upstream branch;
- ahead/behind counts;
- observed time;
- remote host;
- a short "local agent reported" label.

Use compact copy. Do not add visible instructional prose about `/api/plugin/v1`; the UI can show status, not implementation details.

Suggested JSX fragment inside the existing linked-workspace detail block:

```tsx
<div className="flex flex-wrap items-center gap-1.5">
  <span>{workspace.current_branch || "-"}</span>
  <span className="text-border">/</span>
  <span>{workspace.dirty_status || "unknown"}</span>
  {workspace.upstream_branch && (
    <>
      <span className="text-border">/</span>
      <span>{workspace.upstream_branch}</span>
    </>
  )}
</div>
<div className="flex flex-wrap items-center gap-1.5">
  <span>{workspace.remote_url_host || "local"}</span>
  <span className="text-border">/</span>
  <span>ahead {workspace.ahead_count ?? "-"}</span>
  <span>behind {workspace.behind_count ?? "-"}</span>
  <span className="text-border">/</span>
  <span>{relativeTime(workspace.git_state_observed_at ?? workspace.last_seen_at ?? null)}</span>
</div>
```

- [ ] **Step 4: Add frontend adapter/mock assertions**

No new browser endpoint should be needed. Add one assertion to the project-detail mock/adapter tests ensuring the `LocalWorkspace` shape accepts Git metadata and no browser call contains `/api/plugin/v1`.

Run:

```bash
cd /home/ubuntu/emergent_devboard_frontend/frontend && npm test -- --runTestsByPath src/api/httpApi.test.ts --watchAll=false
cd /home/ubuntu/emergent_devboard_frontend/frontend && npm run build
```

Expected: Jest and build pass.

## Task 5: Integrated Verification And Live Smoke

**Files:**
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`
- Optional modify: `README.md`

- [ ] **Step 1: Run backend focused verification**

Run:

```bash
docker exec devboard-app-1 sh -lc 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test tests/Feature/PluginGitWorkspaceStateTest.php tests/Feature/Dashboard/ProjectKickstartDashboardApiTest.php tests/Feature/Dashboard/MultiprojectDashboardApiTest.php --display-warnings'
```

Expected: all tests pass.

- [ ] **Step 2: Run agent verification**

Run:

```bash
cd agent && npm test
node --check agent/bin/devboard-agent.js
node --check agent/src/client.js
node --check agent/src/probe.js
```

Expected: all tests pass and syntax checks exit 0.

- [ ] **Step 3: Run frontend verification**

Run:

```bash
cd /home/ubuntu/emergent_devboard_frontend/frontend && npm test -- --runTestsByPath src/api/httpApi.test.ts --watchAll=false
cd /home/ubuntu/emergent_devboard_frontend/frontend && npm run build
cd /home/ubuntu/emergent_devboard_frontend/frontend && rg -n "/api/plugin/v1" src
```

Expected: tests/build pass. The `rg` scan must find no operational browser calls to `/api/plugin/v1`; existing guardrail comments/tests are acceptable only if they do not call the plugin API.

- [ ] **Step 4: Run diff checks**

Run:

```bash
cd /home/ubuntu/dev-sandbox && git diff --check
cd /home/ubuntu/emergent_devboard_frontend/frontend && git diff --check
```

Expected: both commands exit 0.

- [ ] **Step 5: Optional public deploy smoke**

If deploying to the temporary Traefik route, use both compose files and the required app key:

```bash
DEVBOARD_APP_KEY='base64:6qaz/zOO/vNFxRaNbToyUV9xjlXTM5WMGOCp01kIudQ=' docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.traefik.yaml up -d --build app worker frontend
```

Then smoke:

```bash
curl -m 8 -sS -o /tmp/devboard_home_body -D /tmp/devboard_home_headers -w 'HOME_HTTP=%{http_code} HOME_TOTAL=%{time_total}\n' https://home-sweet-home.cloud/
curl -m 8 -sS -o /tmp/devboard_me_body -D /tmp/devboard_me_headers -w 'ME_HTTP=%{http_code} ME_TOTAL=%{time_total}\n' https://home-sweet-home.cloud/api/dashboard/me
```

Expected: home returns `200`; unauthenticated `/api/dashboard/me` returns `401` quickly.

- [ ] **Step 6: Live agent refresh smoke**

With a real token/device/repository on the developer machine, run:

```bash
node /path/to/dev-sandbox/agent/bin/devboard-agent.js refresh-workspace \
  --server https://home-sweet-home.cloud \
  --token 'REDACTED' \
  --device-id 'DEVICE_ID' \
  --repository-id 'REPOSITORY_ID' \
  --path '/Users/gabriele/Dev/sinervis/carnovali'
```

Expected JSON includes `status: "linked"` and the dashboard project detail shows branch, dirty state, upstream, ahead/behind, and observed time.

- [ ] **Step 7: Update logbook**

Update `ai-sandbox/logbooks/LOGBOOK_PROJECT.md` with:

- context read;
- files changed;
- tests/build commands and results;
- live smoke results if run;
- residual risks.

Residual risks to record:

- remote/ahead/behind are local agent reported, not server-verified remote truth;
- no daemon/watch loop yet;
- no Git hooks yet;
- no Genesis/Delta launch from Node agent yet;
- no job leases yet.
