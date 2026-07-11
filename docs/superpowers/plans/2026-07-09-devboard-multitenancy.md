# DevBoard Multi-Tenancy Migration Plan

**Status:** Planning (separate program — not part of P0/P1/P2 operational hardening)
**Created:** 2026-07-09
**Prerequisite:** Completion of current P0/P1/P2 operational hardening (Waves 0–4)

## 1. Current State

### 1.1 Tenant Boundary: Project

Today, `project` is the implicit tenant boundary. Every data row that belongs to a specific tenant uses `project_id` as its foreign key. There is no `organization_id`, `tenant_id`, or `workspace_id` column anywhere in the database.

### 1.2 Table Inventory

**Tables with `project_id` (27 tables):**

| Table | Project FK | Notes |
|-------|-----------|-------|
| `repositories` | `project_id` → projects | Per-project repos |
| `local_workspaces` | — (via `repository_id`) | Indirect: repo → project |
| `kanban_boards` | `project_id` → projects | Per-project boards |
| `kanban_columns` | — (via `board_id`) | Indirect |
| `tasks` | `project_id` → projects | + `owner_user_id`, `created_by_user_id` |
| `runs` | `project_id` → projects | + `started_by_user_id` |
| `artifacts` | `project_id` → projects | |
| `snapshots` | `project_id` → projects | |
| `genesis_imports` | `project_id` → projects | |
| `delta_syncs` | `project_id` → projects | |
| `wiki_pages` | `project_id` → projects | |
| `task_attachments` | `project_id` → projects | |
| `hades_agents` | `project_id` → projects | |
| `hades_agent_tokens` | `project_id` → projects | |
| `hades_workspace_bindings` | `project_id` → projects | + `hades_agent_id` |
| `hades_agent_jobs` | `project_id` → projects | |
| `hades_source_slices` | `project_id` → projects | |
| `hades_bug_evidence` | `project_id` → projects | |
| `hades_source_slice_candidates` | `project_id` → projects | |
| `hades_diagnosis_reports` | `project_id` → projects | |
| `hades_evidence_packs` | `project_id` → projects | |
| `hades_search_documents` | `project_id` → projects | |
| `assistant_runs` | `project_id` → projects | + `triggered_by_user_id` |
| `assistant_suggestions` | `project_id` → projects | + `created_by_user_id`, `resolved_by_user_id` |
| `agent_work_items` | `project_id` → projects | + `archived_by_user_id` |
| `agent_chat_threads` | `project_id` → projects | + `created_by_user_id`, `archived_by_user_id` |
| `memory_import_batches` | `project_id` → projects | + `requested_by_user_id`, `cancelled_by_user_id` |

**Tables with `user_id` (3 tables, core identity):**

| Table | User FK | Notes |
|-------|---------|-------|
| `role_user` | `user_id` → users | Role assignments |
| `devices` | `user_id` → users | + `fingerprint_hash`, `signing_secret_hash` |
| `api_tokens` | `user_id` → users | + `device_id`, `scopes`, `token_hash` |

**Pivot tables:**
| Table | Notes |
|-------|-------|
| `ai_agent_project_visibility` | Maps agent profiles to projects |

### 1.3 Users and Roles (Current)

- Users are **global** — a user exists once and can belong to any project via `role_user`.
- Roles: `admin` (global), `project_admin`, `project_viewer` (currently per-project through authorization logic in `ChecksDashboardRoles` and `ProjectPolicy`).
- Plugin tokens (`api_tokens`) are bound to a `user_id` and optionally a `device_id`, not to a project.

### 1.4 Plugin Token Flow (Current)

```
User → api_tokens (token_hash, scopes, device_id)
Device → devices (user_id, fingerprint_hash, signing_secret_hash)
Plugin client → HTTP Bearer token + HMAC signature headers
Middleware → AuthenticatePluginToken resolves user, device, scopes
```

Token scopes include plugin operations (`plugin:genesis`, `plugin:delta`, `plugin:graph`). No project or organization scoping on tokens currently.

### 1.5 Neo4j Graph (Current)

- All nodes use `:CodeNode` as base label + specific semantic labels (`:Function`, `:File`, `:Class`, `:Module`).
- Snapshot nodes use `:DevBoardSnapshot` with properties: `snapshot_id`, `repository_id`, `run_id`.
- Relationships use `:CALLS`, `:DECLARES`, `:IMPORTS`, fallback `:RELATED`.
- Purge scope: `MATCH (n:CodeNode {snapshot_id: ...}) DETACH DELETE n` — scoped by snapshot.
- No organization or project label on nodes directly. Project scoping is derived through snapshot → run → project.

### 1.6 What Currently Does NOT Exist

- No `organizations` table
- No `organization_id` column anywhere
- No `organization_user` pivot or organization membership concept
- No multi-tenancy middleware or global scopes
- No `tenant_id` column or concept
- No Eloquent model global scopes (existing models are `$guarded = []` with no scoping)

## 2. Proposed Organization/Tenant Model

### 2.1 Core Tables to Create

```sql
-- Organizations (the top-level tenant)
CREATE TABLE organizations (
    id UUID PRIMARY KEY,
    name VARCHAR NOT NULL,
    slug VARCHAR NOT NULL UNIQUE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- Organization membership
CREATE TABLE organization_user (
    id BIGSERIAL PRIMARY KEY,
    organization_id UUID NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role VARCHAR NOT NULL DEFAULT 'member',  -- 'owner', 'admin', 'member'
    UNIQUE(organization_id, user_id)
);

-- Add organization_id to projects
ALTER TABLE projects ADD COLUMN organization_id UUID
    REFERENCES organizations(id) ON DELETE CASCADE;
```

### 2.2 Entity Relationships

```
Organization (tenants)
  ├── hasMany: User (via organization_user pivot)
  ├── hasMany: Project (via organization_id FK)
  │     ├── hasMany: Repository
  │     ├── hasMany: Task, Run, Artifact, Snapshot
  │     ├── hasMany: WikiPage
  │     └── hasMany: HadesAgent, HadesWorkspaceBinding, etc.
  └── hasMany: ApiToken (via organization_id, replacing per-user-only scoping)
      └── belongsTo: Device
```

### 2.3 Migration Path

1. Users belong to one or more organizations (many-to-many via `organization_user`).
2. Projects belong to exactly one organization (nullable during migration).
3. All project-scoped data inherits organization through `project_id`.
4. Plugin tokens become organization-scoped: an organization admin creates tokens, not individual users.
5. The admin dashboard shows data filtered by the current organization.

## 3. Migration Safety

### 3.1 Principle: Nullable Then Backfill

Do not add a non-nullable `organization_id` column in a single migration. This would break all existing data.

**Phase approach:**

```sql
-- Step 1: Add nullable column (safe, no default needed)
ALTER TABLE projects ADD COLUMN organization_id UUID;

-- Step 2: Create a default organization for existing data
INSERT INTO organizations (id, name, slug, created_at, updated_at)
VALUES (gen_random_uuid(), 'Default Organization', 'default', NOW(), NOW());

-- Step 3: Backfill existing projects
UPDATE projects SET organization_id = (SELECT id FROM organizations WHERE slug = 'default' LIMIT 1)
WHERE organization_id IS NULL;

-- Step 4: Add existing users to the default organization
INSERT INTO organization_user (organization_id, user_id, role)
SELECT o.id, u.id, 'member'
FROM organizations o, users u
WHERE o.slug = 'default';

-- Step 5: Only AFTER backfill, make the column NOT NULL
ALTER TABLE projects ALTER COLUMN organization_id SET NOT NULL;
```

### 3.2 Downtime

The `ALTER TABLE projects ADD COLUMN organization_id` is instant (just metadata). The subsequent `SET NOT NULL` after backfill is also fast. No table rewrite needed for a UUID column.

### 3.3 Backfill Verification

After backfill, run:
```sql
SELECT COUNT(*) FROM projects WHERE organization_id IS NULL;
-- Expected: 0
SELECT COUNT(*) FROM organization_user;
-- Expected: matches total user count
```

## 4. Query Scoping: Eloquent Global Scopes

### 4.1 Design

Create a `TenantScope` global scope that automatically filters queries by the current organization:

```php
// app/Scopes/TenantScope.php
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($organizationId = Context::organizationId()) {
            $table = $model->getTable();

            // Direct tables: already have organization_id after migration
            if (in_array($table, ['projects', 'api_tokens'])) {
                $builder->where("{$table}.organization_id", $organizationId);
                return;
            }

            // Tables with project_id: join through projects
            if (Schema::hasColumn($table, 'project_id')) {
                $builder->whereHas('project', fn ($q) =>
                    $q->where('organization_id', $organizationId)
                );
            }
        }
    }
}
```

### 4.2 Models to Scope

| Model | Scoping Strategy |
|-------|-----------------|
| `Project` | Direct `organization_id` column |
| `Repository`, `Task`, `Run`, `Artifact`, `WikiPage`, etc. | `whereHas('project', ...)` or subquery |
| `ApiToken` | Direct `organization_id` column |
| `Device` | `whereHas('user.organizations', ...)` |
| `Organization` | No scope (admin sees all) |

### 4.3 Non-Eloquent Query Scoping

Many queries in `DashboardApiReader` use `DB::table()` directly. These need organization filtering added manually or migrated to Eloquent:

```php
// Before
DB::table('repositories')->where('project_id', $projectId)->get();

// After (if using DB facade)
DB::table('repositories')
    ->join('projects', 'projects.id', '=', 'repositories.project_id')
    ->where('projects.organization_id', $orgId)
    ->where('repositories.project_id', $projectId)
    ->get();

// Preferred (if migrated to Eloquent with global scope)
Repository::where('project_id', $projectId)->get();  // automatically scoped
```

### 4.4 Context Resolution

Create a `TenantContext` service to resolve the current organization from the request:

```php
// app/Services/TenantContext.php
class TenantContext
{
    public static function organizationId(): ?string
    {
        // 1. From session/conversation scope
        // 2. From request header (X-Organization-Id for API)
        // 3. From JWT claims (for plugin tokens)
        // 4. From resolved project's organization_id
        return app('context.organization_id');
    }

    public static function forRequest(Request $request): ?string
    {
        // Dashboard: user's default organization
        // Plugin: token's organization_id
        // Hades: derived from hades_agent → project → organization
    }
}
```

## 5. Token Scoping: Per-Organization Plugin Tokens

### 5.1 Current State

`api_tokens` has: `id`, `token_prefix`, `token_hash`, `user_id`, `device_id`, `name`, `scopes`, `expires_at`, `revoked_at`, `last_used_at`.

### 5.2 Proposed Changes

Add `organization_id` to `api_tokens`:

```sql
ALTER TABLE api_tokens ADD COLUMN organization_id UUID
    REFERENCES organizations(id) ON DELETE CASCADE;
```

After backfill, `user_id` becomes optional (tokens may be organization-owned, not user-owned for Hades/plugin use).

### 5.3 Middleware Changes

```php
// AuthenticatePluginToken middleware
// After resolving the token:
$organizationId = $token->organization_id;

// Verify the request is for a project in this organization
if ($projectId = $request->input('project_id')) {
    $projectBelongs = DB::table('projects')
        ->where('id', $projectId)
        ->where('organization_id', $organizationId)
        ->exists();
    if (! $projectBelongs) {
        abort(403, 'Token organization does not match project');
    }
}
```

### 5.4 Plugin Token UI

The admin dashboard plugin token management already supports creating and listing tokens. After migration:
- Token creation requires selecting an organization.
- Token list is filtered by current organization.
- `scopes` field gains `organization_id` context.

## 6. Neo4j Graph Partitioning

### 6.1 Current State

- All nodes share a flat `:CodeNode` label space.
- Purge is by `snapshot_id` property.
- No organization-level isolation.

### 6.2 Proposed Partitioning

**Option A: Organization Label (recommended for small-to-medium scale)**

```cypher
-- Add organization label to all nodes
SET n:Org_{organization_id}

-- Index per organization
CREATE INDEX IF NOT EXISTS FOR (n:Org_{orgId}) ON (n.snapshot_id);

-- Purge scoped to organization
MATCH (n:Org_{orgId}:CodeNode {snapshot_id: $snapshotId}) DETACH DELETE n;

-- Query scoped to organization
MATCH (n:Org_{orgId}:Function) WHERE n.name CONTAINS 'authenticate' RETURN n;
```

**Option B: Organization property + composite index**

```cypher
-- Store organization_id as a property on every node
SET n.organization_id = $orgId;

-- Composite index
CREATE INDEX IF NOT EXISTS FOR (n:CodeNode) ON (n.organization_id, n.snapshot_id);

-- Query always includes organization filter
MATCH (n:CodeNode {organization_id: $orgId}) WHERE n.name CONTAINS 'auth' RETURN n;
```

**Recommendation:** Use **Option A** (organization label). Labels are more performant for filtering than property lookups in Neo4j. The dynamic label approach (`:Org_{id}`) creates natural partitioning and prevents cross-organization leaks in Cypher queries.

### 6.3 Import Changes

`GenesisGraphImportService` must:
1. Accept `organizationId` alongside `projectId`.
2. Resolve the organization from the project before import.
3. Add `:Org_{orgId}` label to every node during MERGE.
4. Store `organization_id` as a property on `:DevBoardSnapshot` nodes.

### 6.4 Query Changes

All `Neo4jClient::run()` calls must include organization scoping:
```php
$client->run(
    'MATCH (n:Org_{organizationId}:CodeNode {snapshot_id: $snapshotId}) ...',
    ['organizationId' => $orgId, 'snapshotId' => $snapshotId]
);
```

### 6.5 Rebuild

`Neo4jRebuildService::purgeSnapshot()` must purge only within the organization:
```cypher
MATCH (n:Org_{orgId}:CodeNode {snapshot_id: $snapshotId}) DETACH DELETE n
MATCH (s:Org_{orgId}:DevBoardSnapshot {snapshot_id: $snapshotId}) DETACH DELETE s
```

## 7. Rollout Plan

### Phase 0: Schema Foundation (migration-only, no code changes)

| Step | Description | Risk |
|------|-------------|------|
| 0.1 | Create `organizations` table | None |
| 0.2 | Create `organization_user` pivot table | None |
| 0.3 | Add nullable `organization_id` to `projects` | None (metadata-only) |
| 0.4 | Add nullable `organization_id` to `api_tokens` | None (metadata-only) |
| 0.5 | Backfill: create "Default Organization", assign all projects/users | P1: verify backfill count |
| 0.6 | Add NOT NULL constraint on `projects.organization_id` | P1: require all projects assigned |

### Phase 1: Organization Models and API

| Step | Description | Risk |
|------|-------------|------|
| 1.1 | Create `Organization` Eloquent model | None |
| 1.2 | Create `OrganizationUser` pivot model | None |
| 1.3 | Add organization relationship to `User`, `Project`, `ApiToken` models | None |
| 1.4 | Dashboard API: list organizations for user | None |
| 1.5 | Dashboard API: create/manage organizations (admin only) | None |

### Phase 2: Query Scoping

| Step | Description | Risk |
|------|-------------|------|
| 2.1 | Create `TenantScope` global scope | None |
| 2.2 | Create `TenantContext` service | None |
| 2.3 | Apply `TenantScope` to `Project` model | P2: test all project queries |
| 2.4 | Apply `TenantScope` to child models (Repository, Task, etc.) | P2: test all child queries |
| 2.5 | Add organization filtering to `DashboardApiReader` DB facade queries | P1: high-risk, many queries |
| 2.6 | Add organization filter to Hades controller queries | P2 |
| 2.7 | Add organization filter to plugin middleware queries | P2 |

### Phase 3: Token Organization Scoping

| Step | Description | Risk |
|------|-------------|------|
| 3.1 | Backfill `api_tokens.organization_id` from user's default org | None |
| 3.2 | Update `PluginTokenService` to scope by organization | P2 |
| 3.3 | Update `AuthenticatePluginToken` middleware | P2 |
| 3.4 | Update dashboard token management (create/list/revoke) | P2 |
| 3.5 | Make `user_id` nullable on `api_tokens` (allow org-owned tokens) | P1: backward compat |

### Phase 4: Neo4j Partitioning

| Step | Description | Risk |
|------|-------------|------|
| 4.1 | Update `GenesisGraphImportService` to add `:Org_{id}` labels | P1 |
| 4.2 | Update `Neo4jRebuildService` purge to use org-scoped labels | P1 |
| 4.3 | Update `QueryProjectGraphTool` to include org filter | P2 |
| 4.4 | Update `GraphTraversalController` to include org filter | P2 |
| 4.5 | Migration: re-label existing nodes with `:Org_{id}` | P1: requires running Cypher on all nodes |

### Phase 5: Admin Dashboard Multi-Org UX

| Step | Description | Risk |
|------|-------------|------|
| 5.1 | Dashboard header: organization switcher component | None |
| 5.2 | Project list filtered by selected organization | None |
| 5.3 | Admin page: organization management (create/list/edit/delete) | None |
| 5.4 | User management: organization membership (add/remove/role) | None |

### Phase 6: Cleanup and Hardening

| Step | Description | Risk |
|------|-------------|------|
| 6.1 | Remove nullable `organization_id` columns, set NOT NULL everywhere | P0: after full verification |
| 6.2 | Add unique constraint on `organizations.slug` | None |
| 6.3 | Add organization_id to audience-specific audit logs | None |
| 6.4 | Performance audit: composite indexes with `organization_id` | P2 |
| 6.5 | Remove "Default Organization" bootstrap from new installations | None |

## 8. Dependencies On Existing P0/P1/P2 Work

### 8.1 Completed (Must Be Stable Before Starting)

| Task | What It Provides | Why It's a Prerequisite |
|------|-----------------|------------------------|
| 2.1: Core Eloquent Models | `Project`, `User`, `ApiToken`, `Device` models with relationships | TenantScope applies to models; need model layer stable first |
| 2.2: Form Request Validation | Clean validation layer | Token creation with `organization_id` validation |
| 2.3: Dashboard Authorization Gates | `ProjectPolicy`, `PluginTokenPolicy` | Organization-scoped authorization extends these |
| 2.5: Neo4jClient Interface | Typed `Neo4jClient` interface | Adding org parameter to Cypher calls |
| 3.1: Memory Graph Reconciliation | Canonical Neo4j graph is the source of truth | Org partitioning applies to the canonical graph |
| 3.3: Real Neo4j Labels | `:Function`, `:File`, etc. labels exist | Org labels are additive on top of these |
| 3.5: PostgreSQL Full-Text Search | `hades_search_documents` with tsvector | Organization filter must be added to search queries |
| 4.2: Pgvector Embedding Search | `EmbeddingIndexService::searchSimilar()` | Organization filter must be added to vector search |

### 8.2 Active P0/P1/P2 Work (Do NOT Block On)

| Task | Relationship | Approach |
|------|-------------|----------|
| 4.1: Plugin Client Testing | New tests for plugin auth | Tests pass regardless of organization_id (can be NULL-tolerant) |
| 4.4: Unit Test Coverage | General test coverage | Add organization-specific test cases after Phase 2 models exist |
| 4.5: Documentation Update | Architecture docs | Add organization chapter after Phase 0 schema is finalized |

### 8.3 Non-Dependencies (Unrelated)

| Task | Why Separate |
|------|-------------|
| 1.x: SSRF, CSP, RLS for Hades | Infrastructure security, independent of tenant model |
| 2.6: Python Plugin Retry/Install | Plugin-side changes, no server-side tenant impact |
| 5.x: Frontend Changes (Admin AI Agents, Project Page) | UI-only, wait until Phase 5 for org UX |

## 9. Implementation Notes

### 9.1 Organization Structure Decision Points

| Decision | Recommendation | Alternatives |
|----------|---------------|--------------|
| Organization nesting? | Flat (no parent/child) | Hierarchical organizations add complexity |
| Users in multiple orgs? | Yes (many-to-many via `organization_user`) | Single-org per user simplifies but limits use cases |
| Projects in multiple orgs? | No (projects belong to exactly one org) | Multi-org projects would require workspace federation |
| API tokens org-scoped? | Yes (tokens belong to org, optionally to user) | Per-user tokens only requires project-level scoping |
| Separate Neo4j databases? | No (labels provide logical partitioning) | Separate databases require connection pool management |

### 9.2 Risks Noted

- **27 tables with `project_id`** need query-scoping changes. This is the largest surface area.
- **DashboardApiReader** uses raw `DB::table()` extensively (dozens of queries). Each query needs a join/subquery to filter by organization. This is the highest-risk implementation step.
- **Neo4j dynamic labels** (`:Org_{uuid}`) generate unbounded label count. Neo4j has an upper bound (~32k unique labels) that is sufficient for typical SaaS scale but worth monitoring.
- **Backfill script** must be idempotent and reversible. A failed backfill must not leave projects with NULL `organization_id`.
- **Token `user_id` nullable** change (Phase 3.5) must preserve backward compatibility for existing plugin tokens.

### 9.3 Testing Strategy (per Phase)

Each phase must include:
1. **Migration tests:** Verify column exists, nullable/NOT NULL constraint, foreign key, backfill correctness.
2. **Model relationship tests:** Organization ↔ Users, Organization ↔ Projects, Organization ↔ Tokens.
3. **Query isolation tests:** Assert queries in one organization cannot see data from another.
4. **Authorization tests:** User in org A cannot access org B's projects, even with project_admin role.
5. **Neo4j isolation tests:** Cypher queries scoped to `:Org_A` do not return nodes from `:Org_B`.

## 10. Open Questions (For Implementation Planning)

- Should the seeded "Default Organization" be deleted after multi-org is fully operational?
- Should the organization switcher support URL-based routing (`/o/{org_slug}/projects/{project_slug}`) or session-based?
- Should Neo4j index creation for `:Org_{id}` labels happen lazily (on first graph import) or eagerly (in a migration)?
- Should billing/plan data be stored on `organizations` (e.g., `plan_tier`, `max_projects`, `max_users`) or in a separate `organization_plans` table?
- How does the organization boundary interact with cross-project Hades memory imports (currently `memory_import_batches` has `source_workspace_binding_id` → `target_workspace_binding_id`)?
