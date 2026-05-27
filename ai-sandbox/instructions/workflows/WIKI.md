# WIKI.md

Use when creating or updating sandbox wiki documentation.

## Required Reads

- `ai-sandbox/config/project.yaml`
- `ai-sandbox/instructions/policies/SOURCE_OF_TRUTH.md`
- `ai-sandbox/wiki/AUDIT.md`

## Allowed Writes

- `ai-sandbox/wiki/**`
- `ai-sandbox/logbooks/**`

## Commands

```bash
python3 ai-sandbox/scripts/generate_wiki_seed.py
```

## Completion

- Claims are labeled as `verified_from_code`, `developer_provided`, `inferred`, or `needs_verification`.
- Logbooks are updated.
