# SOURCE_OF_TRUTH.md

Every generated claim must be labeled as one of:

- `verified_from_code`
- `developer_provided`
- `inferred`
- `needs_verification`

Rules:

- Use `verified_from_code` only after reading code, config, test, schema, route output, or a local command result.
- Use `developer_provided` only when the developer explicitly said it.
- Use `inferred` when the project structure strongly suggests a fact but no direct proof was checked.
- Use `needs_verification` for gaps, stale docs, missing commands, and unconfirmed assumptions.
