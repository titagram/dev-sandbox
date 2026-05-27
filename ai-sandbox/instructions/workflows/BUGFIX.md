# BUGFIX.md

Use when fixing behavior in the target project.

## Required Reads

- `ai-sandbox/config/project.yaml`
- `ai-sandbox/wiki/README.md`
- `ai-sandbox/wiki/AUDIT.md`
- `ai-sandbox/instructions/policies/FILE_BOUNDARIES.md`
- `ai-sandbox/instructions/policies/LOGBOOKS.md`

## Process

1. Identify affected files, routes, models, templates, commands, and side effects.
2. Search all usages with `rg`.
3. Write or identify a regression test when feasible.
4. Make the smallest coherent project change.
5. Run configured test commands.
6. Update wiki and `LOGBOOK_PROJECT.md`.

## Completion

- The bug is verified fixed or remaining uncertainty is documented.
- Regression risk and skipped checks are recorded.
