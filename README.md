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

## Guida rapida al test live

Questa sezione raccoglie le definizioni pratiche emerse durante il test live della nuova UI React su `https://home-sweet-home.cloud`.

### Project key

La `key` di un progetto e` lo slug stabile del progetto, non una chiave segreta.

Serve a identificare il progetto in modo breve e leggibile nelle API, nella UI e nei futuri script. Deve essere unica tra i progetti e puo` contenere solo lettere minuscole, numeri e trattini.

Esempi validi:

- `test-live-01`
- `crm-rewrite`
- `home-sweet-home`

Esempi da evitare:

- `Test Live 01`
- `crm_rewrite`
- `cliente/app`

Per un test manuale puoi scegliere un valore arbitrario ma stabile, per esempio `test-live-01`.

### Declare Repository

`Declare Repository` registra nel control plane DevBoard un repository logico appartenente al progetto.

Non clona repository, non legge codice sorgente e non carica file del repository target nel backend. Serve solo a dichiarare metadata operativi:

- nome del repository, per esempio `Target App`;
- repository key, per esempio `target-app`;
- branch di default, per esempio `main`;
- percorsi protetti, per esempio `.env` e `*.pem`;
- percorsi esclusi, per esempio `node_modules/` e `vendor/`;
- stack hints, per esempio `react`, `laravel`, `node`.

Dopo la dichiarazione, DevBoard crea un `repository_id`. Il local agent usa quel `repository_id` per collegare una cartella Git reale tramite `/api/plugin/v1/repositories/{repository}/local-workspaces`.

### Default branch

Il `default_branch` e` il branch canonico atteso per quel repository.

Serve a DevBoard per sapere quale branch usare come baseline logica per Genesis, Delta, wiki, graph e controlli futuri. L'agent locale rileva anche il branch corrente della workspace collegata; in futuro DevBoard potra` evidenziare se il branch corrente non coincide con quello atteso.

Per un test live usa quasi sempre `main`. Usa `master` se il repository locale e` vecchio e usa ancora quel branch, oppure `develop` se quello e` davvero il branch operativo del progetto.

### Flusso corrente

Il flusso live oggi e`:

1. Creare un progetto dalla UI.
2. Dichiarare uno o piu` repository logici nel progetto.
3. Creare/ottenere un token plugin.
4. Eseguire il local agent vicino al repository target.
5. Collegare la workspace Git locale al `repository_id`.
6. Verificare nel dettaglio progetto che Kickstart passi a `awaiting_genesis` e che la workspace risulti `linked`.

La UI browser deve continuare a usare solo `/api/dashboard/...`. Gli endpoint `/api/plugin/v1/...` sono riservati a CLI, MCP e agent locale.

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
docker compose -f docker-compose.devboard.yaml up -d --build --wait
```

Se hai collisioni di porte, cambia solo i binding host:

```bash
DEVBOARD_APP_PORT=18000 \
DEVBOARD_POSTGRES_PORT=15432 \
DEVBOARD_NEO4J_HTTP_PORT=17474 \
DEVBOARD_NEO4J_BOLT_PORT=17687 \
docker compose -f docker-compose.devboard.yaml up -d --build --wait
```

Per validare la configurazione `amd64` target Ubuntu x64 da Apple Silicon:

```bash
docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.amd64.yaml config
docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.amd64.yaml up -d --build --wait
```

Nota: questo verifica la configurazione Docker `amd64`, ma **non sostituisce** una vera validazione su host Ubuntu x64.

### Server pubblico con Traefik separato

Traefik e` infrastruttura condivisa del server e **non** e` un servizio del progetto Hades. Il progetto possiede soltanto le proprie label Docker e il collegamento alla rete esterna `traefik_default`.

Sul server ufficiale, `.env` imposta `COMPOSE_FILE=docker-compose.devboard.yaml:docker-compose.devboard.traefik.yaml`. Dalla root del repository il comando canonico e` quindi:

```bash
docker compose up -d --build --wait
```

Questo avvia `app`, il frontend React/Nginx, `worker`, `scheduler`, PostgreSQL e Neo4j, ma non crea, riavvia o rimuove Traefik. Non cancellare `COMPOSE_FILE` dal `.env` del server e non inserire il servizio `traefik` nei Compose del progetto.

Dettagli, preflight, routing e recovery:

- `docs/runbooks/traefik-integration.md`
- `~/traefik-readme.md` sul server

### Profilo production

Il profilo production separato e` `docker-compose.devboard.prod.yaml`.
Builda Laravel e il frontend React dedicato, richiede segreti espliciti, include worker e scheduler e non espone PostgreSQL o Neo4j su porte host.

Runbook:

- `docs/runbooks/devboard-production-deploy.md`

### 2. Inizializza database e seed

```bash
docker compose -f docker-compose.devboard.yaml exec -T app php artisan migrate:fresh --seed --seeder=DatabaseSeeder --force
```

### 3. Verifica i servizi

```bash
docker compose -f docker-compose.devboard.yaml ps
docker compose -f docker-compose.devboard.yaml logs --tail=80 app
docker compose -f docker-compose.devboard.yaml exec -T neo4j cypher-shell -u neo4j -p "${NEO4J_PASSWORD}" 'RETURN 1 AS ok'
```

## Porte e credenziali locali

Questi valori sono quelli del `docker-compose.devboard.yaml`.

### Backend / dashboard

- URL: [http://127.0.0.1:18000](http://127.0.0.1:18000)
- login page: [http://127.0.0.1:18000/login](http://127.0.0.1:18000/login)

### Frontend React

Il frontend e` servito dal container `frontend` tramite Traefik sul server pubblico. Non esiste piu` un servizio Vite/Inertia attivo nel Compose canonico.

### PostgreSQL

- host: `127.0.0.1`
- port: `15432`
- database: `devboard`
- username: `devboard`
- password: `devboard`

Connessione rapida:

```bash
docker compose -f docker-compose.devboard.yaml exec -T postgres psql -U devboard -d devboard
```

### Neo4j

- browser: [http://127.0.0.1:17474](http://127.0.0.1:17474)
- bolt: `bolt://127.0.0.1:17687`
- username: `neo4j`
- password: supplied via the `NEO4J_PASSWORD` environment variable (see `docker/devboard/README.md`)

## Dati seedati per sviluppo e test

Dopo `migrate:fresh --seed --seeder=DatabaseSeeder` in ambiente locale hai questi dati:

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
  - `policies.read`
  - `runs.write`
  - `artifacts.write`
  - `wiki.write`
  - `graph.write`

Azioni disponibili:

- create
- rotate
- revoke

## Setup plugin Python

```bash
python3 -m venv /tmp/devboard-plugin-venv
/tmp/devboard-plugin-venv/bin/python -m pip install -e 'analyzer[test]' -e 'plugin[test]'
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

### Installazione locale in Codex

Il plugin DevBoard in questo repository e` un bundle locale, non un plugin pubblicato in un marketplace Codex. Per provarlo subito in Codex, registralo come server MCP locale.

Prerequisito: crea un token plugin dalla UI Admin (`/admin/plugin-tokens`) e copia subito il `plain_token`, perche` viene mostrato una sola volta.

Scope consigliati per il test completo:

- `projects.read`
- `repositories.read`
- `policies.read`
- `runs.write`
- `artifacts.write`
- `wiki.write`
- `graph.write`

I vecchi scope `snapshot:write`, `run:create`, `diff:write`, `analyze:read` sono legacy UI/mock e non sono sufficienti per le route `/api/plugin/v1` correnti.

Installa il plugin Python e l'analyzer in un virtualenv locale:

```bash
cd /path/al/clone/ai-sandbox-framework
python3 -m venv /tmp/devboard-plugin-venv
source /tmp/devboard-plugin-venv/bin/activate
python -m pip install --upgrade pip setuptools wheel
python -m pip install -e './analyzer[test]' -e './plugin[test]'
which devboard
devboard version
```

Se `/tmp/devboard-plugin-venv/bin/devboard` non esiste, il passo `python -m pip install -e './plugin[test]'` non e` stato eseguito oppure e` fallito. Verifica con:

```bash
python -m pip show devboard-plugin
python -m pip install -e './analyzer[test]' -e './plugin[test]'
```

Registra il device e salva le credenziali locali:

```bash
devboard auth check \
  --server-url https://home-sweet-home.cloud \
  --token 'devb_live_<token_id>|<secret>'

devboard auth register-device "Local Dev Machine" sha256:local-dev "$(uname -s)" "$(uname -m)" \
  --server-url https://home-sweet-home.cloud \
  --token 'devb_live_<token_id>|<secret>'
```

Questo scrive `~/.config/devboard/credentials.json` con permessi `0600`. Non salvare il token nel repository target.

Registra l'MCP server in Codex:

```bash
codex mcp add devboard -- /tmp/devboard-plugin-venv/bin/devboard-mcp
codex mcp list
```

Apri una nuova sessione Codex nel repository target o in `dev-sandbox` e chiedi:

```text
Check my DevBoard plugin status.
```

Per test manuale da terminale, prima recupera `project_id` e `repository_id`:

```bash
devboard projects list
```

Poi linka la workspace Git locale:

```bash
devboard repos link <project_id> <repository_id> --repo-path /absolute/path/to/repo
```

Se il link va a buon fine, nel dettaglio progetto la workspace deve risultare `linked` e Kickstart deve avanzare verso `awaiting_genesis`.

### CLI agent minima Node

Lo slice live piu` recente ha anche una CLI agent minima in `agent/`. Serve per testare solo auth/device/link workspace, non ancora Genesis/Delta:

```bash
cd /home/ubuntu/dev-sandbox/agent
npm test
node bin/devboard-agent.js auth-check --server https://home-sweet-home.cloud --token 'devb_live_<token_id>|<secret>'
node bin/devboard-agent.js register-device --server https://home-sweet-home.cloud --token 'devb_live_<token_id>|<secret>' --name "Local Dev Machine"
node bin/devboard-agent.js link-workspace --server https://home-sweet-home.cloud --token 'devb_live_<token_id>|<secret>' --device-id <device_id> --repository-id <repository_id> --path /absolute/path/to/repo
```

Per ora, usa il plugin Python/MCP quando vuoi testare Codex e Genesis. Usa la CLI Node solo per validare il primo pairing live.

## Flusso CLI reale consigliato

Imposta prima il server da usare. Per il test live temporaneo:

```bash
export DEVBOARD_SERVER_URL="https://home-sweet-home.cloud"
export DEVBOARD_TOKEN='devb_live_<token_id>|<secret>'
```

Per un backend locale usa invece `http://127.0.0.1:8000`.

### 1. Check token

```bash
devboard auth check \
  --server-url "$DEVBOARD_SERVER_URL" \
  --token "$DEVBOARD_TOKEN"
```

### 2. Registra il device

```bash
devboard auth register-device "Local Dev Machine" sha256:local-dev "$(uname -s)" "$(uname -m)" \
  --server-url "$DEVBOARD_SERVER_URL" \
  --token "$DEVBOARD_TOKEN"
```

Da questo punto il plugin usa le credenziali salvate in `~/.config/devboard/credentials.json`.

### 3. Lista i progetti

```bash
devboard projects list
```

### 4. Linka un repository locale

```bash
devboard repos link <project_id> <repository_id> --repo-path /absolute/path/to/repo
```

### 5. Esegui Genesis

```bash
devboard genesis run \
  --project-id <project_id> \
  --repository-id <repository_id> \
  --local-workspace-id <local_workspace_id> \
  --repo-path /absolute/path/to/repo
```

### 6. Carica gli artifact Genesis

```bash
devboard artifacts upload --genesis --repo-path /absolute/path/to/repo
```

Se il bundle contiene finding bloccanti nel `security-report.json`, per esempio un `.env` locale che hai verificato non contenere segreti rilevanti, l'upload si ferma prima di inviare artifact e restituisce `requires_security_approval`. Dopo approvazione esplicita puoi ripetere:

```bash
devboard artifacts upload --genesis --repo-path /absolute/path/to/repo --allow-blocked-security-findings
```

Lo stesso parametro esiste nel tool MCP `devboard_upload_artifact` come `allow_blocked_security_findings: true`.

### 7. Esegui Delta

```bash
devboard delta run \
  --project-id <project_id> \
  --repository-id <repository_id> \
  --local-workspace-id <local_workspace_id> \
  --base-snapshot-id <snapshot_id> \
  --repo-path /absolute/path/to/repo
```

Per Delta vale la stessa policy: default bloccante, retry esplicito solo dopo verifica locale:

```bash
devboard delta run \
  --project-id <project_id> \
  --repository-id <repository_id> \
  --local-workspace-id <local_workspace_id> \
  --base-snapshot-id <snapshot_id> \
  --repo-path /absolute/path/to/repo \
  --allow-blocked-security-findings
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
python3 -m venv /tmp/devboard-plugin-venv
/tmp/devboard-plugin-venv/bin/python -m pip install -e 'analyzer[test]' -e 'plugin[test]'
cd plugin && /tmp/devboard-plugin-venv/bin/python -m pytest -q
```

### Analyzer

```bash
python3 -m venv /tmp/devboard-plugin-venv
/tmp/devboard-plugin-venv/bin/python -m pip install -e 'analyzer[test]' -e 'plugin[test]'
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
DEVBOARD_QUEUE_FAULT_ACCEPTANCE=1 /tmp/devboard-plugin-venv/bin/python -m pytest tests/e2e/test_queue_retry_fault.py -q
DEVBOARD_QUEUE_FAULT_ACCEPTANCE=1 scripts/devboard_queue_fault_harness.sh
```

The harness:

- seeds the Docker stack;
- requires `DEVBOARD_QUEUE_FAULT_ACCEPTANCE=1`, generates a unique Compose project, and locks it for the run;
- queues a real `ImportGenesisGraphToNeo4j` job;
- stops Neo4j;
- verifies phase 1 (`active`, retry pending);
- exhausts retries with zero backoff;
- verifies final failure (`graph.import_failed`).

### Ubuntu x64 acceptance

```bash
DEVBOARD_RUNTIME_ACCEPTANCE=1 /tmp/devboard-plugin-venv/bin/python -m pytest tests/e2e/test_runtime_acceptance.py -q
DEVBOARD_RUNTIME_ACCEPTANCE=1 scripts/devboard_runtime_acceptance.sh
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
docker compose -f docker-compose.devboard.yaml exec -T neo4j cypher-shell -u neo4j -p "${NEO4J_PASSWORD}" \
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
- App, worker e scheduler locali usano la coda database; gli harness distruttivi usano project name e volumi Compose isolati.
- Il plugin MCP non accetta raw backend token come parametro tool: usa le credenziali locali salvate.

Per i dettagli sui container vedi anche `docker/devboard/README.md`.
