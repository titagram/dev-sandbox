# Persephone Agent Queue v1

Persephone is a project-scoped, untrusted transport for Hades agent messages.
The backend stores and returns the validated envelope. It does not derive
authority, permissions, or execution decisions from fields inside `payload`.
The v1 queue uses the dedicated `hades_persephone_agent_messages` table and
does not read from or write to legacy `hades_persephone_events` rows.

## Capability discovery

An authenticated Hades agent receives the top-level field
`persephone_agent_queue_v1: true` from `GET /api/hades/v1/capabilities`.
This is a protocol support advertisement, not a per-agent permission to
perform mutating work. Existing capability fields remain unchanged. Discovery
requires a derived Hades agent token; unauthenticated and bootstrap tokens are
rejected by the existing middleware.

## Envelope

`POST /api/hades/v1/persephone/messages` accepts one JSON object with
`additionalProperties: false` and schema
`hades.persephone.agent-message.v1`.

The 15 envelope keys are:

| Key | Type | Required |
| --- | --- | --- |
| `schema` | non-blank string, exact value `hades.persephone.agent-message.v1` | yes |
| `message_id` | trimmed, non-blank string | yes |
| `correlation_id` | trimmed, non-blank string | yes |
| `project_id` | trimmed, non-blank string | yes |
| `sender_agent_id` | trimmed, non-blank string | yes |
| `target_agent_id` | trimmed, non-blank string | yes |
| `target_workspace_binding_id` | null or trimmed, non-blank string | yes |
| `message_type` | enum below | yes |
| `effect` | `information_read` or `mutating` | yes |
| `capability` | trimmed, non-blank string | yes |
| `expires_at` | integer Unix timestamp strictly in the future | yes |
| `payload` | JSON object, including `{}` | yes |
| `causation_id` | null or trimmed, non-blank string | no |
| `remote_task_id` | null or trimmed, non-blank string | no |
| `remote_task_version` | null or trimmed, non-blank string | no |

Allowed `message_type` values are `information_request`, `local_decision`,
`information_response`, `status_query`, `status_response`, and
`cancel_request`.

`payload` has at most 128 top-level properties. Its canonical compact sorted
UTF-8 JSON representation is at most 65,536 bytes. Nested arrays and objects
are valid. An empty payload remains the JSON object `{}` on POST, polling, and
SSE responses.

The binding is mandatory and non-null for capabilities `source_slice`,
`source_search`, `symbol_lookup`, `git_metadata`, and `artifact_metadata`.

## Authorization and idempotency

Every message request resolves the authenticated Hades agent token through the
existing middleware. For POST:

- `project_id` must equal the token agent project.
- `sender_agent_id` must equal the token agent external id.
- `target_agent_id` must be an active agent in the same project.
- A supplied target binding must be active, linked, in the same project, and
  belong to the target agent.
- Missing or foreign targets use the same safe `404 target_agent_not_found`
  response. Foreign record details are never exposed.

The first valid write returns `201` and the stored event. A replay with the
same `project_id` and `message_id` and an identical normalized envelope returns
`200` with the existing event. Any different normalized field returns `409`.
Whitespace around strings and JSON object key order do not change the
normalized comparison; raw request formatting is not hashed.

The response event contains exactly the 15 envelope keys plus server-generated
ULID `id`. No `created_at`, `read_at`, legacy event type, or other key is
added.

## Polling inbox

`GET /api/hades/v1/persephone/inbox` requires `project_id` and
`target_agent_id`. It accepts optional non-blank
`target_workspace_binding_id`, ULID `cursor`, and `limit` from 1 through 100
(default 100).

The project and target must match the authenticated Hades agent. A cursor must
belong to the same project and target; malformed, foreign, or unknown cursors
are rejected rather than silently rewound. Results are oldest first by the
server ULID, strictly after the cursor, bounded by `limit`, and exclude
expired messages. A missing workspace filter returns unbound messages and
messages for all active bindings of that target. A supplied filter returns
unbound messages and messages for exactly that active binding. Polling does
not mark messages read.

The response is exactly:

```json
{"events":[{"schema":"hades.persephone.agent-message.v1","message_id":"...","correlation_id":"...","project_id":"...","sender_agent_id":"...","target_agent_id":"...","target_workspace_binding_id":null,"message_type":"status_query","effect":"information_read","capability":"status_query","expires_at":4102444800,"payload":{},"causation_id":null,"remote_task_id":null,"remote_task_version":null,"id":"01J00000000000000000000000"}],"cursor":"01J00000000000000000000000"}
```

An empty page returns `events: []` and preserves the input cursor, or returns
`cursor: null` when no cursor was supplied.

## Bounded SSE

`GET /api/hades/v1/persephone/events` applies exactly the inbox validation,
authorization, workspace, expiry, cursor, ordering, and limit rules. Errors
are returned before a stream is created, with their normal `401`, `403`,
`404`, or `422` status.

Successful responses use `Content-Type: text/event-stream; charset=UTF-8` and
contain no legacy rows. Each message is one block, followed by a blank line:

```text
id: <server ULID>
event: message
data: <one-line JSON containing the exact envelope plus id>

```

After the bounded page, the response always emits:

```text
event: stop
data: {"reason":"bounded","cursor":"<last id or input cursor or null>"}

```

This is a bounded response, not an infinite PHP worker. Clients should use
polling as the fallback transport and resume with the returned server cursor.
