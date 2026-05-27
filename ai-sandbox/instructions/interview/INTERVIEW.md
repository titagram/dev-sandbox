# INTERVIEW.md

Ask one question at a time and write answers into `ai-sandbox/config/project.yaml`.

1. What is the project name?
2. Is this a new project or an existing project?
3. What path contains the real project code?
4. Which paths must never be modified?
5. Which paths are vendor, cache, build, uploads, dumps, generated files, or secrets?
6. How do you start the project locally?
7. Which test commands are reliable?
8. Which build commands are reliable?
9. What is the current source of truth?
10. Which architectural/style rules must be preserved?
11. Which areas are high regression risk?
12. Are the default Neo4j ports, user, and password acceptable for this workspace?

Do not infer missing answers when they affect file safety, destructive operations, data handling, or production-like commands.
