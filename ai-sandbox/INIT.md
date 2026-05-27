# INIT.md

This file is mandatory for first startup.

## First Startup Procedure

1. Read `ai-sandbox/instructions/INDEX.md`.
2. Run environment detection:
   ```bash
   python3 ai-sandbox/scripts/detect_environment.py
   ```
3. Bootstrap vendored dependencies for the detected Docker platform:
   ```bash
   python3 ai-sandbox/scripts/bootstrap_dependencies.py
   ```
4. Inspect `ai-sandbox/config/project.yaml`.
5. If `project.initialized` is not `true`, ask questions from `instructions/interview/INTERVIEW.md`.
6. If the project is new, continue with `instructions/interview/NEW_PROJECT.md`.
7. If the project is existing, continue with `instructions/interview/EXISTING_PROJECT.md`.
8. Write developer answers to `ai-sandbox/config/project.yaml`.
9. Run discovery only after the required answers are present.

## Required Developer Answers

- Project name.
- Project root.
- New or existing project.
- Protected paths.
- Source of truth.
- Start, test, and build commands.
- Docker requirement.
- Neo4j port or credential overrides.
