# REFRESH.md

Use when updating generated sandbox artifacts.

## Required Reads

- `ai-sandbox/config/project.yaml`
- `ai-sandbox/instructions/policies/FILE_BOUNDARIES.md`
- `ai-sandbox/instructions/graphify/RUNBOOK.md`

## Commands

```bash
python3 ai-sandbox/scripts/detect_environment.py
python3 ai-sandbox/scripts/discover_project.py
python3 ai-sandbox/scripts/refresh_graph.py
python3 ai-sandbox/scripts/audit_sandbox.py
```

## Completion

- Audit passes or known failures are recorded.
- `project/` has no unexpected changes.
- `ai-sandbox/logbooks/LOGBOOK_SANDBOX_IA.md` is updated.
