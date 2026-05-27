# FILE_BOUNDARIES.md

During sandbox initialization, allowed write paths are:

- `AGENTS.md`
- `ai-sandbox/**`

Project write paths are allowed only during explicit project work after `project.yaml` is initialized.

Before any project write:

1. Read `ai-sandbox/config/project.yaml`.
2. Check `project.protected_paths`.
3. Confirm the task is not onboarding-only.
4. Record the intended write paths in the relevant logbook.
