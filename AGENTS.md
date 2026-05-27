# AGENTS.md

Mandatory entrypoint for Codex and other LLMs working in this workspace.

## First Rule

Read `ai-sandbox/INIT.md` before modifying files.

## Hard Boundaries

- Do not modify `project/` during sandbox initialization unless the developer explicitly asks for a project code change.
- Do not generate wiki, graph, or architectural claims before `ai-sandbox/config/project.yaml` is initialized.
- Do not treat inferred facts as verified.

## Task Routing

Use `ai-sandbox/instructions/INDEX.md` to classify the task and follow the matching workflow.

## Logbooks

- Sandbox/tooling work: `ai-sandbox/logbooks/LOGBOOK_SANDBOX_IA.md`
- Project work: `ai-sandbox/logbooks/LOGBOOK_PROJECT.md`
