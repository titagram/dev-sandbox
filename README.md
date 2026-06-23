# AI Sandbox Framework / DevBoard

Questo repository sta convergendo su **DevBoard**: una piattaforma self-hosted con:

- `backend/`: Laravel 13 + Inertia React
- `plugin/`: plugin locale Python `CLI + MCP` per Codex/Claude
- `analyzer/`: analyzer Python che genera artifact Genesis/Delta

Lo stack target della v1 e`:

- PostgreSQL obbligatorio
- filesystem locale obbligatorio
- Neo4j obbligatorio
- single workspace implicito
- deploy self-hosted

La documentazione decisionale di riferimento e` in:

- `docs/ai-devboard/03_DOMAIN_MODEL.md`
- `docs/ai-devboard/04_PLUGIN_SERVER_CONTRACT.md`
- `docs/ai-devboard/05_GENESIS_IMPORT.md`
- `docs/ai-devboard/06_DELTA_SYNC.md`
- `docs/ai-devboard/07_SECURITY_MODEL.md`
- `docs/ai-devboard/08_IMPLEMENTATION_STEPS.md`
- `docs/ai-devboard/09_DASHBOARD_WIREFRAMES.md`
- `docs/ai-devboard/10_RUNTIME_SEQUENCES.md`

## Stato attuale

La slice principale gia` verificata end-to-end in locale e`:

- onboarding plugin
- device registration
- repository link
- Genesis Import
- upload chunked degli artifact
- import PostgreSQL
- import Neo4j
- dashboard PM-first
- Delta Sync

Le verifiche live piu` recenti sono state fatte su Docker locale con `app + postgres + neo4j`, usando il plugin reale contro l'API reale.

## Struttura repo

```text
backend/   Laravel API, dashboard, auth, token admin, artifact registry, wiki, graph import
plugin/    Python CLI e MCP server locale per Codex/Claude
analyzer/  Python analyzer per Genesis e Delta
fixtures/  repository di test usati negli smoke/E2E
docker/    file Docker di supporto
tests/     E2E bootstrap e integrazione cross-module
```

## Prerequisiti

- Docker / Docker Desktop
- Python 3.14.x o compatibile
- Node solo se vuoi buildare il frontend sul host invece che nel container

Per vedere la piattaforma Docker effettiva:

```bash
docker info --format '{{.OSType}}/{{.Architecture}}'
```

## Avvio rapido locale

### 1. Avvia lo stack

```bash
docker compose -f docker-compose.devboard.yaml up -d app node postgres neo4j
```

Se hai collisioni di porte, cambia solo i binding host:

```bash
DEVBOARD_APP_PORT=18000 \
DEVBOARD_VITE_PORT=15173 \
DEVBOARD_POSTGRES_PORT=15432 \
DEVBOARD_NEO4J_HTTP_PORT=17474 \
DEVBOARD_NEO4J_BOLT_PORT=17687 \
docker compose -f docker-compose.devboard.yaml up -d app node postgres neo4j
```

Per validare la configurazione `amd64` target Ubuntu x64 da Apple Silicon:

```bash
docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.amd64.yaml config
docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.amd64.yaml up -d app node postgres neo4j
```

Nota: questo verifica la configurazione Docker `amd64`, ma **non sostituisce** una vera validazione su host Ubuntu x64.

### Esposizione Traefik

Su host con Traefik gia` attivo sulla rete Docker esterna `traefik_default`, compila prima gli asset frontend e poi avvia lo stack con l'override dedicato:

```bash
docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.amd64.yaml run --rm node sh -lc "npm install && npm run build"

DEVBOARD_APP_KEY='base64:...' \
DEVBOARD_APP_PORT=127.0.0.1:18000 \
DEVBOARD_POSTGRES_PORT=127.0.0.1:15432 \
DEVBOARD_NEO4J_HTTP_PORT=127.0.0.1:17474 \
DEVBOARD_NEO4J_BOLT_PORT=127.0.0.1:17687 \
docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.amd64.yaml -f docker-compose.devboard.traefik.yaml up -d app postgres neo4j
```

Il dominio pubblico configurato e` `https://home-sweet-home.cloud`. Traefik inoltra solo al container `app` sulla porta `8000`; PostgreSQL e Neo4j non hanno router Traefik. Nell'esempio i binding host restano su `127.0.0.1` per evitare ingressi pubblici fuori da Traefik.

### 2. Inizializza database e seed

```bash
docker compose -f docker-compose.devboard.yaml exec -T app php artisan migrate:fresh --seed --seeder=DevBoardSeeder --force
```

### 3. Verifica i servizi

```bash
docker compose -f docker-compose.devboard.yaml ps
docker compose -f docker-compose.devboard.yaml logs --tail=80 app
docker compose -f docker-compose.devboard.yaml exec -T neo4j cypher-shell -u neo4j -p graphify-sandbox 'RETURN 1 AS ok'
```

## Porte e credenziali locali

Questi valori sono quelli del `docker-compose.devboard.yaml`.

### Backend / dashboard

- URL: [http://127.0.0.1:8000](http://127.0.0.1:8000)
- login page: [http://127.0.0.1:8000/login](http://127.0.0.1:8000/login)

### Vite dev server

- URL: [http://127.0.0.1:5173](http://127.0.0.1:5173)

### PostgreSQL

- host: `127.0.0.1`
- port: `5432`
- database: `devboard`
- username: `devboard`
- password: `devboard`

Connessione rapida:

```bash
docker compose -f docker-compose.devboard.yaml exec -T postgres psql -U devboard -d devboard
```

### Neo4j

- browser: [http://127.0.0.1:7474](http://127.0.0.1:7474)
- bolt: `bolt://127.0.0.1:7687`
- username: `neo4j`
- password: `graphify-sandbox`

## Dati seedati per sviluppo e test

Dopo `migrate:fresh --seed --seeder=DevBoardSeeder` hai questi dati:

### Utente dashboard seedato

- email: `admin@example.com`
- password: `password`
- ruolo: `Admin`

### Project / repository seedati

- project slug: `demo-project`
- project name: `Demo Project`
- repository slug: `demo-repository`
- repository name: `demo-repository`

### Kanban seedato

Colonne default:

- `Backlog`
- `Ready`
- `In Progress`
- `Blocked`
- `Review`
- `Done`

### Importante sui plugin token

**Non esiste un plugin token seedato staticamente nel database.**
Il token va creato dalla dashboard Admin oppure generato in un bootstrap di test.

## Dashboard: pagine utili

Dopo il login:

- `/kanban`
- `/projects/{project_id}`
- `/runs`
- `/runs/{run_id}`
- `/wiki`
- `/wiki/pages/{page_id}`
- `/artifacts`
- `/graph`
- `/system`
- `/admin/plugin-tokens`

La pagina `/admin/plugin-tokens` ora copre:

- creazione token plugin
- rotazione token
- revoca token
- elenco device registrati
- revoca device registrati

La rotazione token richiede ora una conferma esplicita lato UI e lato server.

La pagina `/system` ora copre anche:

- artifact retention con `dry-run` e purge live confermato
- audit export con filtri `action`, `actor_type`, `from`, `to`
- storico locale dell'ultima operazione nella sessione corrente

## Creazione token plugin

Il flusso standard e`:

1. login come `admin@example.com / password`
2. apri `/admin/plugin-tokens`
3. crea un token
4. copia il `plain_token` subito: viene mostrato una sola volta

Default attuale della UI Admin:

- token name iniziale: `Gabriele local plugin`
- expiry days: `90`
- scopes:
  - `projects.read`
  - `repositories.read`
  - `runs.write`
  - `artifacts.write`
  - `wiki.write`

Azioni disponibili:

- create
- rotate
- revoke

## Setup plugin Python

```bash
python3 -m venv /tmp/devboard-plugin-venv
/tmp/devboard-plugin-venv/bin/python -m pip install -e analyzer -e plugin pytest
/tmp/devboard-plugin-venv/bin/devboard version
```

Avvio MCP locale:

```bash
/tmp/devboard-plugin-venv/bin/devboard-mcp
```

Credenziali plugin salvate in:

- default: `~/.config/devboard/credentials.json`
- override: `DEVBOARD_CREDENTIALS_PATH`

Il file viene scritto con permessi `0600`.

Esempio:

```json
{
  "device_id": "01H...",
  "server_url": "http://127.0.0.1:8000",
  "token": "devb_live_<token_id>|<secret>"
}
```

## Plugin Codex / Claude

Il bundle plugin locale e` in:

- `plugin/.codex-plugin/plugin.json`
- `plugin/.mcp.json`

Server MCP esposto:

- command: `devboard-mcp`

Prompt iniziali dichiarati nel plugin:

- `Check my DevBoard plugin status.`
- `Run Genesis Import for this repo.`
- `Upload the current Genesis bundle.`

## Flusso CLI reale consigliato

### 1. Check token

```bash
/tmp/devboard-plugin-venv/bin/devboard auth check \
  --server-url http://127.0.0.1:8000 \
  --token 'devb_live_<token_id>|<secret>'
```

### 2. Registra il device

```bash
/tmp/devboard-plugin-venv/bin/devboard auth register-device "Local Dev Machine" sha256:local-dev darwin arm64 \
  --server-url http://127.0.0.1:8000 \
  --token 'devb_live_<token_id>|<secret>'
```

### 3. Lista i progetti

```bash
/tmp/devboard-plugin-venv/bin/devboard projects list
```

### 4. Linka un repository locale

```bash
/tmp/devboard-plugin-venv/bin/devboard repos link <project_id> <repository_id> --repo-path /absolute/path/to/repo
```

### 5. Esegui Genesis

```bash
/tmp/devboard-plugin-venv/bin/devboard genesis run \
  --project-id <project_id> \
  --repository-id <repository_id> \
  --local-workspace-id <local_workspace_id> \
  --repo-path /absolute/path/to/repo
```

### 6. Carica gli artifact Genesis

```bash
/tmp/devboard-plugin-venv/bin/devboard artifacts upload --genesis --repo-path /absolute/path/to/repo
```

### 7. Esegui Delta

```bash
/tmp/devboard-plugin-venv/bin/devboard delta run \
  --project-id <project_id> \
  --repository-id <repository_id> \
  --local-workspace-id <local_workspace_id> \
  --base-snapshot-id <snapshot_id> \
  --repo-path /absolute/path/to/repo
```

I file locali del plugin vengono salvati in:

```text
.devboard/
  AGENTS.generated.md
  artifacts/
  cache/
  state.json
```

## Test ed E2E

### Backend

```bash
cd backend && php artisan test
```

### Frontend build

```bash
cd backend && npm run build
```

### Plugin

```bash
cd plugin && /tmp/devboard-plugin-venv/bin/python -m pytest -q
```

### Analyzer

```bash
cd analyzer && /tmp/devboard-plugin-venv/bin/python -m pytest -q
```

### E2E onboarding Genesis

```bash
python3 -m venv /tmp/devboard-e2e-venv
/tmp/devboard-e2e-venv/bin/python -m pip install pytest
/tmp/devboard-e2e-venv/bin/python -m pytest tests/e2e/test_onboarding_genesis.py -q
```

### Queue retry fault harness

```bash
/tmp/devboard-plugin-venv/bin/python -m pytest tests/e2e/test_queue_retry_fault.py -q
scripts/devboard_queue_fault_harness.sh
```

The harness:

- seeds the Docker stack;
- queues a real `ImportGenesisGraphToNeo4j` job;
- stops Neo4j;
- verifies phase 1 (`active`, retry pending);
- exhausts retries with zero backoff;
- verifies final failure (`graph.import_failed`).

### Ubuntu x64 acceptance

```bash
DEVBOARD_RUNTIME_ACCEPTANCE=1 /tmp/devboard-plugin-venv/bin/python -m pytest tests/e2e/test_runtime_acceptance.py -q
scripts/devboard_runtime_acceptance.sh
```

Use this only on a real Linux x64 host. It is the acceptance path that closes the remaining runtime validation gap left by local macOS Docker checks.

Bootstrap manuale equivalente:

```bash
scripts/devboard_e2e_bootstrap.sh
```

Lo script:

- crea un database Laravel temporaneo
- seeda admin/project/repository/token
- avvia il backend
- registra un device plugin
- linka un fixture repo
- esegue Genesis
- carica gli artifact
- controlla che `.devboard/state.json` non contenga il token
- verifica la dashboard
- scrive un report JSON

## Comandi operativi utili

### Rebuild Neo4j dagli artifact validati

```bash
docker compose -f docker-compose.devboard.yaml exec -T app php artisan devboard:neo4j-rebuild
docker compose -f docker-compose.devboard.yaml exec -T app php artisan devboard:neo4j-rebuild --snapshot=<snapshot_id>
docker compose -f docker-compose.devboard.yaml exec -T app php artisan devboard:neo4j-rebuild --mode=fake
```

### Retention artifact

```bash
docker compose -f docker-compose.devboard.yaml exec -T app php artisan devboard:artifacts-retain --dry-run
docker compose -f docker-compose.devboard.yaml exec -T app php artisan devboard:artifacts-retain --days=90
docker compose -f docker-compose.devboard.yaml exec -T app php artisan schedule:list
```

Lo scheduler Laravel registra anche una retention giornaliera automatica alle `03:15`, usando `DEVBOARD_ARTIFACT_RETENTION_DAYS`.

### Export audit log

```bash
docker compose -f docker-compose.devboard.yaml exec -T app php artisan devboard:audit-export
docker compose -f docker-compose.devboard.yaml exec -T app php artisan devboard:audit-export --action=artifact.purged
docker compose -f docker-compose.devboard.yaml exec -T app php artisan devboard:audit-export --format=csv --path=devboard/audit-exports/audit.csv
```

## Dove guardare per una verifica rapida

### PostgreSQL

```bash
docker compose -f docker-compose.devboard.yaml exec -T postgres psql -U devboard -d devboard \
  -c "select id, run_id, status, snapshot_id, created_at from genesis_imports order by created_at desc limit 3;" \
  -c "select id, repository_id, created_by_run_id, graph_snapshot_artifact_id, created_at from snapshots order by created_at desc limit 3;"
```

### Neo4j

```bash
docker compose -f docker-compose.devboard.yaml exec -T neo4j cypher-shell -u neo4j -p graphify-sandbox \
  "MATCH (s:DevBoardSnapshot) RETURN count(s) AS snapshots; MATCH (n:CodeNode) RETURN count(n) AS code_nodes;"
```

## Note runtime e limiti plugin

Rate limit plugin attuali:

- light bucket: `DEVBOARD_PLUGIN_LIGHT_RATE_LIMIT_PER_MINUTE` default `240`
- heavy bucket: `DEVBOARD_PLUGIN_HEAVY_RATE_LIMIT_PER_MINUTE` default `30`
- fallback legacy compat: `DEVBOARD_PLUGIN_RATE_LIMIT_PER_MINUTE`

Bucket `light`:

- auth
- device register
- project/repository list
- policy/instructions
- run lifecycle leggero
- local snapshot
- wiki revision

Bucket `heavy`:

- Genesis start
- Delta start
- chunk upload
- finalize

Nei dettagli run e nella graph page viene mostrato anche il `graph_extraction_mode` corrente:

- `graphify`
- `python_ast_fallback`
- `lightweight_fallback`
- `file_only`

Questo evita di sovrastimare la copertura graph sui repository non Python.

## Verifiche recenti sul branch

Verificate in questa sessione:

- `docker compose -f docker-compose.devboard.yaml config`
- `docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.amd64.yaml config`
- stack Docker locale `app + postgres + neo4j` avviato e healthy
- `curl http://127.0.0.1:8000/up`
- `psql select 1`
- `cypher-shell RETURN 1`
- E2E `tests/e2e/test_onboarding_genesis.py`
- backend `php artisan test`
- plugin `pytest`
- analyzer `pytest`
- frontend `npm run build`

## Cose che restano da fare

Questa sezione e` intenzionalmente mantenuta come backlog operativo sintetico.

### Deployment e runtime

- validazione reale su host Ubuntu x64, non solo config/compose da Mac
- harness piu` deterministico per esaurire anche i retry Neo4j live e osservare il passaggio finale a `graph.import_failed`

### Hardening prodotto

- download/link gestiti per gli export audit generati dalla UI
- policy di retention dedicata per i file di audit export

### Analyzer / graph

- espansione parser-backed oltre il fallback lightweight regex
- copertura piu` profonda dei simboli non Python oltre il livello dichiarativo

### File system / Git edge cases

- test live su repository reali con submodule/worktree complessi, oltre ai fixture sintetici

## Note

- `backend/node_modules` nel container vive in un volume Docker dedicato per evitare che binding Linux sovrascrivano il checkout host.
- Il backend live gira con `QUEUE_CONNECTION=sync` nel compose DevBoard locale.
- Il plugin MCP non accetta raw backend token come parametro tool: usa le credenziali locali salvate.

Per i dettagli sui container vedi anche `docker/devboard/README.md`.
