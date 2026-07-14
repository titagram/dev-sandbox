# Hades Agent React Frontend Repository Cutover Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `frontend/` in the Hades Agent repository the only tracked and deployed React frontend source while preserving the current live service until the replacement is verified.

**Status:** Tasks 1-4 are complete and verified on the implementation branch. Task 5 remains pending and is the only phase authorized to change the live frontend runtime or remove the external checkout.

**Architecture:** Copy the external Create React App source as a clean snapshot, excluding nested Git state, secrets, dependencies, and build artifacts. Build it through a repository-owned Docker service, route browser pages to nginx and backend paths to Laravel through explicit Traefik rules, then retire the external checkout only after local and public acceptance succeeds.

**Tech Stack:** React 19, TypeScript 5, CRA/CRACO, Tailwind, Jest, Yarn 1, multi-stage Docker, nginx, Docker Compose, Traefik 3.

## Global Constraints

- Keep the external checkout intact until the new image, container, public login flow, and API routing pass.
- Copy no `.git`, `.env*`, `node_modules`, `build`, coverage, cache, or `package-lock.json`.
- Use `frontend/yarn.lock` as the only frontend lockfile and `yarn install --frozen-lockfile`.
- React/nginx owns product pages plus the static Hades installers at `/install.sh` and `/install.ps1`. Laravel owns `/api`, `/sanctum`, and `/storage` through explicit higher-priority routing.
- Remove the obsolete Laravel Vite/Inertia `node` service from the active DevBoard Compose stack when the standalone React service is added. This cuts Inertia out of the deployed frontend without deleting backend compatibility code before route parity is proven.
- Do not remove Inertia until a separate route-parity gate proves every browser workflow has a React replacement.
- Do not print or commit credentials.

---

### Task 1: Import a clean frontend snapshot

**Files:**
- Create: `frontend/**`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

**Interfaces:**
- Consumes: tracked source from `/home/ubuntu/emergent_devboard_frontend/frontend` at commit `bbc11e8`.
- Produces: repository-owned React source without local runtime artifacts.

- [x] **Step 1: Record source identity and dirty paths**

```bash
git -C /home/ubuntu/emergent_devboard_frontend rev-parse HEAD
git -C /home/ubuntu/emergent_devboard_frontend status --short
```

Expected: `bbc11e8...`; modified `frontend/yarn.lock` and untracked `frontend/package-lock.json` are treated as local artifacts.

- [x] **Step 2: Copy the source and restore the committed lockfile**

```bash
rsync -a --delete \
  --exclude='.git/' --exclude='.env*' --exclude='node_modules/' \
  --exclude='build/' --exclude='coverage/' --exclude='.cache/' \
  --exclude='package-lock.json' \
  /home/ubuntu/emergent_devboard_frontend/frontend/ frontend/
git -C /home/ubuntu/emergent_devboard_frontend show bbc11e8:frontend/yarn.lock > /tmp/devboard-frontend-yarn.lock
cp /tmp/devboard-frontend-yarn.lock frontend/yarn.lock
```

Expected: the imported lockfile matches external commit `bbc11e8`, not its dirty working tree.

- [x] **Step 3: Prove forbidden files were not imported**

```bash
find frontend -maxdepth 2 \( -name .git -o -name node_modules -o -name build -o -name '.env*' -o -name package-lock.json \) -print
```

Expected: no output.

- [x] **Step 4: Install exactly from the lockfile**

```bash
cd frontend && corepack yarn install --frozen-lockfile
```

Expected: exit 0 and an unchanged `yarn.lock`.

### Task 2: Add Hades Agent browser branding

**Files:**
- Create: `frontend/public/favicon.svg`
- Create: `frontend/src/lib/branding.test.ts`
- Modify: `frontend/public/index.html`

**Interfaces:**
- Produces: browser title `Hades Agent — Project Intelligence`, Hades description metadata, and `/favicon.svg`.

- [x] **Step 1: Write the RED branding test**

```ts
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

it("uses Hades Agent browser branding", () => {
  const html = readFileSync(resolve(process.cwd(), "public/index.html"), "utf8");
  const favicon = readFileSync(resolve(process.cwd(), "public/favicon.svg"), "utf8");

  expect(html).toContain("<title>Hades Agent — Project Intelligence</title>");
  expect(html).toContain('href="%PUBLIC_URL%/favicon.svg"');
  expect(favicon).toContain('viewBox="0 0 64 64"');
});
```

- [x] **Step 2: Run RED**

```bash
cd frontend && CI=true corepack yarn test --watchAll=false --runTestsByPath src/lib/branding.test.ts
```

Expected: FAIL because `public/favicon.svg` does not exist and the document still uses DevBoard metadata.

- [x] **Step 3: Add the favicon and document metadata**

`public/index.html` must include:

```html
<link rel="icon" href="%PUBLIC_URL%/favicon.svg" type="image/svg+xml" />
<meta name="description" content="Hades Agent project intelligence and operations console" />
<title>Hades Agent — Project Intelligence</title>
```

The favicon is a self-contained 64x64 SVG with a dark background and high-contrast Hades helmet/flame mark.

- [x] **Step 4: Run GREEN and build**

```bash
cd frontend
CI=true corepack yarn test --watchAll=false --runTestsByPath src/lib/branding.test.ts
corepack yarn build
```

Expected: test PASS; build exit 0; `build/favicon.svg` exists.

### Task 3: Restore repository-owned frontend deployment

**Files:**
- Modify: `frontend/Dockerfile`
- Modify: `frontend/.dockerignore`
- Modify: `frontend/nginx.conf`
- Create: `frontend/src/api/apiBaseUrl.ts`
- Create: `frontend/src/api/apiBaseUrl.test.ts`
- Modify: `frontend/src/api/devboardApi.ts`
- Modify: `docker-compose.devboard.yaml`
- Modify: `docker-compose.devboard.amd64.yaml`
- Modify: `docker-compose.devboard.prod.yaml`
- Modify: `docker-compose.devboard.traefik.yaml`

**Interfaces:**
- Produces: Compose service `frontend`, nginx port 80, Traefik service `devboard-frontend`, and an explicit backend/frontend route split.

- [x] **Step 1: Make the production API default same-origin**

Create a small pure resolver in `apiBaseUrl.ts` and test it independently before wiring it into `devboardApi.ts`:

```ts
export interface ApiBaseEnv {
  REACT_APP_API_BASE_URL?: string;
}

export function resolveApiBaseUrl(env: ApiBaseEnv, browserOrigin?: string): string {
  return env.REACT_APP_API_BASE_URL || browserOrigin || "http://127.0.0.1:8000";
}

const browserOrigin = typeof window === "undefined" ? undefined : window.location.origin;
export const API_BASE_URL = resolveApiBaseUrl(process.env, browserOrigin);
```

The unit test must cover explicit CRA config, browser same-origin fallback, and server-side localhost fallback. Do not retain a `VITE_API_BASE_URL` branch: CRA does not inject unprefixed Vite variables, so that would be a misleading compatibility binding. Expected: a production image built with an empty API build arg calls the same host that served the page instead of the browser user's `127.0.0.1`.

Before building, make the container input deterministic: `.dockerignore` excludes every `.env*` file, and both Dockerfile base images use verified immutable `tag@sha256:<multi-arch-digest>` references.

- [x] **Step 2: Add the development frontend service**

```yaml
frontend:
  build:
    context: ./frontend
    args:
      REACT_APP_USE_MOCK: "false"
      REACT_APP_API_BASE_URL: ${DEVBOARD_PUBLIC_BASE_URL:-}
  restart: unless-stopped
  depends_on:
    app:
      condition: service_started
```

Remove the obsolete `node` service and its dedicated npm/node-modules volumes from `docker-compose.devboard.yaml`; no active Compose frontend path may start Laravel Vite/Inertia.
In `docker-compose.devboard.amd64.yaml`, remove the orphaned `node` platform override and add the equivalent `frontend: platform: linux/amd64` override so the architecture overlay remains valid.

- [x] **Step 3: Add the production service**

Use the same `./frontend` build context without a host bind mount. The API base defaults to same-origin so session cookies and CSRF stay first-party.

- [x] **Step 4: Add explicit Traefik frontend routes**

```yaml
traefik.http.routers.devboard-frontend.rule: Host(`${DEVBOARD_TRAEFIK_HOST:?Set DEVBOARD_TRAEFIK_HOST}`)
traefik.http.routers.devboard-frontend.priority: "1"
traefik.http.services.devboard-frontend.loadbalancer.server.port: "80"
```

Keep Laravel priority `100` for `/api`, `/sanctum`, and `/storage`. Add a priority `130` `devboard-install` router for exact `/install.sh` and `/install.ps1` paths to `devboard-frontend`, without BasicAuth, matching the currently working public installer contract. The catch-all product UI remains priority `1` and keeps BasicAuth.

- [x] **Step 5: Validate merged Compose without creating containers**

```bash
docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.traefik.yaml config --quiet
docker compose -f docker-compose.devboard.prod.yaml -f docker-compose.devboard.traefik.yaml config --quiet
```

Expected: both commands exit 0 and render exactly one `frontend` service.

- [x] **Step 6: Build the image in isolation**

```bash
docker compose -f docker-compose.devboard.yaml build frontend
```

Expected: image contains `index.html`, `favicon.svg`, `install.sh`, and `install.ps1`.

### Task 4: Correct ai-sandbox and deployment documentation

**Files:**
- Modify: `ai-sandbox/config/project.yaml`
- Modify: `docs/runbooks/devboard-production-deploy.md`
- Modify: `.gitignore`
- Create: `docs/superpowers/plans/2026-07-14-react-frontend-repository-cutover.md`
- Create: `docs/superpowers/plans/2026-07-14-work-queue-reclaim-race-test.md`
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

**Interfaces:**
- Produces: truthful frontend stack/build/start declarations and cutover/rollback instructions.

- [x] **Step 1: Replace active obsolete metadata**

Declare `Standalone React 19 frontend`, add `frontend` to Docker services, and use:

```yaml
build_commands:
  - "cd frontend && corepack yarn install --frozen-lockfile && corepack yarn build"
```

- [x] **Step 2: Document deploy and rollback**

State that `frontend/` is the only source, API routes stay Laravel-owned, rollback uses the previous known-good frontend image without changing the database, and `ai-sandbox/` is local bootstrap/rules/logbook support rather than shared project memory.

- [x] **Step 3: Find stale active instructions**

```bash
rg -n "/home/ubuntu/emergent_devboard_frontend|Inertia React|cd backend && npm run build" ai-sandbox docs docker-compose*.yaml
```

Expected: historical records may remain; active config and runbooks contain no stale operational instruction.

### Task 5: Verify, deploy atomically, and retire the external checkout

**Files:**
- Modify: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`

**Interfaces:**
- Consumes: green image and Compose validation from Tasks 1-4.
- Produces: live repository-owned frontend and removal of the external checkout after acceptance.

- [ ] **Step 1: Run frontend acceptance**

```bash
cd frontend
CI=true corepack yarn test --watchAll=false
corepack yarn build
```

Expected: all tests pass and build exits 0.

- [ ] **Step 2: Preserve and verify rollback inputs outside Git**

```bash
install -d -m 700 /home/ubuntu/backups/devboard
current_frontend_image="$(docker inspect --format '{{.Image}}' devboard-frontend-1)"
test -n "$current_frontend_image"
docker image inspect "$current_frontend_image" >/dev/null
docker image tag "$current_frontend_image" hades-agent-frontend:pre-cutover-20260714
test "$(docker image inspect --format '{{.Id}}' hades-agent-frontend:pre-cutover-20260714)" = "$current_frontend_image"

tar --exclude='.git' --exclude='node_modules' --exclude='build' -C /home/ubuntu \
  -czf /home/ubuntu/backups/devboard/emergent-frontend-pre-cutover-20260714.tar.gz \
  emergent_devboard_frontend/frontend
tar -tzf /home/ubuntu/backups/devboard/emergent-frontend-pre-cutover-20260714.tar.gz \
  | grep -q 'frontend/src/App.tsx'
```

Expected: the running frontend image ID exists; the rollback tag resolves to that exact image ID; the private backup directory exists; and archive validation succeeds before any new image build.

- [ ] **Step 3: Recreate only frontend after branch integration**

```bash
docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.traefik.yaml \
  up -d --build --no-deps frontend
```

Expected: backend, worker, scheduler, PostgreSQL, and Neo4j are not recreated.

- [ ] **Step 4: Execute public smoke checks**

Verify HTTPS redirect, `/login`, `/favicon.svg`, `/api/dashboard/me`, `/api/hades/v1/health`, `/install.sh`, browser login, a project page, and hard refresh of a nested React route. Expected: no 404/405, no Inertia HTML, favicon 200, API JSON remains Laravel-served.

If any smoke fails, immediately execute the frontend-only rollback from `docs/runbooks/devboard-production-deploy.md`, verify the restored frontend, and preserve `/home/ubuntu/emergent_devboard_frontend`. A failed smoke gate forbids Step 5.

- [ ] **Step 5: Remove the external checkout only after smoke passes**

```bash
rm -rf /home/ubuntu/emergent_devboard_frontend
test ! -e /home/ubuntu/emergent_devboard_frontend
```

Expected: old checkout absent; public smoke remains healthy.

- [ ] **Step 6: Final verification**

```bash
git diff --check
git status --short
docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.traefik.yaml ps
```

Expected: only planned files changed; runtime services healthy.
