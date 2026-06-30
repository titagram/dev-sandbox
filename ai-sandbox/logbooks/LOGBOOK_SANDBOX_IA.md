# Sandbox IA Logbook

Record sandbox framework, tooling, graph, wiki seed, and automation changes here.

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
