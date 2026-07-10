# DevBoard — Guida al Masterpiece Tecnologico

> Analisi esperta dello stato attuale e piano di evoluzione.
> Redatta il 2026-07-09. Nessuna modifica al codice è stata applicata: questo è un documento di indirizzo.
>
> **Metodo**: analisi statica del monorepo (`backend/` Laravel 13, `plugin/` + `analyzer/` Python, `agent/` Node, `ai-sandbox/` legacy, `docs/ai-devboard/*`, infrastruttura Docker). I riferimenti a file e riga sono indicativi dello stato al momento dell'analisi.

---

## 0. Executive summary

DevBoard ha una visione forte e insolitamente ben documentata (`docs/ai-devboard/00_VISION.md` → `12_*`): un control-plane self-hosted che fa da **memoria condivisa, policy engine e registro artifact** per agenti umani e LLM distribuiti su più client e progetti, senza che il backend debba mai vedere il codice sorgente. L'architettura di base è corretta e le scelte di fondo (deterministico prima dell'LLM, plugin locale unico lettore del codice, Neo4j ricostruibile da Postgres+FS) sono sane.

Ma tra la visione e l'implementazione c'è un divario preciso e va detto senza ammorbidirlo:

1. **La promessa centrale — "qualunque agente rileva un bug senza vedere il sorgente" — oggi è realizzata solo per bug *già diagnosticati*** (i causal pack sono riproducibili), e **per niente per la scoperta di bug latenti**. Manca il livello che la renderebbe possibile: il grafo non ha archi di chiamata risolti tra simboli interni, la wiki non contiene semantica comportamentale, e la ricerca è puramente lessicale (nessun embedding/RAG).
2. **Esistono due piani di memoria paralleli e poco integrati** — il piano Genesis/Plugin (`/api/plugin/v1`) e il piano Hades (`/api/hades/v1`). Condividono alcune tabelle ma hanno grafi, ricerca e API di query separati. Questo è il maggiore rischio architetturale a medio termine.
3. **Il backend Laravel è potente ma sotto-modellato**: ~40 tabelle, 4 soli model Eloquent, 823 chiamate `DB::` a mano, god class da 2.284 righe, PHPStan a livello 0. Funziona, ma il costo di ogni feature nuova cresce.
4. **La sicurezza ha buchi concreti** che vanno chiusi *prima* di aprire il sistema ad agenti esterni con token scambiati: token replayabili da qualunque macchina, path traversal via `artifact_id` non validato, `APP_KEY` e password Neo4j hardcoded nel compose usato in produzione live.
5. **Il deploy live (`home-sweet-home.cloud`) gira sul compose di sviluppo** dietro Traefik, ereditando `php artisan serve`, `composer install` a runtime e i secret di default.

La buona notizia: nessuno di questi problemi è strutturalmente irreparabile. La visione regge. Questa guida ordina gli interventi per **rischio × valore**, non per difficoltà.

### Come leggere le priorità

| Fascia | Obiettivo | Orizzonte |
|---|---|---|
| **P0 — Blocca il rilascio** | Sicurezza e deploy: da chiudere prima di distribuire token ad agenti esterni | giorni |
| **P1 — Fondamenta** | Rendere il sistema manutenibile e affidabile su cui costruire | settimane |
| **P2 — La visione** | Il livello di conoscenza semantica che realizza "trova il bug senza il sorgente" | mesi |
| **P3 — Scala & polish** | Multi-tenancy, performance su repo grandi, consolidamento legacy | continuo |

---

## P0 — Sicurezza e deploy (bloccanti)

Questi vanno chiusi prima di scambiare token con agenti esterni. Sono i punti dove un token rubato o un artifact malevolo causano danno reale.

### P0.1 — Token plugin replayabili da qualunque macchina

**Cosa succede.** Il `fingerprint_hash` del device è fornito interamente dal client (`RegisterDeviceController.php:16-22`, validato solo come `string|max:255`) e non è mai legato crittograficamente al secret del token. Una volta che un token è associato a un device, `PluginTokenService::authenticateRequest()` verifica solo che la riga device sia `active` (`PluginTokenService.php:58-64`) — **non** ricontrolla che la richiesta presenti lo stesso fingerprint. Chi esfiltra il bearer token lo usa da qualunque macchina.

**Perché è grave qui più che altrove.** Tutto il modello DevBoard si regge sull'idea di distribuire token ad agenti esterni (Claude Code, Codex, Hermes, opencode). Se il token è un bearer puro portabile, un leak (log, cronologia shell, `ps`, plugin compromesso) equivale a compromissione totale del progetto.

**Cosa cambiare, dove, come.**
- **Dove**: `app/Services/PluginTokenService.php`, `app/Http/Middleware/AuthenticatePluginToken.php`, `app/Http/Controllers/Plugin/RegisterDeviceController.php`; lato client `plugin/src/devboard_plugin/client.py` (headers).
- **Come (minimo)**: al `register-device`, il server genera un **device secret** casuale e lo restituisce una sola volta; il client lo salva accanto al token in `~/.config/devboard/credentials.json`. Ogni richiesta autenticata include un header di prova (es. HMAC del metodo+path+timestamp+body-hash con il device secret). Il middleware ricalcola l'HMAC e rifiuta se non combacia o se il timestamp è fuori finestra (anti-replay). Questo lega il token alla macchina senza mTLS.
- **Come (ideale)**: request signing completo o mTLS per gli agenti in ambienti gestiti. Ma l'HMAC per-device chiude il 90% del rischio con costo basso.
- **Perché**: trasforma il device da etichetta contabile a controllo anti-replay reale.

### P0.2 — Path traversal via `artifact_id` non validato

**Cosa succede.** Lo storage path degli artifact è costruito da `artifact_id`, che è **fornito dal client** nel manifest e validato solo come `string` (`GenesisStartController.php:36`). `ArtifactStorageService::chunkPath()`/`artifactPath()` lo interpolano direttamente in `devboard/artifacts/{scope}/{importId}/{artifactId}/...` (`ArtifactStorageService.php:10-18`). Un `artifact_id` con `../` può redirigere scrittura/lettura fuori dal prefisso previsto. Peggio: viene usato anche come primary key `char(26)` — Postgres fa padding/truncation invece di rifiutare.

**Cosa cambiare.**
- **Dove**: `app/Http/Controllers/Plugin/GenesisStartController.php` (e l'equivalente delta), `app/Services/ArtifactStorageService.php`.
- **Come**: validare `artifact_id` con regex ULID stretta `/^[0-9A-HJKMNP-TV-Z]{26}$/` in ingresso; e/o generare **lato server** il nome di storage ignorando quello del client (i doc lo richiedono esplicitamente: `07_SECURITY_MODEL.md` "use generated storage names, not user-provided paths"). Aggiungere una FormRequest dedicata (vedi P1.3).
- **Perché**: un client con scope `artifacts.write` non deve poter scrivere fuori dalla sua sandbox di storage.

### P0.3 — Upload senza limiti → OOM/DoS

**Cosa succede.** `size_bytes` è validato solo come `integer` senza `max:`; `chunk_count` ha `min:1` ma nessun massimo (`GenesisStartController.php:39,43`); il body del chunk è illimitato (`getContent()`). `ArtifactStorageService::assembleArtifact()` concatena **tutti i chunk in una singola stringa PHP in memoria** (`:55`), e `GenesisGraphImportService.php:209` fa `json_decode` dell'intero grafo in RAM. Un client con `artifacts.write` può mandare in OOM il worker.

**Cosa cambiare.**
- **Dove**: controller di start/chunk genesis e delta; `ArtifactStorageService`; `GenesisGraphImportService`.
- **Come**: cap configurabili per dimensione-chunk (es. 8 MiB), numero-chunk e dimensione-totale-artifact; assemblaggio via **stream su file** (append su disco, non concatenazione in stringa); parse del grafo in streaming (JSON streaming reader) invece di `json_decode` monolitico. Vedi anche P1.6.
- **Perché**: la superficie di upload è la più esposta e la più costosa in memoria.

### P0.4 — Secret hardcoded e deploy live sul compose dev

**Cosa succede.**
- `backend/.env` è **tracciato in git** (attualmente vuoto, ma `.gitignore` non protegge un file già tracciato) → un commit futuro popolato leakerebbe.
- `docker-compose.devboard.yaml` hardcoda un `APP_KEY` base64 reale come fallback (`:12,52`) e la password Neo4j `<redacted-rotated-neo4j-password>` (`:32,72,114,127`); quest'ultima è anche fallback in `config/services.php:42`. <!-- credential rotated 2026-07-10 per remediation Task 0.2; value redacted -->
- Il dominio pubblico `home-sweet-home.cloud` monta `docker-compose.devboard.traefik.yaml` **sopra il compose dev** (`README.md:167`), quindi la produzione eredita `php artisan serve`, `composer install` a runtime, bind mount `.:/workspace` e i secret di default — pur con `APP_ENV=production`.

**Cosa cambiare.**
- **Dove**: `docker-compose.devboard.*.yaml`, `config/services.php:42`, `backend/.env`.
- **Come**:
  1. `git rm --cached backend/.env` e ruotare qualunque secret già committato (incluso l'`APP_KEY` di default, ora pubblico).
  2. Rimuovere i fallback hardcoded: usare `${VAR:?}` ovunque come già fa `docker-compose.devboard.prod.yaml` (che è fatto bene).
  3. Far sì che il layer Traefik si sovrapponga a `prod.yaml`, non al dev. Consolidare i due percorsi "produzione" in uno solo.
- **Perché**: l'`APP_KEY` di default pubblico compromette cifratura sessioni, `Crypt`, e le **API key dei provider AI cifrate nel DB** — che dipendono proprio da quella chiave.

### P0.5 — Audit log senza tamper-resistance e con eventi mancanti

**Cosa succede.** Le scritture su `audit_logs` sono raw `DB::table(...)->insert(...)` sparse in ~15 file, senza un `AuditLogger` centrale. Diversi eventi richiesti dai doc non vengono emessi: `token.created`, `token.revoked` (esiste solo `token.rotated`), `permission.denied`, `run.*`, `artifact.uploaded/rejected`, `repository.linked`. La tabella è "append-only" solo per convenzione: nessun hash-chain, nessun trigger, l'utente DB dell'app può fare UPDATE/DELETE.

**Cosa cambiare.**
- **Dove**: nuovo `app/Services/AuditLogger.php`; call site sparsi.
- **Come**: un unico servizio `AuditLogger::record($action, $target, $payload, $actor)` che tutti chiamano; aggiungere gli eventi mancanti (specialmente `permission.denied` su ogni `abort_unless` 403 e `token.created/revoked`); hash-chain leggero (ogni riga include `prev_hash = sha256(prev_row_canonical)`) per rendere il tampering rilevabile.
- **Perché**: senza audit affidabile non c'è forensics quando un token esterno fa qualcosa di sbagliato — e con agenti esterni succederà.

### P0.6 — SSRF nel config provider AI (defense-in-depth)

**Cosa succede.** `AiAgentRegistry` fa `Http::withToken(...)->get/post($endpoint)` dove `endpoint` deriva da `provider->base_url`, impostabile via config Admin (`:489,529,647`). Nessuna allowlist, nessun blocco su IP interni (169.254.169.254, ecc.). Un Admin compromesso o un config avvelenato raggiunge endpoint interni.

**Cosa cambiare.** Allowlist di host per i provider; blocco range privati/link-local; limite redirect. È Admin-only quindi P0-basso, ma va messo prima dell'apertura multi-org.

---

## P1 — Fondamenta (manutenibilità e affidabilità)

Questi non sono cosmesi: sono ciò che determina se le feature P2 costeranno giorni o settimane l'una.

### P1.1 — Introdurre un vero livello Eloquent

**Cosa succede.** ~40 tabelle, **4 model** (`User`, `HadesAgent`, `HadesAgentToken`, `HadesBootstrapToken`), **823 chiamate `DB::`**. Ogni colonna JSON è decodificata a mano, ogni ULID generato a mano, ogni join scritto a mano. È la radice della maggior parte degli altri smell (N+1, boilerplate audit duplicato, nessun route-model binding).

**Cosa cambiare.**
- **Dove**: `app/Models/` (nuovi model per Project, Repository, Run, Artifact, Snapshot, WikiPage, WikiRevision, ApiToken, Device, Task, e le tabelle Hades).
- **Come**: introdurre model con `$casts` (JSON→array, ULID, enum), relazioni, e `SoftDeletes` dove oggi il `deleted_at` è gestito a mano. **Non serve un big-bang**: si può migrare per dominio, iniziando dalle entità più lette (Project, Run, Artifact) e sostituendo i `DB::table` nei reader. Route-model binding elimina i lookup manuali `->where('id', ...)`.
- **Perché**: ogni feature futura (multi-tenancy, freschezza wiki, nuovi tipi di nodo) diventa 3× più economica. È l'investimento con il ROI più alto del progetto.

### P1.2 — Spezzare le god class

**Cosa succede.** `DashboardApiReader.php` = **2.284 righe**, 25 metodi pubblici, iniettato in 12+ controller, 104 `DB::table`. `AiAgentRegistry.php` = 1.040. `MemorySearchController.php` = 991 (logica in un controller). `AgentWorkItemController.php` = 843.

**Cosa cambiare.** Suddividere per concern di dominio: `DashboardApiReader` → reader per area (OverviewReader, KanbanReader, ProjectReader, RunReader...). Spostare la logica dai controller Hades/Plugin a servizi dedicati, come già fa bene `DeltaFinalizeController → DeltaFinalizeService`. Obiettivo: nessun controller con query/validazione/audit inline.

### P1.3 — FormRequest + autorizzazione centralizzata

**Cosa succede.** **Zero FormRequest**: ~92 `$request->validate([...])` inline. Autorizzazione via check ad-hoc `abort_unless($this->userHasRole(...,'Admin'),403)` sparsi; la tabella `permissions` e `roles.permissions` JSON **non sono lette da alcun codice** — l'autorizzazione è solo per nome-ruolo, e molte route dashboard di scrittura sono dietro il solo `auth` senza gate per-route.

**Cosa cambiare.** FormRequest per ogni endpoint di scrittura (validazione riusabile e testabile, e il posto giusto per la validazione ULID di P0.2). Laravel **Policies/Gates** al posto dei check ad-hoc; far leggere davvero il modello permessi. Audit `permission.denied` su ogni negazione.

### P1.4 — Unificare l'esecuzione asincrona di genesis e delta

**Cosa succede.** L'import grafo genesis è accodato (`GenesisFinalizeService.php:119 ImportGenesisGraphToNeo4j::dispatch`), ma l'import grafo delta **identico** gira **sincrono dentro la request HTTP** (`DeltaFinalizeService.php:233`), senza retry/backoff e bloccando la connessione.

**Cosa cambiare.** Estrarre un unico `ImportGraphToNeo4j` job usato da entrambi i percorsi; delta accoda come genesis. Uniformare tries/backoff (già configurabili per genesis).

### P1.5 — Tipizzare il boundary Neo4j e alzare PHPStan

**Cosa succede.** `Neo4jClientFactory::client(): object` e `?object $client` ovunque → PHPStan cieco. `phpstan.neon.dist` è a **livello 0**. `RefreshDatabase` è commentato in `Pest.php` e i test girano su **SQLite in-memory mentre la produzione è Postgres** — quindi full-text index, operatori JSON e `orderByRaw` Postgres non sono mai esercitati.

**Cosa cambiare.** Un'interfaccia `Neo4jClient` (metodo `run(string, array): Result`) implementata dal client reale e dal fake. Alzare PHPStan progressivamente (0→5→max) man mano che arrivano i model tipizzati di P1.1. Aggiungere un job CI che gira i test contro **Postgres** (non solo SQLite), così il degrado full-text→LIKE (vedi P2.3) emerge in test.

### P1.6 — Streaming end-to-end degli artifact

**Cosa succede.** Lato client `artifacts.py` fa `read_bytes()` dell'intero file prima di chunkare (annullando il beneficio del chunking); lato server si concatena in RAM e si fa `json_decode` monolitico. Su repo grandi è il principale rischio di scala/OOM (collegato a P0.3).

**Cosa cambiare.** Client: leggere e uploadare a chunk in streaming. Server: assemblare su file, parse del grafo in streaming, batch di scrittura Neo4j (già presente `BATCH_SIZE=500`, mantenerlo). Rispettare `DEVBOARD_ARTIFACT_DISK` invece dell'hardcoded `Storage::disk('local')`.

### P1.7 — Resilienza del client Python

**Cosa succede.** In `plugin/src/devboard_plugin/client.py`: nuovo `httpx.Client` per ogni richiesta (nessun pooling), `timeout=30.0` hardcoded, **nessun retry/backoff**, timeout/`ConnectError` non wrappati in `DevBoardApiError`. `handle_api_error` è definito ma **mai collegato** (`cli.py:483`) → traceback grezzi all'utente. Gli upload sono sequenziali e **non riprendibili**: un errore a metà riparte da zero nonostante il protocollo supporti chunk indicizzati. `load_credentials` non gestisce file mancante/corrotto.

**Cosa cambiare.** Un `httpx.Client` riusabile con pool; retry con backoff esponenziale su 5xx/timeout; wrappare tutte le eccezioni di rete in `DevBoardApiError`; collegare `handle_api_error` al CLI; **upload riprendibile** interrogando lo stato chunk lato server prima di ri-inviare; gestione errori in `load_credentials`. Il timeout dev'essere configurabile e più alto per i PUT grandi.

### P1.8 — Packaging Python rotto per install standalone

**Cosa succede.** `plugin` importa `devboard_analyzer` (`cli.py:488`, `mcp_tools.py:357`) ma **non lo dichiara come dipendenza**. Un `pip install devboard-plugin` pulito fallirebbe genesis/delta con `ModuleNotFoundError`. `pydantic` è dichiarato in entrambi i progetti ma **mai importato**. `plugin_version="0.1.0"` è hardcoded in 3 posti (e anche nel Node agent) senza single-source-of-truth.

**Cosa cambiare.** Aggiungere `devboard-analyzer` alle dipendenze del plugin; rimuovere `pydantic` o iniziare a usarlo per validare gli schemi (vedi P2). Derivare la versione da `importlib.metadata`. Questo conta molto perché il piano è **distribuire il plugin ad agenti esterni** — deve installarsi pulito.

---

## P2 — La visione: memoria che permette di trovare bug senza il sorgente

Questa è la parte più importante della guida, perché è la ragione d'esistere del progetto. Oggi la promessa è mantenuta **solo per bug già diagnosticati** (i causal pack Hades sono riproducibili da un secondo agente senza sorgente). Non è mantenuta per la **scoperta di bug latenti**, perché mancano tre livelli.

### Diagnosi: perché oggi non basta

1. **Il grafo non ha archi di chiamata risolti.** In `analyzer/code_graph.py:401-407` gli archi `CALLS` puntano solo a `external:<name>`: i nomi di chiamata non sono mai risolti agli ID dei simboli interni. Gli estrattori lightweight (tutto ciò che non è Python: JS/TS/PHP/Go/Java/Ruby) emettono **solo `DECLARES`**. Quindi non si può rispondere a "chi chiama questa funzione / cosa raggiunge questa funzione" — la spina dorsale dell'analisi d'impatto.
2. **La wiki non contiene semantica comportamentale.** L'analyzer emette una **singola pagina stub** ("Genesis Import...", `genesis_bundle.py:96-118`); il contenuto reale dev'essere scritto da un agente. Non ci sono doc a livello di funzione, pre/post-condizioni, invarianti, contratti, tipi, o "cosa fa questo endpoint".
3. **La ricerca è puramente lessicale.** Nessun embedding/vector/RAG (grep conferma zero occorrenze). E il "full-text" `hades_search_documents` usa `MATCH ... AGAINST` **solo su MySQL** (`HadesSearchDocumentIndexer.php:625-627`) mentre lo stack è **Postgres** → in produzione degrada sempre a `LIKE`. Un agente deve già conoscere l'identificatore esatto; non può recuperare "il codice responsabile del comportamento X" per significato.

In più, Neo4j è di fatto **write-only**: nessun percorso di query (plugin, Hades, o tool AI interno) emette Cypher. `QueryProjectGraphTool` ri-legge il JSON del grafo da disco e fa `stripos` su `json_encode(node)`. E la semantica è appiattita: tutti i nodi sono `CodeNode`, tutte le relazioni sono `RELATED{type:"..."}` (`GenesisGraphImportService.php:21-150`), quindi Cypher non può fare pattern-matching su `[:CALLS]`.

### La roadmap in quattro livelli

**Livello 1 — Grafo semantico risolto (la fondazione).**
- **Dove**: `analyzer/src/devboard_analyzer/code_graph.py`; modello Neo4j in `GenesisGraphImportService.php`.
- **Cosa**: (a) risolvere gli archi `CALLS` ai simboli interni (name resolution a due passate: prima indicizza tutti i simboli, poi risolvi i target); (b) portare l'estrazione non-Python oltre il regex — la dipendenza `graphify`/tree-sitter è già cablata in `code_graph.py:140` ma **non installata** e silenziosamente ignorata (`:148-158`): renderla una dipendenza reale sblocca call/import edge per JS/TS/PHP; (c) usare label Neo4j reali (`:Function`, `:Class`, `:File`) e tipi di relazione reali (`:CALLS`, `:IMPORTS`, `:DECLARES`) invece di appiattire tutto su `CodeNode`/`RELATED`.
- **Perché**: senza call/dataflow graph non esiste impact analysis, e senza quella non esiste bug detection.

**Livello 2 — Query reale sul grafo.**
- **Cosa**: far sì che qualcuno interroghi davvero Neo4j via Cypher. Esporre un MCP tool `devboard_query_graph` e/o un endpoint Hades che accetta query strutturate ("callers di X", "cosa raggiunge la tabella Y", "path da endpoint a query DB"). Oggi la traversata Hades è una BFS generica su un artifact JSON, profondità ≤3, limite ≤50 — insufficiente per ragionamento causale.
- **Perché**: la memoria dev'essere *interrogabile per struttura*, non solo dumpabile per recency (`shared-memory-pack` restituisce gli ultimi 50 entry senza filtro).

**Livello 3 — Contenuto comportamentale derivato (dove entra l'AI).**
- **Dove**: `app/Assistants/` (Laravel AI SDK è già integrato e ben incapsulato).
- **Cosa**: un flusso server-side che, a valle di Genesis/Delta, genera **wiki a livello di simbolo con evidence ref**: cosa fa una funzione, precondizioni, side-effect osservati dal grafo, contratti di I/O. Output strutturato + `source_status: ai_generated` + `needs_verification` finché un agente/umano non conferma. **Regola dura, già scritta nei doc**: l'output del modello dev'essere validato e non deve mutare stato senza approvazione.
- **Perché**: questo è ciò che rende la wiki "abbastanza completa da ragionare senza sorgente". È il collante tra grafo (struttura) e agente (ragionamento).

**Livello 4 — Recupero semantico (RAG con pgvector).**
- **Cosa**: aggiungere embedding + ricerca vettoriale. Lo stack è già Postgres → `pgvector` è la scelta naturale, e Laravel AI SDK supporta similarity-search su model Eloquent con embedding (motivo in più per P1.1). Indicizzare: wiki revision, summary di run, summary artifact, causal pack, memory entry. Cache degli embedding per costo/latenza.
- **Perché**: permette a un agente di recuperare "il codice/knowledge responsabile del comportamento X" per significato, non per keyword — il salto da "devi sapere il nome" a "descrivi il problema".

**Il completamento del ciclo — dalla scoperta alla libreria di pattern.**
Oggi `hades_causal_packs` sono knowledge riutilizzabile ma **per-progetto** e **popolata dall'agente che aveva il sorgente**. Il passo che realizza la tua visione "un qualunque agente rileva un bug": una **libreria di bug-pattern cross-progetto** (astrazioni di causal pack: "questo bug-class su questa struttura di grafo") contro cui matchare i grafi di progetti nuovi. Un agente esterno, con solo l'accesso alla memoria, matcha il pattern sul grafo del progetto target e segnala il bug latente — senza il sorgente. Questo richiede prima il multi-tenancy di P3.1 (per sapere cosa è condivisibile) e i Livelli 1–4.

### Unificare i due piani di memoria

Prima di costruire sopra, decidere la relazione tra piano Genesis/Plugin e piano Hades. Oggi hanno grafi separati (`CodeNode` in Neo4j vs `hades.code_graph.v1`/`hades.php_graph.v1` come artifact JSON), ricerca separata e API separate. Costruire i Livelli 1–4 due volte è insostenibile. **Raccomandazione**: un unico grafo canonico in Neo4j come source of truth per la struttura, con il piano Hades che lo interroga invece di mantenere un artifact JSON parallelo. Documentare la decisione (i doc `03`–`10` sono il contratto ma non menzionano Hades; serve un doc `13` che riconcili).

---

## P3 — Scala, multi-tenancy, consolidamento

### P3.1 — Multi-tenancy / isolamento organizzazione

**Cosa succede.** Nessuna tabella `organizations`/`workspaces`/`teams`. L'entità top è `projects` con `created_by_user_id` ma nessun owner-org né membership. Qualunque utente dashboard (secondo ruolo) vede tutti i progetti. I token plugin sono per-utente, **non** per-progetto (solo i bootstrap token Hades sono project-scoped). Per un backend "di un'organizzazione" con agenti su più progetti, questo è il gap strutturale.

**Cosa serve.** (a) `organizations` + `organization_user`; (b) `organization_id` FK su `projects` (cascata a repository/run/artifact/audit); (c) tenant-scoping su ogni query (molto più facile dopo P1.1 con global scope Eloquent — oggi impossibile perché si usa il query builder); (d) token plugin project/org-scoped; (e) partizionamento del grafo Neo4j per org.

### P3.2 — Performance su repo grandi

`analyzer/file_inventory.py:12` fa `sorted(base.rglob("*"))` materializzando ogni path in memoria e attraversando comunque `node_modules` prima di escluderlo; `safety.py:27` legge ogni file (anche binari) interamente in RAM; nessuna cache mtime/size per gli hash → sha256 dell'intero repo a ogni genesis **e** ogni delta. Rispettare `.gitignore`, escludere per prefisso durante il walk, cache hash incrementale.

### P3.3 — Secret scanning serio

`analyzer/safety.py` è due regole: nome `.env` e la stringa esatta `-----BEGIN PRIVATE KEY-----` (manca `BEGIN RSA/OPENSSH/EC PRIVATE KEY`). Nessuna entropia, nessun pattern per AWS/GitHub/JWT/connection string. Dato che questo è il gate che protegge dal caricare segreti nella memoria condivisa, va sostituito con un detector serio (regex + entropia, o integrare gitleaks/trufflehog come libreria).

### P3.4 — Consolidare legacy e duplicazioni

- **`ai-sandbox/`**: i doc stessi lo dichiarano transitorio/non-goal (`03_DOMAIN_MODEL.md:30`, `08_IMPLEMENTATION_STEPS.md:24`). Contiene `.venv/` committato e wheel Python **solo macOS-arm64** che rompono il bootstrap su Linux x64. Rimuovere `.venv/` e i wheel subito; pianificare il ritiro dopo aver confermato la migrazione dell'analyzer.
- **`tests/ai_sandbox_scripts/`**: mirror duplicato di `ai-sandbox/scripts/`. Ritirare insieme a `ai-sandbox`.
- **Node `agent/` vs Python `plugin/`**: il Node agent è un sottoinsieme stretto (solo auth/device/link) con costanti di protocollo duplicate a mano. Decidere: o promuoverlo al daemon locale previsto, o ripiegarlo nel plugin. Almeno: single-source per `PLUGIN_VERSION`/`PROTOCOL_VERSION`.
- **`docker-compose.graph.yaml`**: Neo4j legacy dell'era ai-sandbox, ridondante.
- **Drift documentale**: i doc `00`–`10` non descrivono più il sistema costruito (Hades, assistant, memory queue, quality center). Trattare `03`–`10` come contratto e scrivere un doc di riconciliazione dello stato reale.

---

## Piano d'azione consigliato (ordine di esecuzione)

1. **Settimana 1 — Chiudere P0.** Rimuovere secret committati e ruotarli; validazione ULID su `artifact_id`; cap sugli upload; HMAC per-device; `AuditLogger` centrale con eventi mancanti; separare deploy prod dal compose dev. Questi sbloccano in sicurezza la distribuzione di token ad agenti esterni.
2. **Settimane 2-6 — P1.1 + P1.3 + P1.5.** Model Eloquent per le entità più lette, FormRequest/Policies, interfaccia Neo4j e CI su Postgres. È la piattaforma su cui tutto il resto costa meno.
3. **In parallelo — P1.4, P1.7, P1.8.** Job grafo unificato, resilienza client Python, packaging plugin. Basso rischio, alto sollievo operativo.
4. **Mesi 2-4 — P2 Livelli 1-2.** Grafo semantico risolto + query Cypher reale. Prima di questo, decidere l'unificazione dei due piani di memoria.
5. **Mesi 3-6 — P2 Livelli 3-4.** Wiki comportamentale AI-generata + RAG pgvector. Qui la visione diventa dimostrabile.
6. **Continuo — P3.** Multi-tenancy quando serve il secondo cliente; performance quando arriva il primo repo grande; consolidamento legacy appena l'analyzer è confermato migrato.

---

## Nota finale da esperto

La cosa che colpisce di più di questo progetto non sono i difetti — sono normali per un sistema a questo stadio — ma la **qualità del pensiero architetturale nei doc** e la disciplina del principio "deterministico prima, LLM assiste ma non possiede". Quel principio è esattamente ciò che rende credibile una memoria condivisa su cui gli agenti possono fidarsi.

Il rischio numero uno non è tecnico: è **costruire il Livello 3-4 (AI, RAG) prima del Livello 1 (grafo risolto)**. Un RAG sopra una wiki-stub e un grafo senza call edge produrrebbe risposte plausibili e sbagliate — il peggior esito possibile per un sistema il cui valore è la *fiducia* che gli agenti ripongono nella memoria. Costruisci la struttura deterministica per prima; lascia che l'AI ragioni sopra fatti verificati. È già la filosofia dei tuoi doc: la guida qui sopra è, in fondo, un invito a onorarla fino in fondo.
