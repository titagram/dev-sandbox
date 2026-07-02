# DevBoard Backend Blockers Implementation - 2026-07-01

## Esito

Implementati i blocchi backend approvati nel report `devboard-backend-blockers-report-2026-07-01.md` senza toccare il frontend.

## Route aggiunte o estese

- `GET /api/dashboard/projects/{project}` ora espone `links.wiki` e `links.wiki_api`.
- `POST /api/dashboard/projects/{project}/memory` salva manual memory con `source = user_inserted`.
- `GET /api/dashboard/projects/{project}/workspace-bindings`.
- `GET /api/dashboard/projects/{project}/wiki/refresh-requests`.
- `POST /api/dashboard/projects/{project}/wiki/refresh-requests`.
- `GET /api/dashboard/projects/{project}/memory/imports`.
- `POST /api/dashboard/projects/{project}/memory/imports`.
- `GET /api/dashboard/projects/{project}/memory/imports/{batch}`.
- `POST /api/hades/v1/memory/import-bundles`.
- `POST /api/dashboard/admin/ai-model-profiles`.
- `POST /api/dashboard/admin/ai-model-providers/{provider}/validate`.

## Contratti implementati

- Wiki discoverability: il payload progetto include link UI/API per la wiki.
- Wiki refresh: il dashboard crea job Hades `populate_project_wiki` con payload `devboard.wiki_refresh_request.v1`, binding e repository project-scoped. Il result Hades `devboard.wiki_refresh_result.v1` viene applicato tramite `WikiRevisionService` e marcato con `result_applied_at`.
- Manual memory: le entry inserite da dashboard usano `source = user_inserted`; lo snapshot Hades le include preservando il source.
- OpenCode Go: aggiunto preset provider `opencode_go`; le API admin possono configurare provider, creare model profile e validare con probe reale/redatto verso OpenCode Go (`/models` + mini `chat/completions`) senza esporre segreti.
- Memory import: aggiunti batch e item di import. Dashboard e Hades bundle creano proposte `hades_memory_proposals` pending, deduplicate per `source_hash` tramite `local_proposal_id = memory-import:{source_hash}`. Cross-project vietato da token/binding validation.

## Migrazioni e servizi

- `2026_07_01_000006_create_memory_import_batches_and_extend_hades_jobs.php`
  - estende `hades_agent_jobs` con `repository_id`, `requested_by_user_id`, `job_type`, `result_applied_at`;
  - crea `memory_import_batches`;
  - crea `memory_import_items`.
- `2026_07_01_000007_ensure_opencode_go_provider.php`
  - crea il provider `opencode_go` se assente;
  - imposta `https://opencode.ai/zen/go/v1` come base URL default se vuota;
  - non tocca eventuali chiavi gia' salvate.
- `MemoryImportService` centralizza batch, dedupe e proposal creation.
- `WikiRefreshResultService` valida/applica result wiki Hades.

## Test eseguiti

Comando:

```bash
docker exec devboard-app-1 sh -lc 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test tests/Feature/Dashboard/DashboardApiContractTest.php tests/Feature/Dashboard/ProjectMemoryDashboardApiTest.php tests/Feature/Hades/HadesM3SharedMemoryTest.php tests/Feature/Dashboard/AiAgentRegistryDashboardTest.php tests/Feature/Dashboard/WikiRefreshDashboardApiTest.php tests/Feature/Dashboard/MemoryImportDashboardApiTest.php --display-warnings'
```

Risultato aggiornato dopo l'allineamento frontend/backend: `33 passed (328 assertions)`.

Pint dry-run:

```bash
docker exec devboard-app-1 sh -lc './vendor/bin/pint --test app/Assistants/AiAgentRegistry.php app/Dashboard/DashboardApiReader.php app/Http/Controllers/Dashboard/Api/DashboardAiAgentController.php app/Http/Controllers/Dashboard/Api/DashboardHadesController.php app/Http/Controllers/Dashboard/Api/DashboardMemoryController.php app/Http/Controllers/Dashboard/Api/DashboardMemoryImportController.php app/Http/Controllers/Dashboard/Api/DashboardWikiRefreshController.php app/Http/Controllers/Dashboard/Api/DashboardWorkspaceBindingController.php app/Http/Controllers/Hades/AgentJobResultController.php app/Http/Controllers/Hades/MemoryImportBundleController.php app/Services/Hades/HadesCapabilityPolicy.php app/Services/MemoryImportService.php app/Services/WikiRefreshResultService.php routes/api.php routes/web.php database/seeders/DevBoardSeeder.php database/migrations/2026_07_01_000006_create_memory_import_batches_and_extend_hades_jobs.php tests/Feature/Dashboard/AiAgentRegistryDashboardTest.php tests/Feature/Dashboard/DashboardApiContractTest.php tests/Feature/Dashboard/ProjectMemoryDashboardApiTest.php tests/Feature/Dashboard/MemoryImportDashboardApiTest.php tests/Feature/Dashboard/WikiRefreshDashboardApiTest.php tests/Feature/Hades/HadesM3SharedMemoryTest.php'
```

Risultato: `PASS (23 files)`.

## Verifiche live post-deploy

- Migrazioni live applicate: `2026_07_01_000006...` e `2026_07_01_000007...`.
- Cache Laravel pulita con `php artisan optimize:clear`.
- Queue restart segnalato con `php artisan queue:restart`.
- Provider `opencode_go` presente nel DB live con base URL ufficiale; al momento e' disabilitato e senza API key salvata.
- Probe OpenCode Go da backend con key fittizia in transazione rollback: raggiunge `/models`, seleziona `glm-5.2`, raggiunge `/chat/completions` e riceve correttamente `401` per token non valido. Questo conferma connettivita' rete/API; la validazione `ready_for_runtime` richiede una key reale salvata.

## Limiti residui

- La validazione OpenCode Go ora esegue un probe reale. Non e' stato possibile ottenere `ready_for_runtime` live perche' non esiste una API key OpenCode Go salvata nel DB o in environment.
- L'import memoria non accetta direttamente entry accepted: crea solo proposal pending, come richiesto. La review/merge effettiva resta nel workflow memory proposal esistente.
- Wiki refresh crea job Hades e applica result; il frontend ora espone richiesta/stato tramite la pagina wiki.
