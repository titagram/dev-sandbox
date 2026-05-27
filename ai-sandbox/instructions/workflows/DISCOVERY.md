# DISCOVERY.md

Use when mapping a project without modifying project code.

## Required Reads

- `ai-sandbox/config/project.yaml`
- `ai-sandbox/instructions/policies/FILE_BOUNDARIES.md`
- `ai-sandbox/instructions/policies/SOURCE_OF_TRUTH.md`

## Allowed Writes

- `ai-sandbox/docs/discovery.json`
- `ai-sandbox/docs/discovery.md`
- `ai-sandbox/logbooks/LOGBOOK_SANDBOX_IA.md`

## Commands

```bash
python3 ai-sandbox/scripts/detect_environment.py
python3 ai-sandbox/scripts/discover_project.py
```

## Completion

- Discovery artifacts exist.
- Project files are unchanged.
- Unverified findings are marked `needs_verification` or `inferred`.
