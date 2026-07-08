# DevBoard Local Agent Plan

**Purpose:** define the thin Node local agent direction without rewriting the existing Python plugin/analyzer.

## Decisions

- `developer_provided`: build a real local Node application as an agent.
- `developer_provided`: the first version is a thin wrapper over the existing Python plugin/analyzer path.
- `developer_provided`: do not use OS keychain storage for the first VPN-oriented scope.
- `developer_provided`: use polling/job leases first, not WebSocket server push.
- `verified_from_code`: `/api/plugin/v1` is the existing local CLI/MCP/plugin API surface.
- `inferred`: future local agent code should live in top-level `agent/` to keep repository boundaries clear.

## Responsibilities

- run a local web UI on loopback;
- accept DevBoard server URL and token;
- select or enter a local repository folder;
- validate `.git`, branch, `HEAD`, dirty state, stack, package manager, commands, and local tool availability;
- register or update local workspace bindings;
- run local checks through Python plugin/analyzer workers;
- poll for allowlisted jobs;
- upload reports, evidence, artifacts, logbook entries, and Git state to DevBoard;
- expose a localhost API that Codex/MCP can call to launch approved local check profiles.

## Security Boundaries

- bind local UI/API to loopback by default;
- do not expose arbitrary shell execution to the server;
- enforce allowlisted profiles locally;
- do not expose the DevBoard token to Codex or browser pages that do not need it;
- treat server-requested jobs as leases that the local agent validates before execution.

## First MVP

- local UI;
- token entry;
- project/repository selection;
- local folder selection or manual path entry;
- deterministic workspace probes;
- register local workspace;
- run Genesis/Delta through current Python path;
- upload results;
- poll job leases;
- local status endpoint for Codex/MCP.

## Spec Gaps

- package manager and framework for `agent/`;
- exact localhost API;
- exact job lease API;
- local token persistence format;
- probe script structure;
- startup/install packaging for macOS, Linux, and Windows.

