# Paste-Ready Prompt: DevBoard Git Workspace Sync Slice

Use this prompt in a fresh Codex session.

```text
Lavora nel repository DevBoard in /home/ubuntu/dev-sandbox.

Prima di modificare qualunque file leggi obbligatoriamente:
1. AGENTS.md
2. ai-sandbox/INIT.md
3. ai-sandbox/instructions/INDEX.md
4. ai-sandbox/config/project.yaml
5. tutti i file in ai-sandbox/instructions/policies/
6. ai-sandbox/logbooks/LOGBOOK_PROJECT.md
7. docs/superpowers/plans/2026-06-24-devboard-masterplan.md
8. docs/superpowers/plans/2026-06-24-devboard-project-kickstart.md
9. docs/superpowers/plans/2026-06-24-devboard-local-agent.md
10. docs/superpowers/plans/2026-06-25-devboard-git-workspace-sync.md

Task corrente:
implementa il piano docs/superpowers/plans/2026-06-25-devboard-git-workspace-sync.md.

Obiettivo:
costruire il primo slice live-testable di Git/workspace state sync. Un workspace locale gia' linkato deve poter aggiornare in DevBoard branch, HEAD, dirty state, remote host/hash, upstream, ahead/behind e observed_at tramite local agent, senza che DevBoard backend cloni o legga il repository target.

Vincoli non negoziabili:
- la UI browser deve usare solo /api/dashboard/...;
- /api/plugin/v1 e' riservato a plugin CLI/MCP/local agent;
- DevBoard backend e' control plane e non deve contenere o clonare il sorgente dei progetti target;
- Git state remoto riportato dall'agent e' local_agent_reported, non remote truth verificata dal server;
- non salvare raw remote URL, perche' puo' contenere token o informazioni sensibili; salva host e sha256 hash;
- questo slice non deve implementare daemon/watch loop, Git hooks, job leases, Genesis/Delta dal Node agent, ne' il graph UX.

Prima di editare:
- registra i path previsti in ai-sandbox/logbooks/LOGBOOK_PROJECT.md;
- conferma brevemente cosa hai letto, prossimo task, file che toccherai, comandi di verifica e rischi.

Implementazione attesa:
1. Backend:
   - migration per aggiungere a local_workspaces:
     remote_name, remote_url_host, remote_url_hash, upstream_branch, ahead_count, behind_count, git_state_observed_at;
   - estendi RegisterLocalWorkspaceController per accettare e aggiornare questi campi sull'endpoint esistente POST /api/plugin/v1/repositories/{repository}/local-workspaces;
   - estendi DashboardApiReader::localWorkspaceState() per esporre questi campi con source_truth = local_agent_reported;
   - aggiungi test backend dedicato, preferibilmente backend/tests/Feature/PluginGitWorkspaceStateTest.php.

2. Agent Node:
   - estendi agent/src/probe.js con remote host/hash sanitizzato, upstream e ahead/behind usando git locale, senza fetch di rete;
   - aggiungi comando agent/bin/devboard-agent.js refresh-workspace che riusa l'endpoint di link/update workspace;
   - aggiorna test agent.

3. Frontend React/emergent:
   - aggiorna LocalWorkspace in /home/ubuntu/emergent_devboard_frontend/frontend/src/types/devboard.ts;
   - aggiorna ProjectDetailPage per mostrare branch, dirty state, remote host, upstream, ahead/behind e observed_at;
   - mantieni la UI su /api/dashboard/...;
   - aggiorna mock/test se necessario.

Verifiche minime:
- docker exec devboard-app-1 sh -lc 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test tests/Feature/PluginGitWorkspaceStateTest.php tests/Feature/Dashboard/ProjectKickstartDashboardApiTest.php tests/Feature/Dashboard/MultiprojectDashboardApiTest.php --display-warnings'
- cd agent && npm test
- node --check agent/bin/devboard-agent.js
- node --check agent/src/client.js
- node --check agent/src/probe.js
- cd /home/ubuntu/emergent_devboard_frontend/frontend && npm test -- --runTestsByPath src/api/httpApi.test.ts --watchAll=false
- cd /home/ubuntu/emergent_devboard_frontend/frontend && npm run build
- cd /home/ubuntu/dev-sandbox && git diff --check
- cd /home/ubuntu/emergent_devboard_frontend/frontend && git diff --check

Se deployi sul temporaneo pubblico Traefik, usa sempre:
DEVBOARD_APP_KEY='base64:<redacted-rotated-app-key>' docker compose -f docker-compose.devboard.yaml -f docker-compose.devboard.traefik.yaml up -d --build app worker frontend

<!-- credential rotated 2026-07-10 per remediation Task 0.2; value redacted -->

Alla fine aggiorna ai-sandbox/logbooks/LOGBOOK_PROJECT.md con lavoro svolto, verifiche, eventuale smoke live e rischi residui.

Procedi splittando il lavoro in subagenti quando utile, come al solito.
```

