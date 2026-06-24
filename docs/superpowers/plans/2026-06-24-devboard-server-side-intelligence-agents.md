# DevBoard Server-Side Intelligence Agents Plan

**Purpose:** define server-side predictive assistants that suggest and explain, without autonomous mutation.

## Decisions

- `developer_provided`: DevBoard should include configurable server-side predictive assistants.
- `developer_provided`: assistants provide intelligence, questions, explanations, and suggestions, not autonomous domain mutations.
- `developer_provided`: first assistants are Task Clarifier, Backlog Triage Assistant, Wiki Query, and Socrate.
- `developer_provided`: Task Clarifier always requires explicit PM approval before updating task or Kanban data.
- `developer_provided`: Wiki Query includes wiki chat and freshness assistant behavior.
- `developer_provided`: Socrate is the generic project chat assistant.
- `verified_from_code`: DevBoard already stores project-scoped tasks, wiki pages/revisions, runs, artifacts, repositories, snapshots, and graph import state.

## Assistants

### Task Clarifier

Reads a task draft and project context, asks the PM follow-up questions, then proposes refined title, description, acceptance criteria, risks, dependencies, labels, and test hints.

It does not update the task until the PM explicitly approves.

### Backlog Triage Assistant

Reviews backlog for vague tasks, duplicates, oversized work, missing owners, stale blocked work, inconsistent priorities, and missing acceptance criteria.

It emits recommendations only.

### Wiki Query

Provides chat over project wiki with source-aware answers, references, and freshness warnings.

It can propose wiki updates or identify stale/conflicting pages, but does not write wiki revisions without approval.

### Socrate

Provides broader project-level chat over DevBoard-held project intelligence: wiki, tasks, runs, quality reports, test results, artifact metadata, AST/code graph data, and analyzer outputs.

Socrate is read-only by default and must honor project code exposure policies.

## Platform Requirements

- Admin/System panel for model/provider/runtime configuration.
- Agent registry separate from model registry.
- OpenAI-compatible HTTP provider support.
- Local/CLI provider support.
- Future opencode-style connector only if it exposes a stable integration surface.
- Assistant outputs persisted as suggestions, drafts, messages, or analysis records before any domain mutation.

## Safety Rules

- project-scoped by default;
- respect role permissions and project policy;
- record prompt context metadata, selected model/runtime profile, actor, target entity, output, and approval state;
- preserve rejected, accepted, and superseded suggestions for audit;
- no assistant may execute local scans, mutate code, trigger risky operations, or update project data without approval.

## Spec Gaps

- provider registry schema;
- model/runtime registry schema;
- assistant run, message, and suggestion tables;
- approval workflow and UI;
- context packing and evidence citation rules;
- rate, quota, and cost limits.

