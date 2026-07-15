# Frontend Source-of-Truth UX Design

Date: 2026-07-15

## Status and evidence boundary

This design is based on the current Hades repository implementation, its existing tests, the canonical graph and Genesis documentation, and the acceptance criteria for this branch. Statements about current behavior are implementation observations. The design decisions below are the target contract for this change and are not claims about behavior until the associated tests pass.

The repository distinguishes `verified_from_code`, `developer_provided`, `ai_generated`, `needs_verification`, `stale`, and `conflict_with_code`. Code evidence is authoritative for code-derived facts; an authenticated human can provide context, but a manual wiki write is still unverified until the separate verification workflow checks it.

## Goal

Project overview, Kickstart, Graph, Memory, and Wiki must tell one consistent story about the same project scope. The UI must expose useful public evidence and uncertainty instead of manufacturing readiness from missing API fields or showing an attractive fuzzy answer when an exact result was not indexed.

The change preserves existing API fields where safe and adds explicit fields. It does not add a Traefik service, alter Traefik, deploy, push, merge, reset a database, or perform destructive import operations.

## Canonical operational status

The backend owns a single `operational_status` view model on project summary/detail responses and on Kickstart. Overview projects use the same project-summary resolver. The resolver first selects the ready canonical graph projection for the project scope. A ready workspace-binding projection is evidence that the linked Hades workspace binding is operational; a repository-scope projection is evidence for the repository projection but does not invent a workspace link.

The additive shape is:

```json
{
  "operational_status": {
    "source": "canonical_graph_projection",
    "graph": {
      "status": "ready",
      "canonical": true,
      "scope_type": "workspace_binding",
      "scope_id": "safe-public-scope",
      "quality": "complete",
      "node_count": 12,
      "relationship_count": 18,
      "reason": "Canonical graph projection is ready."
    },
    "workspace": {
      "status": "linked",
      "linked_count": 1,
      "repository_count": 1,
      "reason": "A workspace is linked to this project."
    },
    "genesis": {
      "status": "complete",
      "reason": "Genesis analysis is represented in the canonical projection."
    },
    "artifacts": {
      "status": "available",
      "legacy_count": 0,
      "reason": "Canonical graph artifacts are available; the legacy artifact list may be empty."
    }
  }
}
```

The exact status values are `ready`, `not_ready`, `partial`, and `not_indexed` for graph; `linked`, `missing`, and `partial` for workspace; `complete`, `pending`, `stale`, `failed`, and `not_started` for Genesis; and `available`, `empty`, and `not_indexed` for artifacts. The resolver never treats a missing legacy artifact row as proof that a ready canonical projection is absent.

Kickstart consumes this same object. Its human copy is:

- “Genesis analysis” means the first reproducible full repository analysis that creates the initial code facts.
- “Workspace link” means Hades knows which authenticated local workspace/repository binding is the project’s source; it is not a claim that a path on the server is currently mounted.
- “Canonical graph ready” means the backend has a scoped, queryable projection of the imported code facts.

The frontend removes fallback inference for Graph import, artifact availability, workspace presence, and linked counts. It may render a loading or unavailable state, but it cannot turn absent fields into a positive or negative operational claim.

## Graph contract and ranking

The public graph envelope gains `completeness`, with values `complete`, `verified_none`, `partial`, and `not_indexed`. Search/detail items expose only safe metadata:

```json
{
  "handle": "public-symbol-handle",
  "kind": "class",
  "label": "AdminControllerBulkDeleteBehavior",
  "source_file": "app/Http/Controllers/AdminController.php",
  "line_start": 42,
  "line_end": 88,
  "namespace": "App\\Http\\Controllers",
  "match_type": "exact_symbol_name",
  "match_reason": "Exact symbol-name match"
}
```

`source_file` is a bounded safe relative path. Absolute paths, path traversal, control characters, raw internal IDs, legacy identities, and private source properties are rejected or omitted. `line_start`, `line_end`, and `namespace` are optional and sanitized. The API never exposes an absolute filesystem path.

Search precedence is deterministic:

1. Exact case-insensitive normalized symbol name.
2. Exact normalized route path, including the leading slash when supplied.
3. Token and full-text matches.
4. Fuzzy matches.

Within a tier, score and stable public handle order are deterministic. Exact candidates are selected before capacity-limited fuzzy candidates, so an exact route cannot be displaced by unrelated worker names and an exact class cannot lose to its `Test` suffix. If an exact-looking query has no exact candidate because graph capacity omitted it, the response contains no fuzzy substitute, `items: []`, `completeness: partial`, and an actionable reason such as “The exact match may be outside the indexed result capacity. Narrow the scope or rerun the canonical import.”

Empty callers, dependencies, and impact are rendered as “Verified none” only when the projection is complete. `partial` or `not_indexed` states explain that the absence is not conclusive and provide the available recovery action.

## Wiki reading and verification

Reading mode uses a maintained React 19-compatible GFM renderer with:

- `react-markdown@10.1.0`
- `remark-gfm@4.0.1`
- `rehype-sanitize@6.0.0`

These exact versions are recorded in `package.json` and `yarn.lock`. The renderer supports headings, ordered and unordered lists, links, fenced code, inline code, blockquotes, and tables. Raw HTML is sanitized. URL protocols are restricted to safe web/mail protocols, unsafe links are not emitted as active links, and Markdown remains inert React content. The page heading is not duplicated when the first body heading is the same normalized title. Edit mode shows the raw Markdown textarea.

Manual create and edit forms do not expose a source-status selector. The backend forcibly writes `needs_verification` with manual source metadata regardless of any legacy status value in the request. Code verification is a separate workflow and is the only route that can establish `verified_from_code`.

The Wiki index adds client-side text search and filters for audience, page type, verification status, and source. The verification queue count is visible beside the filter controls. Refresh status is expressed as one concise status/reason message; raw IDs and repeated metadata remain available in the detail flow rather than competing with the index.

## Memory, navigation, and responsive behavior

The Memory stream is the primary project content and remains first in the content order. Workspace memory import moves into a collapsed “Advanced import” panel with an explicit explanation that it creates proposals and does not silently merge memory. The existing import dialog/detail behavior remains available inside that panel.

The existing project-scoped Graph route is exposed directly in project navigation. No duplicate Graph route is created. Projects select a known authenticated/current project when available; the initial “Demo Project” scope is not retained when the real project list has loaded.

At 390×844, critical state remains visible. Project IDs, UUIDs, breadcrumb segments, graph provenance/scope, and Wiki metadata wrap or middle-truncate with a full accessible label/title. Icon-only buttons have accessible names. Tables use intentional horizontal scrolling for non-critical secondary columns; primary labels and status remain readable. Dark and light themes continue using the existing tokens and component language.

## Test and delivery strategy

Every defect starts with a focused failing backend or React test. Each task follows red, minimal implementation, green, and focused regression verification. Existing tests are extended with semantic assertions rather than brittle snapshots. The final gate runs the non-watch frontend suite, production build, relevant backend tests, Pint, available PHP static analysis, and `git diff --check`.

The implementation is additive at the API boundary and requires no schema migration. Existing canonical projections may need a normal non-destructive re-projection to populate new public metadata; the code documents this operational requirement without running imports. Work is committed in coherent steps on the existing branch, with no merge, push, deploy, Traefik change, logbook edit, or destructive database command.

