# Sandbox IA Logbook

<!-- Intended write paths for Remediation Task 2.5: ai-sandbox/config/project.yaml; .graphifyignore; ai-sandbox/scripts/audit_sandbox.py; tests/test_audit_sandbox.py; tests/test_init_sandbox.py; ai-sandbox/logbooks/LOGBOOK_SANDBOX_IA.md -->

Record sandbox framework, tooling, graph, wiki seed, and automation changes here.

### 2026-07-11 - DevBoard Project Orientation

**Request**: Inspect the existing codebase before receiving a follow-up implementation request.
**Context read**:
- `AGENTS.md`
- `ai-sandbox/INIT.md`
- `ai-sandbox/instructions/INDEX.md`
- `ai-sandbox/instructions/workflows/DISCOVERY.md`
- `ai-sandbox/instructions/policies/FILE_BOUNDARIES.md`
- `ai-sandbox/instructions/policies/DOCKER.md`
- `ai-sandbox/instructions/policies/LOGBOOKS.md`
- `ai-sandbox/config/project.yaml`
- `README.md`
- `docs/ai-devboard/*`

**Work performed**:
- Detected the local environment and bootstrapped the pinned Graphify dependency from the vendored wheelhouse.
- Refreshed the allowed discovery artifacts under `ai-sandbox/docs/`.
- Read the backend, plugin, analyzer, Docker, route, migration, frontend, and test layout to establish project orientation.

**Verification**:
- `python3 ai-sandbox/scripts/detect_environment.py` -> Linux/x86_64 host, Python 3.13.3, Docker linux/amd64.
- `python3 ai-sandbox/scripts/bootstrap_dependencies.py` -> passed; Graphify 0.8.19 available, no vendored Docker archives for linux-amd64.
- `python3 ai-sandbox/scripts/discover_project.py` -> passed; discovery artifacts written.
- Project code remained unchanged during onboarding.

**Files changed**:
- `ai-sandbox/config/environment.yaml` - refreshed environment snapshot.
- `ai-sandbox/config/dependencies.lock.yaml` - refreshed dependency lock.
- `ai-sandbox/docs/discovery.json` - refreshed discovery output.
- `ai-sandbox/docs/discovery.md` - refreshed discovery output.
- `ai-sandbox/logbooks/LOGBOOK_SANDBOX_IA.md` - this entry.

**Residual risks**:
- Docker images are pinned in Compose but not vendored for the detected platform; starting the stack may require image pulls.
- The workspace is a monorepo, so the generic discovery stack summary is less informative than the configured project stack and module-level inspection.

### 2026-07-10 05:38 - Dependency Bootstrap Verification

**Request**: Initialize the required sandbox workflow before a DevBoard seed operation.
**Context read**:
- `ai-sandbox/INIT.md`
- `ai-sandbox/instructions/INDEX.md`
- `ai-sandbox/config/project.yaml`
- `ai-sandbox/instructions/policies/FILE_BOUNDARIES.md`
- `ai-sandbox/instructions/policies/DOCKER.md`
- `ai-sandbox/instructions/policies/SOURCE_OF_TRUTH.md`
- `ai-sandbox/instructions/policies/LOGBOOKS.md`

**Work performed**:
- Ran environment detection successfully.
- Attempted the mandated vendored dependency bootstrap; made no sandbox changes after the wheel dependency failure.

**Verification**:
- `python3 ai-sandbox/scripts/detect_environment.py` -> Linux/x86_64 host and linux/amd64 Docker platform.
- `python3 ai-sandbox/scripts/bootstrap_dependencies.py` -> failed because the vendored Python wheelhouse has no compatible `rapidfuzz` wheel required by `graphifyy==0.8.19`.

**Files changed**:
- `ai-sandbox/logbooks/LOGBOOK_SANDBOX_IA.md` - recorded initialization verification.

**Residual risks**:
- Sandbox graph tooling remains unavailable until a compatible vendored `rapidfuzz` wheel is supplied.

### 2026-07-10 05:49 - Linux CPython 3.13 Graphify Wheelhouse

**Request**: Restore sandbox Python graph/discovery tooling on Linux x86_64 with Python 3.13 by supplying a compatible `rapidfuzz` dependency or permitting an external pull.

**Context read**:
- `ai-sandbox/INIT.md`
- `ai-sandbox/instructions/INDEX.md`
- `ai-sandbox/instructions/workflows/DISCOVERY.md`
- `ai-sandbox/instructions/workflows/GRAPH.md`
- `ai-sandbox/instructions/workflows/REFRESH.md`
- `ai-sandbox/instructions/graphify/RUNBOOK.md`
- `ai-sandbox/instructions/graphify/NEO4J.md`
- `ai-sandbox/instructions/policies/FILE_BOUNDARIES.md`
- `ai-sandbox/instructions/policies/LOGBOOKS.md`
- `ai-sandbox/instructions/policies/DOCKER.md`
- `ai-sandbox/instructions/policies/SOURCE_OF_TRUTH.md`
- `ai-sandbox/scripts/bootstrap_dependencies.py`
- `ai-sandbox/vendor/README.md`
- `ai-sandbox/config/project.yaml`

**Work performed**:
- Vendored `rapidfuzz-3.13.0-cp313-cp313-manylinux_2_17_x86_64.manylinux2014_x86_64.whl`.
- Added the Linux x86_64 CPython 3.13 NumPy, SciPy, Tree-sitter, and language-parser wheels also required by Graphify; the existing variants were macOS-only.
- Kept the bootstrap offline through `--no-index`; no runtime external pull was enabled.
- Documented the platform-specific wheelhouse and refreshed the generated dependency lock.

**Verification**:
- `python3 ai-sandbox/scripts/detect_environment.py` -> Linux/x86_64 host, Python 3.13.3, and linux/amd64 Docker platform.
- `python3 -m venv /tmp/opencode/graphify-wheelhouse-test && .../pip install --no-index --find-links ai-sandbox/vendor/python/wheels graphifyy==0.8.19` -> installed successfully from local wheels only; `graphify --version` returned `graphify 0.8.19` and `pip check` passed.
- `python3 ai-sandbox/scripts/bootstrap_dependencies.py` -> passed and wrote `ai-sandbox/config/dependencies.lock.yaml`.
- `python3 ai-sandbox/scripts/discover_project.py` -> passed and wrote discovery artifacts.
- `python3 ai-sandbox/scripts/audit_sandbox.py` -> graph artifact still absent, unrelated to Python dependency resolution.

**Files changed**:
- `ai-sandbox/vendor/python/wheels/*manylinux*x86_64*.whl` - Linux x86_64 CPython 3.13 Graphify dependency set.
- `ai-sandbox/vendor/README.md` - documented supported wheelhouse platforms and offline requirement.
- `ai-sandbox/config/dependencies.lock.yaml` - refreshed by the successful bootstrap.
- `ai-sandbox/logbooks/LOGBOOK_SANDBOX_IA.md` - this entry.

**Residual risks**:
- Graph refresh and audit remain blocked by the separate graph configuration issue: `graph.ast_root` is `project`, but `/home/ubuntu/dev-sandbox/project` does not exist.
- No Docker archives are vendored for linux/amd64; this did not affect the Python wheelhouse verification.

## Template

```markdown
### YYYY-MM-DD HH:MM - Title

**Request**: ...
**Context read**:
- `path/file`

**Work performed**:
- ...

**Verification**:
- `command` -> result

**Files changed**:
- `path/file` - reason

**Residual risks**:
- ...
```

### 2026-06-17 11:20 - Local Delegate Plugins

**Request**: Create two local Codex plugins that delegate manual worker tasks to local Ollama and Gemini CLIs while Codex remains director and verifier.
**Context read**:
- `ai-sandbox/INIT.md`
- `ai-sandbox/instructions/INDEX.md`
- `ai-sandbox/config/project.yaml`
- `ai-sandbox/instructions/policies/FILE_BOUNDARIES.md`
- `ai-sandbox/instructions/policies/DOCKER.md`
- `ai-sandbox/instructions/policies/SOURCE_OF_TRUTH.md`
- `ai-sandbox/instructions/policies/LOGBOOKS.md`
- `/Users/gabriele/.codex/skills/.system/plugin-creator/SKILL.md`
- `/Users/gabriele/.codex/skills/.system/plugin-creator/references/plugin-json-spec.md`
- `/Users/gabriele/.codex/skills/.system/plugin-creator/references/installing-and-updating.md`

**Work performed**:
- Created personal marketplace-backed plugins `ollama-delegate` and `gemini-delegate`.
- Added MCP stdio wrappers for local `ollama` and `gemini` CLI delegation.
- Added plugin skills that instruct Codex to treat delegate output as unverified worker output.
- Installed both plugins with `codex plugin add <plugin>@personal`.

**Verification**:
- `python3 -m py_compile /Users/gabriele/plugins/ollama-delegate/scripts/ollama_delegate_mcp.py /Users/gabriele/plugins/gemini-delegate/scripts/gemini_delegate_mcp.py` -> passed.
- `PYTHONPATH=<temporary PyYAML target> python3 scripts/validate_plugin.py /Users/gabriele/plugins/ollama-delegate` -> passed.
- `PYTHONPATH=<temporary PyYAML target> python3 scripts/validate_plugin.py /Users/gabriele/plugins/gemini-delegate` -> passed.
- `PYTHONPATH=<temporary PyYAML target> python3 scripts/validate_plugin.py /Users/gabriele/.codex/plugins/cache/personal/ollama-delegate/0.1.0` -> passed.
- `PYTHONPATH=<temporary PyYAML target> python3 scripts/validate_plugin.py /Users/gabriele/.codex/plugins/cache/personal/gemini-delegate/0.1.0` -> passed.
- MCP `initialize` and `tools/list` checks against both installed cache server scripts -> passed.
- `codex plugin list` -> both `ollama-delegate@personal` and `gemini-delegate@personal` are `installed, enabled`.

**Files changed**:
- `/Users/gabriele/plugins/ollama-delegate/.codex-plugin/plugin.json` - plugin metadata.
- `/Users/gabriele/plugins/ollama-delegate/.mcp.json` - MCP server registration.
- `/Users/gabriele/plugins/ollama-delegate/scripts/ollama_delegate_mcp.py` - local Ollama MCP wrapper.
- `/Users/gabriele/plugins/ollama-delegate/skills/ollama-delegate/SKILL.md` - Codex usage workflow.
- `/Users/gabriele/plugins/gemini-delegate/.codex-plugin/plugin.json` - plugin metadata.
- `/Users/gabriele/plugins/gemini-delegate/.mcp.json` - MCP server registration.
- `/Users/gabriele/plugins/gemini-delegate/scripts/gemini_delegate_mcp.py` - local Gemini MCP wrapper.
- `/Users/gabriele/plugins/gemini-delegate/skills/gemini-delegate/SKILL.md` - Codex usage workflow.
- `/Users/gabriele/.agents/plugins/marketplace.json` - personal marketplace entries.
- `/Users/gabriele/.codex/plugins/cache/personal/ollama-delegate/0.1.0/**` - installed plugin cache.
- `/Users/gabriele/.codex/plugins/cache/personal/gemini-delegate/0.1.0/**` - installed plugin cache.
- `ai-sandbox/logbooks/LOGBOOK_SANDBOX_IA.md` - this log entry.

**Residual risks**:
- `gemini` is not visible in Codex's non-interactive PATH; set `GEMINI_CLI` if the executable is only available in your interactive shell.
- `ollama list` returned no installed models; pass `model` to `ollama_delegate` or set `OLLAMA_DELEGATE_MODEL` after installing a model.
- Newly installed plugin tools are normally picked up in a new Codex thread.

### 2026-06-30 20:12 - Coordination Prep Discovery Refresh

**Request**: Prepare for a frontend-agent coordination discussion by inspecting initialized sandbox/project state and current Markdown sources.
**Context read**:
- `ai-sandbox/INIT.md`
- `ai-sandbox/instructions/INDEX.md`
- `ai-sandbox/config/project.yaml`
- `ai-sandbox/instructions/policies/FILE_BOUNDARIES.md`
- `ai-sandbox/instructions/policies/LOGBOOKS.md`
- `ai-sandbox/instructions/policies/DOCKER.md`
- `ai-sandbox/instructions/policies/SOURCE_OF_TRUTH.md`
- `ai-sandbox/instructions/workflows/DISCOVERY.md`
- `ai-sandbox/scripts/discover_project.py`

**Work performed**:
- Ran environment detection.
- Attempted dependency bootstrap as required by INIT.
- Ran lightweight project discovery to refresh `ai-sandbox/docs/discovery.json` and `ai-sandbox/docs/discovery.md`.

**Verification**:
- `python3 ai-sandbox/scripts/detect_environment.py` -> passed; reported Linux/x86_64 and Docker linux/amd64.
- `python3 ai-sandbox/scripts/bootstrap_dependencies.py` -> failed because the vendored wheel set lacks `rapidfuzz` for `graphifyy==0.8.19`.
- `python3 ai-sandbox/scripts/discover_project.py` -> passed; wrote discovery artifacts.
- `git check-ignore -v ai-sandbox/docs/discovery.md ai-sandbox/docs/discovery.json` -> confirmed discovery artifacts are gitignored.
- `git diff --check -- ai-sandbox/docs/discovery.md ai-sandbox/docs/discovery.json ai-sandbox/logbooks/LOGBOOK_PROJECT.md ai-sandbox/logbooks/LOGBOOK_SANDBOX_IA.md` -> passed for tracked changes.
- `rg -n "[ \t]+$" ai-sandbox/docs/frontend-agent-coordination-2026-06-30.md ai-sandbox/logbooks/LOGBOOK_PROJECT.md ai-sandbox/logbooks/LOGBOOK_SANDBOX_IA.md` -> no trailing whitespace.

**Files changed**:
- `ai-sandbox/docs/discovery.json` - refreshed lightweight discovery artifact.
- `ai-sandbox/docs/discovery.md` - refreshed lightweight discovery artifact.
- `ai-sandbox/logbooks/LOGBOOK_SANDBOX_IA.md` - this log entry.

**Residual risks**:
- Discovery output is rough because the script includes `ai-sandbox/.venv` in sample files.
- Dependency bootstrap remains blocked until a compatible `rapidfuzz` wheel is vendored or the bootstrap strategy changes.

## 2026-07-10 - Remediation Task 2.5

- Request: execute Task 2.5 from `docs/superpowers/plans/2026-07-10-devboard-operational-hardening-remediation.md`.
- Intended write paths: `ai-sandbox/config/project.yaml`, `.graphifyignore`, `ai-sandbox/scripts/audit_sandbox.py`, `tests/test_audit_sandbox.py`, `tests/test_init_sandbox.py`, `ai-sandbox/logbooks/LOGBOOK_SANDBOX_IA.md`.
- RED verification: `python3 -m pytest -q tests/test_audit_sandbox.py tests/test_init_sandbox.py` -> failed as expected with 5 failures: root `.` audit signature/handling, missing-root validation, `.graphifyignore` root dependency/cache exclusions, workspace-root source filtering, and duplicate `environment:` block.
- Work performed: set `graph.ast_root` to `.`, removed duplicate tracked `environment:` facts, kept Neo4j credentials out of tracked project config, replaced obsolete `project/**` Graphify ignores with root-level generated/dependency/cache paths, and updated sandbox audit validation for workspace-root graphs while preserving non-root prefix checks.
- GREEN verification: `python3 -m pytest -q tests/test_audit_sandbox.py tests/test_init_sandbox.py` -> 10 passed; `python3 ai-sandbox/scripts/detect_environment.py` -> passed and reported Linux/x86_64 with Docker linux/amd64; `python3 ai-sandbox/scripts/bootstrap_dependencies.py` -> passed and wrote dependency lock; `python3 ai-sandbox/scripts/discover_project.py` -> passed and wrote discovery artifacts; `python3 ai-sandbox/scripts/refresh_graph.py` -> passed for `.` with 4927 nodes and 6873 edges after clustering; `python3 ai-sandbox/scripts/audit_sandbox.py` -> Sandbox audit passed; `git diff --check` -> passed.
- Files changed: `ai-sandbox/config/project.yaml`, `.graphifyignore`, `ai-sandbox/scripts/audit_sandbox.py`, `tests/test_audit_sandbox.py`, `tests/test_init_sandbox.py`, `ai-sandbox/logbooks/LOGBOOK_SANDBOX_IA.md`.
- Residual risks: graph refresh generated an untracked `ai-sandbox/graph/GRAPH_REPORT.md` as a verification side effect and it was removed to honor the task's allowed write boundary; generated ignored discovery/environment/dependency artifacts may have been refreshed by the required gate commands.
