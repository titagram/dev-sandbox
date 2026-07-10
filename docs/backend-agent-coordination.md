# Coordinamento con agente backend Laravel

Documento operativo per preparare e registrare il confronto con l'agente del
progetto backend Laravel. Non sostituisce `docs/implementation_plan.md`:
questo file raccoglie domande, risposte, migliorie emerse e conclusioni da
implementare in futuro.

## Fonti lette

- `docs/CODEX_AGENTS.md`
- `docs/implementation_plan.md`
- `docs/README.md`
- `docs/PROJECT_OVERVIEW.md`
- `docs/SOURCE_OF_TRUTH.md`
- `docs/hades-backend-laravel-prompt.md`

## Nota operativa per dubbi backend

Quando emergono dubbi sul contratto backend Laravel, si puo' riaprire in
autonomia il confronto con l'agente backend remoto via SSH:

```bash
ssh -tt ubuntu@162.19.229.31 'cd /home/ubuntu/dev-sandbox && codex resume --last --yolo'
```

Usare il comando per porre domande mirate, raccogliere risposte e aggiornare
questo documento con decisioni, rischi, migliorie e conclusioni operative.

## Contesto da portare al backend

Hades deve integrare un backend Laravel autorevole per progetti, token,
registrazione agent, shared memory, richieste operative locali e Persephone.
Il backend non decide modelli LLM, toolset locali o profili subagent. Hades
mantiene storage e fallback locali, ma non deve leggere o scrivere memoria
project-scoped backend senza progetto e workspace binding riconosciuti.

La prima fase privilegia:

- one-liner tokenizzato generato dalla dashboard Laravel;
- token project-scoped riutilizzabile per piu' agent sullo stesso progetto e
  revocabile;
- registrazione agent con `agent_id` locale e label macchina/profilo;
- mapping locale path/repo root -> progetto tramite `workspace_binding_id`;
- shared memory con Laravel autoritativo e Hades limitato a proposte;
- job read-only capability-scoped consegnati in pull/piggyback, non via
  Persephone;
- Persephone come layer realtime tra istanze Hades, non come canale job
  primario della prima fase.

## Domande preparate per il backend

### Installazione, token e registrazione agent

1. Quali route Laravel esistono gia' per generare il comando di installazione
   project-scoped e quali vanno create?
2. Il token project-scoped deve essere un token plain bearer, un token hashed in
   DB con lookup sicuro, o una coppia bootstrap token + agent token?
3. Quali campi restituisce l'endpoint di verifica token: progetto, capability,
   scadenza, revoca, URL realtime, policy job e memory?
4. La registrazione agent crea un token/credential derivato o continua a usare
   lo stesso token project-scoped?
5. Quali campi minimi vuole il backend per `agent_id`, label locale, versione
   Hades, piattaforma e capability locali?
6. Come vengono gestite revoca, rotazione e rinomina agent dopo la
   registrazione?

### Project linking e workspace binding

1. Quale route conferma il binding tra `project_id`, `agent_id` e workspace
   locale?
2. Il backend vuole ricevere path/repo root raw, hash/fingerprint, remote git
   URL, commit corrente, o una combinazione?
3. Quale definizione di `workspace_binding_id` stabile conviene usare e quando
   deve cambiare?
4. Come deve rispondere il backend quando lo stesso workspace viene linkato
   allo stesso progetto: idempotenza piena o refresh metadata?
5. Come deve rispondere quando lo stesso workspace risulta gia' legato a un
   progetto diverso?
6. Quale contratto serve per `hades project unlink`: notifica semplice,
   tombstone backend, stato archived, o solo evento audit?
7. Il backend deve conoscere gli stati locali `unlinked`, orphaned cache e
   cache disabled, oppure basta riceverli in health/doctor successivi?

### Shared memory

1. Quali entita' memory esistono gia' lato Laravel e quali campi sono
   obbligatori?
2. Quale schema usare per create/update/delete come proposta, inclusi
   `memory_id`, `base_version`/`etag`, `intent`, `provenance`, summary e actor?
3. Quali stati backend sono previsti per proposta accettata, rifiutata,
   conflicted, superseded, pending e applied?
4. La causa di rifiuto deve essere un codice stabile, un messaggio leggibile o
   entrambi?
5. Come vengono prodotti snapshot o delta versionati per consentire cache locale
   stale/cached su Hades?
6. Chi decide la soglia "pending troppo a lungo": policy discovery backend,
   policy per proposta o config locale Hades come fallback?
7. Quale policy backend deve applicare quando workspace/progetto non sono
   riconosciuti o memory capability e' disabilitata?

### Endpoint job e richieste operative locali

1. Quale route dedicata usera' Hades per il pull dei job, ad esempio
   `GET /api/hades/agent/jobs`?
2. Quali parametri sono obbligatori in request: `agent_id`, `project_id`,
   `workspace_binding_id`, capability locali, versione client, path fingerprint?
3. Quale schema job usare per `job_id`/idempotency id, capability, payload,
   deadline, retry/backoff, policy auto/confirm e limiti?
4. Confermiamo il lifecycle: `received`, `waiting_confirmation`, `started`,
   `completed`, `failed`, `expired`, `cancelled`, `unlinked`?
5. Quali endpoint servono per ack/stato, risultato, errore e cancellazione?
6. Quali job possono essere auto-eseguiti senza conferma e quali soglie devono
   imporre `waiting_confirmation`?
7. Le soglie auto/confirm arrivano sempre nel job, in discovery/capabilities, o
   entrambe?
8. Quale schema risultato vuole Laravel: `summary`, `provenance`, allegati
   bounded, metadata di truncation/redaction e lista omissioni?
9. Quale retention backend vuole per job terminali e risultati, e quale
   fallback locale Hades e' accettabile?

### `sync_git_tree` e `populate_backend_ast`

1. Esiste gia' uno schema AST lato Laravel? Se si', dove sono migration/model e
   quali campi aspettano?
2. Per `sync_git_tree`, il backend vuole file tree, git status, hash contenuti,
   commit HEAD, diff summary, linguaggi, dimensioni o altro?
3. Per `populate_backend_ast`, conviene parsare localmente in Hades oppure
   inviare sorgenti/metadata bounded al backend?
4. Quali linguaggi devono essere supportati nella prima fase?
5. Quali limiti di dimensione, numero file e profondita' simboli accetta il
   backend?
6. Come gestire redazione e omissione di file sensibili prima di popolare AST o
   git tree?

### Persephone realtime

1. Laravel prevede WebSocket, SSE o broadcast framework specifico per
   Persephone?
2. Quale auth/sessione realtime deriva dal token project-scoped o
   dall'agent registration?
3. Quali eventi minimi sono realistici nella prima fase: online/offline,
   message/inbox item, memory changed, task/status update, capability changed?
4. Serve persistenza inbox lato backend o Hades mantiene inbox locale e il
   backend consegna solo eventi?
5. Quale fallback HTTP polling deve dichiarare discovery quando Persephone non
   e' disponibile?

### Health, discovery e doctor

1. Quali endpoint health pubblici e authenticated capabilities esistono o vanno
   creati?
2. Quali capability deve leggere Hades durante setup: memory, jobs, realtime,
   project linking, AST, installer?
3. Quale forma hanno messaggi macchina leggibili per `doctor`?
4. Il backend puo' esporre policy suggerite per orphaned cache, pending memory,
   retention job e limiti payload, lasciando Hades autoritativo solo dove
   stabilito dal piano?
5. Quali esempi di risposta health/doctor possiamo usare come fixture futura?

## Risposte dell'agente backend

| Area | Risposta backend | Impatto su Hades |
| --- | --- | --- |
| Install/token | L'infrastruttura token piu' vicina e' `PluginTokenService` + `AuthenticatePluginToken`, ma `api_tokens.device_id` non modella bene agent multipli project-scoped. L'agente backend consiglia tabelle Hades dedicate. | Hades deve trattare il project token come bootstrap e preferire un agent token derivato dopo registrazione. |
| Agent registration | Serve registrazione esplicita di agent locale con `agent_id`, label, versione, platform e capabilities. | Hades deve generare/stabilizzare `agent_id`, salvare credential derivata e redigere token in log/status. |
| Project/workspace binding | Nessun contratto Hades/Persephone gia' presente nelle app path Laravel; il materiale piu' vicino e' workspace/plugin/work queue esistente. | Hades deve inviare fingerprint/redacted display path/git metadata, non assumere che path assoluti siano necessari o accettati. |
| Shared memory | Laravel deve restare autoritativo. Le proposte create/update/delete devono essere versionate, con provenance, policy e stati stabili. | Hades puo' proporre memoria, cache locale e gestire stale/degraded; create low-risk puo' essere auto-accepted da policy backend. |
| Jobs dispatcher | Esiste work queue vicina, ma serve endpoint Hades dedicato con lifecycle e capability scope. L'agente backend ha segnalato la differenza `canceled` vs `cancelled`. | Hades deve usare uno spelling API unico, consigliato `cancelled`, mappando internamente eventuali varianti backend. |
| `sync_git_tree` | Deve produrre snapshot bounded con limiti configurabili, no leakage di path/source raw, e test di validazione payload. | Hades deve redigere, limitare e tracciare omissioni/truncation. |
| `populate_backend_ast` | Deve importare artifact AST/symbol bounded; evitare invio grezzo di sorgenti e crescita incontrollata. | Hades deve partire da schema minimo versionato symbols/relations/provenance, non da full tree-sitter JSON come contratto iniziale. |
| Persephone | Prima milestone: inbox persistente + SSE; Reverb/WebSocket solo se serve realtime bidirezionale vero. | Hades deve progettare Persephone come server-relay persistente, non P2P e non canale primario job. |
| Health/discovery/doctor | Servono report doctor/capabilities, audit logs, metriche, pruning TTL e documentazione OpenAPI/contract. | Hades deve leggere discovery/policy backend e degradare in modo esplicito quando capability, realtime o memory non sono disponibili. |

## Domande del backend a Hades

| Domanda backend | Risposta Hades | Decisione/azione |
| --- | --- | --- |
| Hades puo' salvare/usare un token derivato dopo registrazione o deve usare il project token a ogni call? | Puo' e deve preferibilmente salvare un agent token derivato. Il project token e' bootstrap/project-scoped/revocabile. | Backend espone register che rilascia credential agent; Hades la persiste localmente in modo sicuro e la redige in output. |
| Persephone deve essere server-relay persistente o Hades prevede canali locali peer-to-peer? | Prima fase server-relay persistente. Niente P2P locale nella prima milestone. | Backend implementa inbox persistente + SSE/WS; Hades usa polling/SSE fallback e non usa Persephone per job primari. |
| Che schema AST puo' produrre Hades subito? | Nessuno schema fisso; Hades puo' produrre artifact/symbol summary bounded, versionato, con provenance, file hash/range e relations minime. | Backend propone schema minimo versionato; evitare full tree-sitter JSON come contratto obbligatorio iniziale. |
| Per i path locali basta `display_path` redatto + hash? | Si'. Non serve path assoluto leggibile in dashboard. | Identita' e auth usano fingerprint/git remote/project binding; display path serve solo per UX dopo redazione. |
| Le memory proposal `create` possono essere auto-accepted? | Si', per intent/capability low-risk secondo policy backend. Laravel resta autoritativo. | Create puo' diventare accepted/queued/rejected/transformed; update/delete richiedono etag/version/provenance e gating piu' severo. |

## Esecuzione M1 backend remoto - 2026-06-30

Stato: completata la slice M1 concordata nel backend remoto
`/home/ubuntu/dev-sandbox`, con tracking dettagliato in
`ai-sandbox/docs/hades-backend-m1-results-2026-06-30.md`.

Coordinamento SSH:

- Sessione backend agent riaperta con
  `ssh -tt ubuntu@162.19.229.31 'cd /home/ubuntu/dev-sandbox && codex resume --last --yolo'`.
- Primo brief M1 completo e secondo brief ridotto al solo test rosso sono
  rimasti bloccati in `Working` senza creare file.
- Ho interrotto lo stallo, implementato M1 direttamente via SSH e poi ho
  comunicato all'agente backend il risultato, il path del report remoto e le
  conclusioni operative per M2. L'agente backend ha risposto `ACK`.

M1 implementato:

- Route backend: `GET /api/hades/v1/health`,
  `POST /api/hades/v1/token/verify`, `POST /api/hades/v1/agents/register`,
  `GET /api/hades/v1/capabilities`.
- Tabelle dedicate: `hades_bootstrap_tokens`, `hades_agents`,
  `hades_agent_tokens`.
- Token dedicati Hades `hades_bootstrap_{ulid}|secret` e
  `hades_agent_{ulid}|secret`, con secret hashato, revoca, scadenza e scope.
- Registrazione agent idempotente per `project_id + external_agent_id`;
  `agent_id` locale resta separato da `backend_agent_id`.
- Intersezione capability dichiarate con policy backend; M1 supporta
  `read_files`, `sync_git_tree`, `populate_backend_ast`.
- `workspace_binding_id` non e' accettato dal client in M1; resta derivazione
  server-side per M2.
- Traefik espone `/api/hades/v1` senza BasicAuth, come gia' fatto per
  `/api/plugin/v1`, cosi' il client Hades puo' raggiungere il backend pubblico
  usando solo bearer token applicativi.

Verifiche remote:

- Test rosso iniziale: `7 failed`; health 404 e tabella
  `hades_bootstrap_tokens` mancante.
- Test verde Hades:
  `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test tests/Feature/Hades/HadesM1ApiTest.php`
  ha passato `7 tests / 63 assertions`.
- Regressione auth:
  `HadesM1ApiTest.php + PluginAuthTest.php` ha passato
  `14 tests / 88 assertions`.
- `php -l` pulito su controller, middleware, service, migration e test Hades.
- `vendor/bin/pint --dirty` passato con `0 files`.
- `git diff --check` passato.
- `php artisan route:list --path=hades` mostra 4 route Hades.
- Migration runtime applicata con `php artisan migrate --force`.
- Smoke pubblico:
  `https://home-sweet-home.cloud/api/hades/v1/health` ritorna HTTP 200 JSON;
  `https://home-sweet-home.cloud/api/hades/v1/capabilities` senza token ritorna
  HTTP 401 JSON Laravel, non piu' BasicAuth Traefik.

Follow-up emersi da M1:

- [ ] Aggiungere provisioning/revoca bootstrap token da dashboard o comando
  setup, evitando inserimenti DB manuali.
- [ ] Implementare M2 workspace binding server-side e non accettare binding id
  arbitrari dal client.
- [ ] Aggiungere smoke Hades client reale con bootstrap token appena esiste il
  provisioning.

## Migliorie emerse

- [ ] Separare le tabelle Hades da `api_tokens.device_id` per supportare piu'
  agent sullo stesso progetto, revoca/rotazione e capability per agente.
- [ ] Standardizzare lo spelling pubblico API su `cancelled` e mappare
  eventuale `canceled` solo internamente o in compatibilita' transitoria.
- [ ] Aggiungere limiti configurabili, redazione e metadata di omissione per
  git tree, artifact AST e risultati job.
- [ ] Tenere Persephone in prima fase su inbox persistente + SSE, rimandando
  Reverb/WebSocket bidirezionale a quando esiste un requisito non coperto.
- [ ] Produrre contract docs/OpenAPI e fixture health/discovery/doctor prima
  della piena integrazione Hades.

## DevBoard agent-work/memory/graph slice - 2026-07-01

Stato: implementata nel repo `/home/ubuntu/dev-sandbox` senza push/reset.

Risultati verificati da codice:

- `platon` e `aristoteles` in `agent_work_items.assigned_agent_key` ora sono
  serviti dal backend tramite alias controllati verso `task_clarifier` e
  `backlog_triage`.
- Le risposte server-side completate scrivono sia memoria progetto
  (`project_memory_entries.kind = agent_note`) sia chat persistente
  (`assistant_runs/messages` con `target_type = agent_work_item`).
- Aggiunto dettaglio dashboard
  `GET /api/dashboard/projects/{project}/agent-work/{workItem}` con eventi,
  result memory e messaggi chat.
- Memoria dashboard interrogabile per domini `logbook`, `wiki`, `agent_notes`
  tramite `domain` e `q`.
- Tool AI controllati aggiunti:
  `search_project_memory`, `query_project_graph`, `write_wiki_revision`.
- `write_wiki_revision` passa da `WikiRevisionService`: mantiene validazione
  evidence per `verified_from_code` e audit `wiki.updated`.
- La pagina React progetto espone chat agent-scoped basata su agent-work,
  dettaglio agent-work cliccabile, query domini memoria e link graph.

Verifiche:

- `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --display-warnings`
  passato: `282 passed / 2460 assertions`.
- `./vendor/bin/pint --test ...` sui file PHP toccati: passato.
- `npm run build` in `backend`: passato.
- Typecheck frontend non eseguito: `backend/package.json` non definisce script
  di typecheck e non esiste `node_modules/.bin/tsc`.

## Conclusioni da implementare in futuro

- [ ] **M1 - Backend foundation.** Creare namespace Hades lato Laravel con
  migrations dedicate per projects/agents/workspace bindings/agent tokens o
  token grants, usando token hashed, revoca, rotazione, audit e capability
  scope. Endpoint minimi: install command, token verify, agent register,
  capabilities discovery.
- [ ] **M2 - Project linking.** Implementare binding idempotente tra
  `project_id`, `agent_id` e workspace fingerprint. Salvare `display_path`
  redatto, git remote hash/display, HEAD commit, platform e last_seen; rifiutare
  collisioni cross-project esplicite con codice stabile.
- [ ] **M3 - Shared memory.** Implementare snapshot/delta memory versionati,
  proposals create/update/delete con `base_version`/`etag`, provenance,
  `intent`, actor, status e reason code. Laravel resta source of truth; Hades
  usa cache locale solo come degraded/stale fallback.
- [ ] **M4 - Jobs endpoint.** Aggiungere pull dedicato tipo
  `GET /api/hades/agent/jobs` e update status/result/cancel. Payload con
  idempotency key, capability, policy `auto|confirm`, deadline, retry/backoff,
  limits, workspace binding e result schema bounded con redaction/truncation.
- [ ] **M5 - Git tree e AST.** Definire due artifact versionati:
  `sync_git_tree` per file tree/git metadata bounded e `populate_backend_ast`
  per symbols/relations/provenance. No raw source upload come default; includere
  omission metadata e limiti di file/bytes/depth.
- [ ] **M6 - Persephone.** Implementare inbox persistente project/agent-scoped,
  eventi minimi online/offline, inbox message, memory changed, capability
  changed e job status summary. Prima consegna via SSE/polling fallback;
  WebSocket/Reverb solo come evoluzione.
- [ ] **M7 - Doctor, docs e hardening.** Esporre health public, authenticated
  discovery, doctor report, audit logs, metriche, pruning TTL, retention jobs,
  OpenAPI/contract docs e fixture di errore. Hades deve mostrare stato degraded
  senza rompere operativita' locale quando backend non e' disponibile.

## Piano di implementazione concordato

### Backend Laravel

- Routes/API: aggiungere `routes/api.php` o file route dedicato Hades per
  `install-command`, `token/verify`, `agents/register`, `workspaces/bind`,
  `memory/snapshot`, `memory/proposals`, `agent/jobs`, `agent/jobs/{id}/status`,
  `agent/jobs/{id}/result`, `persephone/inbox`, `persephone/events`,
  `health`, `capabilities`, `doctor`.
- Controllers/services: creare controller sottili e servizi dedicati per token
  bootstrap, agent registry, workspace binding, memory proposal policy, job
  leasing/idempotency, artifact ingestion e Persephone inbox.
- Migrations/models: introdurre tabelle Hades dedicate per project tokens/agent
  tokens, agents, workspace bindings, memory records/proposals, jobs/results,
  artifact manifests/chunks, inbox events, audit logs e capability policy.
- Queues/jobs: usare Laravel queue per pruning TTL, materializzazione artifact,
  fanout eventi Persephone e retry job result processing. Non mettere
  esecuzione locale nel backend: il backend assegna richieste, Hades esegue.
- Tests: feature test per auth/revoca, idempotenza register/bind, conflitti
  workspace, memory proposal status, job lifecycle, result redaction,
  artifact limits, SSE/polling fallback e doctor payload. Unit test per policy
  memory/job/capability e hashing token.

### Hades

- Setup/install: consumare project token una tantum, registrare agent, salvare
  agent token derivato e aggiornare config/profile senza invalidare fallback
  locale.
- Binding: calcolare fingerprint stabile da repo/workspace, inviare path
  redatto e git metadata, gestire collisione e unlink con stato locale chiaro.
- Memory: leggere snapshot/delta, proporre mutazioni con etag/provenance,
  degradare a cache locale se backend e' down, esporre pending/conflicted in
  doctor.
- Jobs: implementare dispatcher pull/piggyback con capability allow-list,
  conferma utente quando policy lo richiede, idempotenza, cancellazione,
  risultati bounded e stati terminali.
- Artifacts: implementare `sync_git_tree` e `populate_backend_ast` come job
  locali con limiti, redazione, omission metadata, no raw source upload default
  e schema versionato.
- Persephone: client SSE/polling per inbox persistente; non usare Persephone
  per esecuzione job primaria nella prima fase.
- Doctor: aggiungere check backend URL/token/project/workspace/memory/jobs/
  realtime/artifacts con messaggi action-oriented.

## Avanzamento Hades locale 2026-06-30

- Implementato client HTTP Hades con namespace provvisorio `/api/hades/v1`,
  token bearer, redazione segreti e metodi per health/capabilities, verify,
  agent register, workspace bind, memory, jobs, artifacts e Persephone inbox.
- Implementato storage SQLite profilo-scoped `hades_backend.db` per agent,
  workspace bindings, job, memory proposals, memory cache e inbox events.
- Implementato `hades backend setup/status/sync`: setup usa project token come
  bootstrap e salva il token agent derivato; sync fa pull job, persiste in coda,
  esegue capability read-only allow-listate e carica risultato bounded/redatto.
- Implementato `hades project link/unlink` con workspace fingerprint, display
  path redatto, git metadata e binding id stabile restituito dal backend.
- Implementato memory provider `hades_backend` con recall da cache locale solo
  quando il workspace e' linkato e write trasformate in proposte pending.
- Implementato doctor base e RPC TUI `backend.status`.

Restano da coordinare con Laravel prima della piena integrazione: nomi route
finali/OpenAPI, schema definitivo AST/artifact, payload e limiti job, policy
memory proposal, health/doctor remoto e Persephone SSE/polling.

## Coordinamento remoto aggiornato 2026-06-30

Sessione SSH riaperta con:

```bash
ssh -tt ubuntu@162.19.229.31 'cd /home/ubuntu/dev-sandbox && codex resume --last --yolo'
```

Brief consegnato all'agente backend: Hades ha gia' implementato la slice MVP
con Bearer auth, namespace provvisorio `/api/hades/v1`, project token come
bootstrap, `agent_token` derivato dopo `agents/register`, workspace binding
idempotente, job pull read-only, memory snapshot/proposals, Persephone inbox e
doctor/status su health/capabilities.

Output backend creato:

```text
ai-sandbox/docs/hades-backend-implementation-plan-2026-06-30.md
```

Correzioni/decisioni backend rilevanti da recepire:

- `token/verify` deve accettare solo project token bootstrap; le route operative
  devono richiedere agent token.
- `agent_id` ricevuto da Hades va trattato come external/local id, non come PK
  trusted: Laravel crea il proprio `hades_agents.id` e mantiene
  `external_agent_id`.
- `workspace_binding_id` deve essere generato dal backend e non accettato dal
  client.
- `capabilities[]` dichiarate da Hades vanno sempre intersecate con policy e
  grants backend.
- `project_id`, `agent_id`, `workspace_binding_id` e job devono essere validati
  nello stesso project scope per status/result.
- `populate_backend_ast` parte da schema summary versionato, non full
  tree-sitter JSON e non raw source upload.

Il piano remoto conferma milestones Laravel M1-M4:

- M1: identity, token bootstrap, registration, health/capabilities.
- M2: workspace binding e shared memory proposals.
- M3: agent jobs, read-only dispatcher, status/result.
- M4: git tree, AST summary e Persephone inbox.

Avanzamento Hades aggiunto nello stesso step:

- `hades backend sync` ora fa anche snapshot memory, invio proposal pending e
  persistenza `last_sync_summary` / `last_sync_error`.
- `hades doctor` espone health/capabilities remoti, conteggi job/proposals e
  ultimo sync.
- RPC TUI `backend.status` espone conteggi job/proposals e stato sync.

## Esecuzione backend remoto M2-M4 - 2026-07-01

Stato: completate direttamente via SSH nel backend remoto
`/home/ubuntu/dev-sandbox/backend`, branch `fase-2`, le slice M2, M3 e M4
necessarie al client Hades locale gia' implementato. Non e' stato necessario
porre nuove domande bloccanti all'agente backend, ma resta valido il comando
SSH documentato sopra per dubbi futuri.

M2 implementato:

- `POST /api/hades/v1/workspaces/bind` autenticato con agent token.
- `POST /api/hades/v1/workspaces/{workspaceBinding}/unlink` autenticato con
  agent token.
- Nuova tabella `hades_workspace_bindings`.
- `workspace_binding_id` generato dal backend come ULID e non accettato dal
  client.
- Binding idempotente su `project_id + hades_agent_id + workspace_fingerprint`.
- Salvataggio solo di metadata redatti/hashati: `display_path`,
  `git_remote_display`, `git_remote_hash`, `head_commit`, `platform` e
  fingerprint. Nessun path raw richiesto dal client.
- Conflitto stabile `workspace_project_conflict` se lo stesso fingerprint e'
  gia' linkato a un progetto diverso.
- `unlink` conserva storico e marca il binding `unlinked`.

M3 implementato:

- `GET /api/hades/v1/memory/snapshot` per snapshot versionato della shared
  memory di progetto.
- `POST /api/hades/v1/memory/proposals` per proposal create/update/delete.
- Nuova tabella `hades_memory_proposals`; la source of truth resta
  `project_memory_entries`.
- Snapshot restituisce `version`, `snapshot_version`, `etag` e `items` con
  payload JSON decodificato.
- Proposal `create` low-risk auto-accepted con scrittura in
  `project_memory_entries` come `source=hades_agent`, `kind=proposal`.
- Idempotenza su `workspace_binding_id + local_proposal_id`.
- Binding `unlinked` blocca snapshot/proposal con codice
  `workspace_binding_unlinked`.

M4 implementato:

- `GET /api/hades/v1/agent/jobs` per pull di job `queued`, filtrati per
  `project_id`, `agent_id`, `workspace_binding_id` e `capabilities[]`.
- `POST /api/hades/v1/agent/jobs/{job}/status` per lifecycle
  `received`, `waiting_confirmation`, `started`, `failed`, `expired`,
  `cancelled`, `unlinked`. Lo stato `completed` viene prodotto solo dal result endpoint.
- `POST /api/hades/v1/agent/jobs/{job}/result` per risultati bounded gia'
  prodotti dal client Hades locale.
- I job con `requires_confirmation=true` non possono passare da
  `waiting_confirmation` a `started` con il bearer agent. Una sessione dashboard
  Admin deve confermarli tramite
  `POST /api/dashboard/admin/hades/jobs/{job}/confirm`; la conferma e' atomica,
  auditata e vincolata a progetti attivi.
- Nuove tabelle `hades_agent_jobs` e `hades_agent_job_events`.
- Payload/result JSON restano strutturati; eventi status/result vengono
  tracciati separatamente.
- Lo spelling pubblico e' `cancelled`.
- `workspaces/{id}/unlink` marca `unlinked` i job non terminali collegati e
  lascia invariati quelli terminali.

Capability/discovery:

- `GET /api/hades/v1/capabilities` ora espone anche le route M2-M4.
- Policy aggiornata: `workspace_binding_required=true`, `memory=true`,
  `jobs=true`, `artifacts=false`, `persephone=false`.

Verifiche remote:

```bash
docker compose -f docker-compose.devboard.yaml exec -T app sh -lc \
  'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test tests/Feature/Hades tests/Feature/PluginAuthTest.php'
```

Risultato: `23 passed`, `189 assertions`.

Altre verifiche:

- Test rossi eseguiti prima di ogni slice:
  - M2: endpoint workspace mancanti -> 404.
  - M3: endpoint memory mancanti -> 404.
  - M4: tabella `hades_agent_jobs` mancante -> errore atteso.
- PHP lint passato su controller, service, migration, route e test Hades.
- `./vendor/bin/pint` passato dopo formattazione dei nuovi file.
- `git diff --check` passato.
- `php artisan route:list --path=hades` mostra 11 route Hades.
- `php artisan migrate --force` ha applicato:
  - `2026_07_01_000001_create_hades_m2_workspace_bindings_table`
  - `2026_07_01_000002_create_hades_m3_memory_proposals_table`
  - `2026_07_01_000003_create_hades_m4_agent_jobs_tables`
- Smoke pubblico:
  - `https://home-sweet-home.cloud/api/hades/v1/health` -> HTTP 200.
  - `https://home-sweet-home.cloud/api/hades/v1/capabilities` senza bearer ->
    HTTP 401 JSON `Hades agent token is required.`

Verifica end-to-end client Hades locale -> backend pubblico:

- Creato un progetto Laravel temporaneo e bootstrap token a scadenza breve.
- Eseguito con `HERMES_HOME` temporaneo locale:
  - `hades backend setup --url https://home-sweet-home.cloud ...`
  - `hades project create ...`
  - `hades project link ...`
  - `hades backend sync`
- Seed remoto creato per una memory entry e un job `read_files` su
  `README.md` nel workspace temporaneo.
- Esito locale dopo sync:
  - job locale `completed`;
  - proposta memory locale `accepted`;
  - cache memory aggiornata con la seed remota;
  - `last_sync_summary`: `pulled=1`, `completed=1`,
    `memory_snapshots=1`, `proposals_synced=1`, errori `0`.
- Esito remoto dopo sync:
  - job Laravel `completed`;
  - 3 eventi job registrati;
  - proposal Laravel `accepted`.
- Cleanup sicurezza: revocati tutti i bootstrap token e agent token associati
  ai progetti temporanei E2E; conteggio finale token attivi E2E `0`.

Risultato operativo:

- Il backend remoto ora espone il contratto necessario per provare
  `hades backend setup`, `hades project link` e `hades backend sync` contro
  Laravel per setup, workspace binding, memory snapshot/proposals e job
  read-only.
- Restano fuori da M2-M4: provisioning dashboard dei bootstrap token,
  artifact upload M5, Persephone/inbox M6, OpenAPI definitivo, retention
  policy e admin UI per creare job/manual review.

## Open questions residue

- Nome esatto delle route e versione API (`/api/hades/v1/...` o namespace
  equivalente).
- Schema minimo definitivo per artifact AST e cardinalita' symbols/relations.
- Limiti iniziali numerici per file count, bytes, symbol depth, job result size
  e retention TTL.
- Policy di auto-accept memory create: allow-list intent/capability e audit
  richiesto.
- Forma finale di OpenAPI/contract fixtures condivise tra repo backend e Hades.
- Modalita' di migrazione se il backend vuole compatibilita' temporanea con
  `PluginTokenService`/`AuthenticatePluginToken`.

## Stato della conversazione remota 2026-06-30

La sessione SSH su `ubuntu@162.19.229.31` in
`/home/ubuntu/dev-sandbox` e' stata avviata con
`codex resume --last --yolo`. L'agente backend ha ispezionato parti del repo
Laravel, token service/middleware e migrations, poi ha fornito le domande e i
rischi riportati sopra. La richiesta di far scrivere direttamente un markdown
remoto e' rimasta bloccata in stato `Working` senza creare il file; la
consegna locale di questo documento usa quindi le risposte gia' ottenute dalla
conversazione e segna esplicitamente i punti ancora aperti.

## Punti da riportare nel piano vivo

Quando una conclusione diventa stabile, aggiornare anche
`docs/implementation_plan.md` sotto la voce pertinente senza spuntare la voce
principale finche' resta lavoro operativo.

## Fix esposizione Admin Hades 2026-07-01

Problema verificato: `https://home-sweet-home.cloud/admin/hades` era una route
Laravel/Inertia registrata e buildata, ma Traefik la inoltrava al container
`frontend` perche' il router Laravel pubblico copriva solo `/api`, `/sanctum`,
`/storage` e `/api/hades/v1`. Con BasicAuth valida la risposta era quindi la
SPA Nginx generica, non la pagina `Admin/Hades`.

Fix remoto applicato in `/home/ubuntu/dev-sandbox/docker-compose.devboard.traefik.yaml`:
aggiunto router `devboard-laravel-web` con priorita' 130 per `Path(/login)`,
`Path(/logout)`, `PathPrefix(/admin/hades)` e `PathPrefix(/build)`, mantenendo
`devboard-basic-auth`. Ricreato `devboard-app-1` con la compose base +
override Traefik.

Verifiche fresche:

- Senza BasicAuth: `/admin/hades` resta `HTTP 401` Traefik.
- Con BasicAuth: `/admin/hades` risponde da PHP/Laravel con redirect a
  `/login`, non piu' da Nginx frontend.
- Con BasicAuth: `/login` risponde `HTTP 200` con HTML Inertia e asset
  `/build/assets/app-BTNwdPjc.js` risponde `HTTP 200`.
- `https://home-sweet-home.cloud/api/hades/v1/health` resta `HTTP 200`.
- Suite remota: `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory:
  DB_URL= php artisan test tests/Feature/Hades tests/Feature/PluginAuthTest.php`
  -> 27 test passati.

## Diagnosi routing frontend 2026-07-01

Richiesta riportata all'agent backend remoto: il frontend sospetta che alcune
route pubbliche vadano ancora al frontend Laravel/Inertia legacy invece del
frontend React corretto. Ho chiesto diagnosi non distruttiva su route Laravel,
fallback SPA React, Traefik/nginx, `/login`, dashboard e asset/static serving.

Risposta sintetica dell'agent backend:

- Il sospetto e' confermato per le page-route: Laravel ha ancora pagine web
  legacy registrate per `/login` e `/admin/hades`, mentre il frontend React ha
  fallback SPA e asset statici propri.
- La causa operativa e' il router Traefik `devboard-laravel-web`, aggiunto nel
  fix precedente, che instrada verso Laravel anche `/login`, `/logout`,
  `PathPrefix(/admin/hades)` e probabilmente `/build`.
- I file/config coinvolti sono il compose override Traefik remoto
  `/home/ubuntu/dev-sandbox/docker-compose.devboard.traefik.yaml`, le route web
  Laravel/Inertia per login/admin e la build/fallback del frontend React.
- Public smoke senza BasicAuth: `/login` e `/api/dashboard/me` rispondono
  `401`; questo e' coerente con BasicAuth Traefik, ma il routing effettivo e'
  determinato dalle label Traefik sopra.

Fix consigliato dall'agent backend:

- Rimuovere `/login`, `/logout` e probabilmente `/build` dal router
  `devboard-laravel-web`.
- Lasciare a Laravel solo `/api/*`, `/sanctum/*`, `/storage/*` e le API
  agent-facing gia' separate.
- Far servire tutte le page-route pubbliche dal catch-all del frontend React.
- Per `/admin/hades`, decidere esplicitamente: se deve essere UI pubblica va
  portata nel frontend React; se resta un tool Laravel temporaneo, spostarla su
  un path legacy/admin separato per non interferire con la SPA.

Nessuna modifica remota applicata durante questa diagnosi.

## Fix routing React per Admin Hades 2026-07-01

Obiettivo: correggere il routing pubblico dopo la diagnosi sopra e verificare
che `/admin/hades` non venga piu' catturato dal frontend Laravel/Inertia.

Intervento remoto eseguito in `/home/ubuntu/dev-sandbox`:

- Rimosse dal compose override Traefik le label del router
  `devboard-laravel-web`, che catturavano `/login`, `/logout`,
  `PathPrefix(/admin/hades)` e `PathPrefix(/build)` verso Laravel.
- Ricreato `devboard-app-1` con:
  `docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.traefik.yaml up -d app`
  usando l'`APP_KEY` gia' presente nel container, senza stamparlo.
- Rimosso il backup temporaneo creato durante l'edit.

Verifiche fresche:

- `docker inspect devboard-app-1` non contiene piu' label
  `devboard-laravel-web`.
- Traefik ha ricaricato una config con:
  - `devboard-frontend`: `Host(home-sweet-home.cloud)`, priority `1`,
    servizio `devboard-frontend`, BasicAuth;
  - `devboard-web`: solo `PathPrefix(/api)`, `PathPrefix(/sanctum)` e
    `PathPrefix(/storage)`, servizio Laravel;
  - `devboard-hades`: solo `PathPrefix(/api/hades/v1)`, servizio Laravel;
  - `devboard-plugin`: solo `PathPrefix(/api/plugin/v1)`, servizio Laravel.
- HTTP pubblico senza BasicAuth:
  - `/admin/hades` -> `401 Unauthorized` da Traefik BasicAuth, coerente con il
    router frontend protetto;
  - `/login` -> `401 Unauthorized` da Traefik BasicAuth;
  - `/api/hades/v1/health` -> `200 OK` JSON Hades;
  - `/api/dashboard/me` -> `401 Unauthorized` da Traefik BasicAuth.
- Verifica diretta del frontend container:
  `docker exec devboard-frontend-1 wget -S -O - http://127.0.0.1/admin/hades`
  -> `HTTP/1.1 200 OK`, `Server: nginx/1.27.5`, HTML React con
  `DevBoard - Operational Dashboard`, `/static/js/main.e0cdc452.js` e
  `/static/css/main.4898dcfb.css`.
- Route Laravel esistono ancora internamente per `admin/hades` e `login`, ma
  non sono piu' esposte dal router pubblico Traefik per quelle page-route.
- Test remoto:
  `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test tests/Feature/Hades tests/Feature/PluginAuthTest.php`
  -> 27 test passati, 239 assertions.

Stato remoto finale:

- `docker-compose.devboard.traefik.yaml` torna pulito rispetto a git.
- Rimane modificato solo `backend/bootstrap/app.php`, gia' presente prima di
  questo intervento e non toccato qui.

## Alias React Admin Hades 2026-07-01

Osservazione dopo il fix proxy: il routing Traefik mandava correttamente
`/admin/hades` al frontend React, ma `src/App.tsx` del frontend remoto aveva
solo la route `/admin`. Di conseguenza `/admin/hades` cadeva nel catch-all
React `*`, che naviga a `/`; da li' l'app puo' poi finire su `/projects` in
base al ruolo/sessione.

Intervento remoto eseguito nel repo frontend
`/home/ubuntu/emergent_devboard_frontend/frontend`:

- aggiunta una sola route:
  `/admin/hades` -> `<Section navKey="admin"><AdminPage /></Section>`;
- rebuild e redeploy del container:
  `docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.traefik.yaml up -d --build frontend`.

Verifiche fresche:

- Build Docker frontend completata con `craco build` e nuovo bundle
  `/static/js/main.41ad833e.js`.
- Il bundle deployato contiene la stringa `/admin/hades`.
- `docker exec devboard-frontend-1 wget -S -O - http://127.0.0.1/admin/hades`
  -> `HTTP/1.1 200 OK`, HTML React aggiornato con `main.41ad833e.js`.
- HTTP pubblico senza BasicAuth:
  - `/admin/hades` -> `401 Unauthorized` Traefik, cioe' la protezione esterna
    resta attiva;
  - `/login` -> `401 Unauthorized` Traefik;
  - `/api/hades/v1/health` -> `200 OK`.

Nota: non e' stato fatto un browser smoke autenticato perche' in questa
sessione non sono state usate credenziali BasicAuth. L'evidenza server-side
dimostra pero' che `/admin/hades` non e' piu' una route wildcard che cade su
`/projects`: il path esiste nel router React deployato e viene servito dal
frontend container.

## Fix cache index SPA Admin Hades 2026-07-01

Segnalazione successiva dal browser reale: DevTools mostrava ancora il body
HTML vecchio per `/admin/hades`, con script `/static/js/main.e0cdc452.js`, e
l'app finiva su `/projects`. La riga Network indicava document servito da disk
cache. Il deploy corretto invece aveva gia' generato `main.41ad833e.js` con la
route `/admin/hades`.

Intervento remoto nel frontend:

- aggiornato `nginx.conf` per rendere non cacheabile `index.html` e tutti i
  fallback SPA:
  - `Cache-Control: no-store, no-cache, must-revalidate`;
  - `Pragma: no-cache`;
  - `expires off`;
- mantenuto cache lungo solo su `/static/`, dove i file sono hashed;
- rebuild e redeploy di `devboard-frontend-1`.

Verifiche fresche dal container deployato:

- `wget -S -O - http://127.0.0.1/admin/hades` -> `HTTP/1.1 200 OK`.
- Header presenti: `Cache-Control: no-store, no-cache, must-revalidate` e
  `Pragma: no-cache`.
- Body aggiornato: script `/static/js/main.41ad833e.js`, non piu'
  `main.e0cdc452.js`.
- Bundle deployato: `/usr/share/nginx/html/static/js/main.41ad833e.js`
  contiene `/admin/hades`.

Nota operativa: i browser che hanno gia' messo in disk cache il vecchio
documento potrebbero richiedere un hard reload o "Disable cache" in DevTools
una volta. Dopo la prima fetch fresca, il nuovo `index.html` non dovrebbe piu'
essere riusato da cache.

## Porting React Admin Hades e installer 2026-07-01

Nuova segnalazione: `/admin` e `/admin/hades` mostravano la stessa pagina
React, e dalla UI non risultavano istruzioni di installazione Hades.

Diagnosi:

- Il backend Laravel aveva gia' le API Hades agent-facing M1-M5 sotto
  `/api/hades/v1/*`.
- Esistevano anche endpoint mutating dashboard per provisioning:
  `POST /api/dashboard/admin/hades/bootstrap-tokens`, revoke token, create job
  e review memory proposal.
- Mancava pero' un endpoint dashboard GET per lo snapshot Hades consumabile da
  React: la vecchia pagina Laravel/Inertia `GET /admin/hades` riceveva i dati
  server-side.
- La route React `/admin/hades` era stata solo aliasata ad `AdminPage`, quindi
  mostrava token/plugin/devices e non Hades.
- I comandi install generati dal backend puntavano a `/install.sh` e
  `/install.ps1`, ma il frontend pubblico serviva quei path come fallback SPA;
  inoltre Traefik li proteggeva con BasicAuth, rompendo `curl | bash`.

Interventi remoti:

- Backend `/home/ubuntu/dev-sandbox/backend`:
  - aggiunto `GET /api/dashboard/admin/hades` in
    `Dashboard\Api\DashboardHadesController@index`;
  - rimosso il web route legacy Laravel/Inertia `GET /admin/hades`;
  - aggiornato `tests/Feature/Hades/HadesM5MvpCompletionTest.php` per coprire
    lo snapshot dashboard Hades.
- Frontend `/home/ubuntu/emergent_devboard_frontend/frontend`:
  - aggiunta `src/pages/HadesAdminPage.tsx`;
  - `/admin/hades` ora punta a `HadesAdminPage`, non piu' ad `AdminPage`;
  - aggiunti tipi/metodi API Hades dashboard in `src/types/devboard.ts`,
    `src/api/devboardApi.ts`, `src/api/httpApi.ts` e mock fallback;
  - copiati gli installer Hades reali in `public/install.sh` e
    `public/install.ps1`.
- Traefik:
  - aggiunto router `devboard-install` per `Path(/install.sh) ||
    Path(/install.ps1)` verso `devboard-frontend`, senza BasicAuth.

Verifiche:

- Backend:
  `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test tests/Feature/Hades/HadesM5MvpCompletionTest.php`
  -> 4 test passati, 59 assertions.
- Route list backend `--path=hades`:
  - non mostra piu' `GET admin/hades`;
  - mostra `GET api/dashboard/admin/hades` e le route Hades API.
- Frontend:
  - `npm run build` locale remoto passato;
  - Docker build/deploy passato con bundle `main.8f5b836d.js`;
  - bundle deployato contiene `Admin - Hades` e `/admin/hades`.
- Installer pubblici:
  - `https://home-sweet-home.cloud/install.sh` -> HTTP 200, script Bash Hades,
    non HTML React;
  - `https://home-sweet-home.cloud/install.ps1` -> HTTP 200, script PowerShell
    Hades, non HTML React.
- Hades API:
  - `https://home-sweet-home.cloud/api/hades/v1/health` -> HTTP 200 JSON.

## Menu React Hades e test installer pubblico 2026-07-01

Richiesta successiva: esporre nel menu React un accesso rapido a
`/admin/hades` e verificare il comando installer generato per il progetto
Carnovali.

Interventi remoti frontend:

- aggiunta voce visibile nel menu React:
  `Hades` -> `/admin/hades`, con ruolo `admin`;
- aggiornato `src/App.tsx` per usare `navKey="hades"` sulla route
  `/admin/hades`;
- rebuild e redeploy del container `devboard-frontend-1`;
- bundle deployato `main.7aa6d769.js` verificato con stringhe `Hades` e
  `/admin/hades`.

Problemi trovati durante il test dell'installer pubblico:

- gli installer pubblici puntavano al repository storico
  `gabriele/hades-agent`, non accessibile dal clone HTTPS;
- dopo la correzione al repository `titagram/Hephaistos`, il default `main`
  non conteneva ancora il subcommand `hades backend`;
- il default dichiarato `--backend-workspace PATH` come "current dir" era
  fragile: l'installer cambia directory internamente, quindi senza workspace
  esplicita il bootstrap poteva legare la cartella sbagliata.

Correzioni installer:

- `scripts/install.sh` e `scripts/install.ps1` ora usano:
  - SSH/HTTPS repo `titagram/Hephaistos`;
  - branch default `codex/hades-rebrand`, finche' l'implementazione Hades non
    viene mergiata su `main`;
  - la directory iniziale dello script come workspace backend di default,
    prima dei `cd` interni dell'installer;
  - in Bash, `backend bootstrap` viene eseguito con il Python del venv appena
    installato quando `--no-venv` non e' attivo, evitando launcher `hermes`
    vecchi su PATH.
- Aggiornamento naming comando:
  - il comando primario installato e documentato dagli installer e' `hades`;
  - `hermes` resta installato solo come alias di compatibilita';
  - su questo Mac e' stato creato subito `~/.local/bin/hades`.
- Copiati gli installer aggiornati in
  `/home/ubuntu/emergent_devboard_frontend/frontend/public/` e redeployato il
  frontend.

Verifiche:

- `curl https://home-sweet-home.cloud/install.sh` mostra:
  - `REPO_URL_HTTPS="https://github.com/titagram/Hephaistos.git"`;
  - `BRANCH="codex/hades-rebrand"`;
  - `INSTALLER_START_DIR="$PWD"`;
  - passaggio workspace con `BACKEND_WORKSPACE:-$INSTALLER_START_DIR`.
- `curl https://home-sweet-home.cloud/install.ps1` mostra repo/branch Hades e
  fallback workspace a `$InstallerStartDir`.
- Eseguito da `/Users/gabriele/Dev/sinervis/carnovali` il comando pubblico
  generato con token redatto nei log:
  - installazione completata;
  - checkout passato a `codex/hades-rebrand`;
  - `uv.lock` sul ramo Hades risulta da aggiornare: l'installer ha completato
    usando il fallback `uv pip install -e '.[all]'`;
  - `Backend setup complete`;
  - agent `ha_c8df14317e732d80`;
  - workspace corretta `/Users/gabriele/Dev/sinervis/carnovali`;
  - binding corretto `01KWEPJGFG5K0W0J7NJEQ8YF0N`;
  - sync finale: 0 job, 2 snapshot memoria, 0 proposal/artifact/inbox.
- Stato locale finale:
  - `hades backend status` -> 1 binding;
  - unico binding attivo verso `~/Dev/sinervis/carnovali`.

Pulizia:

- Un run intermedio, prima della correzione workspace, aveva creato localmente
  un progetto `carnovali-2` verso `~/.hermes/hermes-agent/ui-tui`.
- Il progetto e' stato archiviato e il binding/cache locale spurio sono stati
  rimossi; resta attivo solo `carnovali`.

## Fix memory proposal summary lunga 2026-07-01

Problema osservato durante il test locale nel workspace Carnovali:

- `hades backend sync` inviava una memory proposal locale con summary lunga;
- il backend rispondeva HTTP 500;
- Laravel log riportava PostgreSQL `SQLSTATE[22001] value too long for type
  character varying(255)` su `project_memory_entries.summary`;
- il contratto API validava gia' `summary` fino a 4000 caratteri e
  `hades_memory_proposals.summary` era gia' `text`, quindi il collo di
  bottiglia era solo la tabella condivisa `project_memory_entries`.

Interventi backend remoto:

- `project_memory_entries.summary` cambiato da `string` a `text` nella
  migration sorgente;
- aggiunta migration runtime
  `2026_07_01_000005_expand_project_memory_entry_summary_column.php`;
- applicata migration su PostgreSQL con `php artisan migrate --force`;
- verificato via DB che l'ultima entry `hades_agent/proposal` per Carnovali
  contiene summary lunga 1005 caratteri.

Interventi client Hades locale:

- `hades_backend_sync.run_backend_sync()` ora cancella `last_sync_error` quando
  una sync completa termina senza errori;
- aggiunto helper `clear_sync_state()` in `hades_backend_db`;
- aggiornato anche il managed checkout locale `~/.hermes/hermes-agent` per
  rendere subito corretto il comando `hades` installato su questo Mac.

Verifiche:

- Test rosso schema: `project_memory_entries.summary` era `varchar`;
- test verde remoto: `22 passed / 237 assertions` su
  `tests/Feature/Hades` + `ProjectWorkspaceMemoryQueueSchemaTest.php`;
- test verde locale: `13 passed` su backend command, doctor e TUI RPC;
- `hades backend sync` da `/Users/gabriele/Dev/sinervis/carnovali`:
  `memory 1, proposals 1`, senza errori;
- sync successiva: `proposal_errors: 0`, `last_error: null`;
- stato locale: proposal accettata `accepted: 1`; resta una vecchia proposal
  `refused: 1` da revisionare separatamente, non collegata al 500.
