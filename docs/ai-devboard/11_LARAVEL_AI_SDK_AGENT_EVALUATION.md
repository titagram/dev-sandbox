# Laravel AI SDK Agent Evaluation For DevBoard

## Purpose

`developer_provided`: DevBoard needs to evaluate Laravel-native agent support for future server-side assistants and agent orchestration.

This document records what is relevant from the official Laravel 13 AI SDK documentation for future DevBoard use. It is a documentation note only. It does not install `laravel/ai`, add provider credentials, add migrations, or change runtime behavior.

Primary source:

- Official Laravel documentation: https://laravel.com/docs/13.x/ai-sdk
- Read date: 2026-06-28

## Source Status

- `verified_from_code`: DevBoard currently runs Laravel 13 and has a backend control-plane architecture, based on `ai-sandbox/config/project.yaml` and existing project docs.
- `developer_provided`: the developer asked to evaluate Laravel-side agent technology, with specific attention to LangChain / LangGraph-style usage.
- `needs_verification`: Laravel AI SDK details below come from official external documentation and still need verification against an installed package version inside this repository before implementation.
- `needs_verification`: the official Laravel AI SDK page does not contain textual references to `LangChain` or `LangGraph` as of the read date. Treat Laravel AI SDK as a separate Laravel-native agent abstraction, not as confirmed Laravel LangChain / LangGraph support.

## Executive Summary

Laravel AI SDK is relevant for DevBoard server-side assistants because it provides first-class PHP agent classes, tool calling, MCP tool integration, sub-agents, structured output, queueing, streaming, provider failover, embeddings, vector search, fake/assert test APIs, and AI lifecycle events.

It is not a replacement for the local Node agent or Python plugin/analyzer. DevBoard's current boundary remains valid:

- browser UI uses `/api/dashboard/...`;
- local plugin / local agent uses `/api/plugin/v1/...`;
- backend must not clone or read target source repositories by default;
- target repository execution stays near the target code through local plugin/agent paths.

The most plausible DevBoard use is server-side intelligence over DevBoard-held state: Task Clarifier, Backlog Triage, Wiki Query, Socrate, and Watchman suggestions. The SDK should not be used to let the server execute repository scans, shell commands, Git operations, or source-code mutations.

## Laravel AI SDK Building Blocks

### Installation

`needs_verification`: official docs install the package with Composer:

```bash
composer require laravel/ai
```

The docs then publish config and migrations through the package service provider and run migrations. The generated database tables include conversation storage for agents.

DevBoard implication:

- add the package only in an implementation slice;
- inspect generated migrations before running them;
- decide whether SDK conversation tables are acceptable as-is or whether DevBoard needs its own `assistant_runs`, `assistant_messages`, and `assistant_suggestions` schema;
- store provider keys only in environment/config, never in database rows, audit logs, prompt logs, or artifacts.

### Provider Support

`needs_verification`: the docs list broad provider support for text, images, audio, transcription, embeddings, reranking, files, and vector stores. Text providers include OpenAI, Anthropic, Gemini, Azure, Bedrock, Groq, xAI, DeepSeek, Mistral, Ollama, and OpenRouter. Embeddings and reranking have separate provider lists.

DevBoard implication:

- the Admin/System model-provider registry can map well to Laravel SDK provider configuration;
- use explicit provider and model names for repeatability;
- avoid automatic cheapest/smartest model attributes for auditable flows unless we also persist the resolved model and cost expectations.

### Custom Base URLs

`needs_verification`: providers can be configured with custom base URLs for proxy/gateway use.

DevBoard implication:

- useful for LiteLLM, corporate gateways, Azure OpenAI Gateway, or private routing;
- config should support per-provider base URL, timeout, and failover order;
- never expose provider secrets to browser payloads or MCP responses.

## Agents

### Agent Classes

`needs_verification`: an agent is a PHP class that can define:

- instructions;
- conversation context;
- tools;
- structured output schema;
- provider/model/runtime configuration.

The docs expose an Artisan generator:

```bash
php artisan make:agent AgentName
php artisan make:agent AgentName --structured
```

DevBoard candidate agents:

- `TaskClarifierAgent`: turns vague PM tasks into suggested acceptance criteria and questions.
- `BacklogTriageAgent`: groups tasks, risk signals, stale work, and run failures.
- `WikiQueryAgent`: answers over DevBoard-held wiki, artifact metadata, graph summaries, quality reports, and run history.
- `SocrateAgent`: read-only project assistant over DevBoard-held evidence.
- `WatchmanAgent`: correlates project logbook entries with runs, artifacts, wiki freshness, graph deltas, and quality reports.

### Conversation Context

`needs_verification`: agents can provide a message history iterable.

DevBoard implication:

- do not pass entire project history blindly;
- create a context-packing service that selects bounded, permission-filtered evidence;
- include source status and evidence refs in every context block;
- persist exact context pack hashes or snapshots for auditability.

### Structured Output

`needs_verification`: agents can implement structured output through JSON schema definitions. The docs cover required fields, nested objects, arrays of objects, enums, and `anyOf`.

DevBoard implication:

- use structured output for suggestion records, not free-form mutations;
- candidate schemas:
  - task clarification suggestion;
  - backlog triage grouping;
  - wiki answer with citations;
  - Watchman warning;
  - follow-up task proposal;
  - confidence and evidence metadata.

Hard rule:

- structured output is still model output. It must pass server validation and human approval before mutating project state.

### Attachments

`needs_verification`: prompts can include documents and images as attachments, either from storage, paths, URLs, uploaded files, raw strings, or provider-stored file IDs depending on the API used.

DevBoard implication:

- do not attach raw target source files by default;
- safe candidates are DevBoard-held PDFs, images, audit exports, quality reports, artifact summaries, and generated wiki pages;
- attachments need the same storage, permission, retention, and backup policy as other DevBoard-held evidence.

### Streaming And Broadcasting

`needs_verification`: agents can stream responses, use Vercel AI SDK stream protocol, and broadcast streamed events. Agent operations can also be queued while broadcasting partial events.

DevBoard implication:

- useful for Socrate chat UX and long-running analysis explanations;
- first implementation should probably persist final assistant messages and suggestions, then add streaming UX after the API contract is stable;
- streaming events must not bypass authorization or audit boundaries.

### Queueing

`needs_verification`: agents can be queued with success and failure callbacks.

DevBoard implication:

- aligns with current DevBoard worker/queue direction;
- assistant jobs should be durable and project-scoped;
- queue payloads should contain IDs and context-pack references, not large prompt bodies when avoidable;
- failures should create audit-safe assistant run events.

## Tools

### Laravel Tool Classes

`needs_verification`: the SDK can generate tool classes with:

```bash
php artisan make:tool ToolName
```

Each tool defines a description, parameter schema, and handler.

DevBoard safe tool candidates:

- read project summary;
- read task detail;
- search wiki revisions;
- read run summary;
- read artifact metadata;
- read quality report summaries;
- read graph neighborhood summaries through bounded dashboard-safe queries;
- append an assistant suggestion record.

DevBoard unsafe tool candidates unless explicitly gated:

- start Genesis / Delta;
- call local agent job leases;
- modify tasks;
- edit wiki;
- archive/delete projects;
- access raw plugin tokens;
- access provider keys;
- fetch arbitrary URLs;
- execute shell commands.

### MCP Tools

`needs_verification`: Laravel AI SDK can consume tools from Laravel MCP clients, including remote/named clients and local MCP servers. MCP tools are wrapped so an SDK agent can call them like normal tools.

DevBoard implication:

- this is useful for server-side agents that need controlled access to external knowledge or internal MCP resources;
- do not connect server-side agents directly to Codex-local tools that can operate on developer machines;
- if DevBoard exposes its own MCP server, distinguish carefully between:
  - server-owned read-only tools;
  - local-agent tools that require developer machine approval;
  - destructive tools requiring explicit dashboard approval.

### Provider Tools

`needs_verification`: provider-native tools include web search, web fetch, and file search, with provider-specific support and configurable limits/domain filters.

DevBoard implication:

- web search/fetch should be disabled by default for project assistants unless a feature explicitly requires current external research;
- when enabled, use allowlists, rate limits, audit logs, and citation storage;
- file search can be useful only after retention/security policy for provider-side file/vector stores is designed.

### Similarity Search

`needs_verification`: Laravel provides a similarity-search tool for agents over Eloquent models with vector embeddings.

DevBoard implication:

- good fit for project wiki, logbook, audit-safe summaries, artifact metadata, quality reports, and run summaries;
- less suitable for raw full-code artifact search unless the project policy explicitly permits it;
- results must include evidence refs and source status.

## Sub-Agents

`needs_verification`: agents may be returned from another agent's `tools()` method. The parent agent can delegate a specific task to the sub-agent. A sub-agent can implement `CanActAsTool` to define its tool-facing name and description. The docs state that sub-agent invocations are isolated and do not receive the parent conversation history.

DevBoard implication:

- this maps well to a supervisor pattern without bringing in LangGraph:
  - `SocrateAgent` as parent;
  - `WikiQueryAgent`, `BacklogTriageAgent`, `QualitySignalAgent`, and `WatchmanAgent` as sub-agents;
  - each sub-agent gets bounded task input, not the whole chat history.
- keep delegation explicit and narrow;
- persist which sub-agent was invoked, with input summary, output schema, provider, model, usage, and evidence refs.

Important boundary:

- Laravel AI SDK sub-agents are not equivalent to LangGraph's explicit state graph runtime. If DevBoard needs graph-defined workflows with checkpoints, state transitions, resumability, and explicit node/edge orchestration, that remains a separate design decision.

## Middleware

`needs_verification`: agents support middleware that can intercept prompts and post-process responses.

DevBoard uses:

- redact secrets before provider calls;
- enforce project/user permissions;
- attach source-status rules;
- add trace IDs and audit metadata;
- measure token/cost usage;
- block unsafe tool calls;
- validate response schemas;
- write assistant run events.

Do not log full prompt bodies by default. Log hashes, evidence IDs, prompt template versions, model/provider, usage, and policy decisions. Store full prompts only if an explicit retention/security policy approves it.

## Anonymous Agents

`needs_verification`: the SDK supports ad-hoc agents through an `agent()` helper.

DevBoard guidance:

- avoid anonymous agents for production assistant surfaces;
- allow them only for admin-only experiments or tests;
- use named agent classes for stable prompts, audits, provider config, tests, and permission review.

## Agent Configuration

`needs_verification`: agent configuration can use PHP attributes for provider, model, max steps, max tokens, temperature, timeout, top-p, and cheapest/smartest model selection.

DevBoard guidance:

- set `MaxSteps` aggressively low for tools that only read state;
- set timeout explicitly per assistant type;
- prefer deterministic temperature for audit-sensitive suggestions;
- specify concrete model names for repeatable behavior;
- persist runtime configuration used for each assistant run.

## Embeddings, RAG, And Vector Search

### Embeddings

`needs_verification`: the SDK supports embedding generation and vector queries. Laravel vector queries are documented as PostgreSQL-only through the `pgvector` extension.

DevBoard implication:

- DevBoard already uses PostgreSQL, so pgvector is plausible;
- add pgvector only through a dedicated schema and migration plan;
- candidate embedded records:
  - wiki revisions;
  - project logbook entries;
  - run summaries;
  - artifact summaries;
  - quality reports;
  - graph neighborhood summaries;
  - audit-safe assistant suggestions.

### Embedding Caching

`needs_verification`: embeddings can be cached globally or per call.

DevBoard implication:

- caching is important for cost and latency;
- cache keys must account for provider, model, dimensions, input content, and project visibility;
- cache invalidation must follow project lifecycle, artifact retention, and wiki/logbook updates.

### Vector Stores And File Search

`needs_verification`: the SDK supports provider-side vector stores and file search provider tools. Files can be added to stores, removed from stores, and optionally deleted from provider file storage.

DevBoard guidance:

- prefer local PostgreSQL/pgvector for the first DevBoard RAG slice;
- provider-side vector stores create retention, deletion, export, and data-residency questions;
- if provider-side stores are used, store both provider file IDs and vector-store document IDs, because providers may return different identifiers.

## Failover

`needs_verification`: prompting and media generation can accept an ordered provider/model list for failover on service interruption or rate limiting.

DevBoard guidance:

- useful for availability, risky for deterministic audit behavior;
- failover should be explicit per assistant type;
- persist primary provider, fallback provider, selected model, failure reason, and usage;
- avoid silently changing model family on high-stakes suggestions without recording it.

## Testing

`needs_verification`: the SDK exposes fake/assert APIs for agents, queued agents, images, audio, transcriptions, embeddings, reranking, files, and vector stores.

DevBoard testing approach:

- unit test each agent with fake responses and strict prompt assertions;
- test queued assistant jobs with queued assertions;
- test tool authorization separately from model output;
- test schema validation against bad structured outputs;
- use `preventStrayPrompts`-style discipline so tests fail if an agent calls a real provider unexpectedly;
- add integration tests only behind opt-in env flags with explicit provider credentials.

## Events And Observability

`needs_verification`: the SDK dispatches events for prompting, streaming, tool invocation, generated media, embeddings, reranking, file operations, vector store operations, and related completions.

DevBoard implication:

- subscribe to relevant events for internal assistant telemetry;
- store audit-safe run events:
  - assistant prompted;
  - tool invoked;
  - tool denied;
  - response validated;
  - suggestion created;
  - human approved/rejected;
  - provider failure/failover;
  - token/cost usage summary.

Do not store raw prompts/responses in audit logs unless a retention policy explicitly permits it.

## Fit Against Current DevBoard Agent Model

### Local Node Agent

`verified_from_code`: current `agent/` is a minimal Node CLI for auth/device/workspace link and Git state refresh.

Laravel AI SDK should not replace this local agent. The local agent exists to run near the target repository and report deterministic local state without giving the server repository access.

### Python Plugin / Analyzer

`verified_from_code`: current DevBoard architecture uses Python CLI/MCP plugin and analyzer for Genesis/Delta artifact generation and upload.

Laravel AI SDK should not replace analyzer execution. It can consume analyzer results and DevBoard-held artifacts after upload.

### Server-Side Assistants

`inferred`: Laravel AI SDK is most useful for server-side assistants that reason over DevBoard-held data:

- Socrate chat over project state;
- Watchman suggestions;
- Task Clarifier;
- Backlog Triage;
- Wiki Query;
- quality/report explanation;
- risk summary after Genesis/Delta.

## Recommended Future Architecture

```text
Dashboard UI
  -> /api/dashboard/assistants/*
     -> Assistant controller
        -> Assistant run service
           -> Context packer
           -> Laravel AI SDK agent class
              -> read-only DevBoard tools
              -> optional sub-agents
              -> structured suggestion output
           -> assistant_suggestions / assistant_messages
           -> audit_logs / run_events

Local plugin / local Node agent
  -> /api/plugin/v1/*
     -> local workspace, Genesis, Delta, logbook append/fetch
```

The two paths must remain separate. Server-side Laravel AI agents may read DevBoard-held evidence. They must not perform local repository actions.

## First Safe Adoption Slice

Recommended first implementation, after a design spec:

1. Install `laravel/ai` in a feature branch.
2. Publish config/migrations and inspect generated schema.
3. Add provider config with no production key committed.
4. Create one read-only `WikiQueryAgent`.
5. Add one read-only tool: `SearchDevBoardWiki`.
6. Use structured output: answer, citations, confidence, missing evidence.
7. Add dashboard endpoint under `/api/dashboard/assistants/wiki-query`.
8. Persist assistant run metadata, prompt template version, context hash, provider, model, usage, and citations.
9. Add fake-based tests that prove no real provider call happens in normal test runs.
10. Add role guards and audit logs.

Out of scope for first slice:

- autonomous task mutation;
- local agent job launching;
- provider-side vector stores;
- web search;
- Git or shell operations;
- cross-project context;
- unbounded chat history.

## Open Decisions Before Implementation

- Should DevBoard use Laravel SDK conversation tables, custom assistant tables, or both?
- Should the first RAG backend be PostgreSQL/pgvector or provider vector stores?
- Which provider(s) are acceptable for self-hosted deployments?
- How will Admin configure provider keys and model policy?
- What prompt/response retention policy is acceptable?
- How will token/cost usage be budgeted per project?
- What assistant outputs require human approval?
- How should assistant suggestions integrate with Kanban, Wiki, Logbook, and Watchman?
- Is LangGraph-style explicit workflow orchestration needed, or are Laravel sub-agents plus queues sufficient for V1?

## Decision Guidance

Use Laravel AI SDK when:

- the assistant lives inside the Laravel control plane;
- the work is read-heavy over DevBoard-held data;
- output can be represented as validated suggestions;
- the workflow benefits from Laravel queues, events, policies, tests, and database persistence.

Do not use Laravel AI SDK when:

- execution must happen near a target repository;
- the workflow needs shell/Git access on a developer machine;
- the task requires existing Python analyzer behavior;
- the operation must remain offline/local-only;
- the system needs explicit LangGraph-style state-machine semantics that Laravel sub-agents do not provide.

