# DevBoard Backup Export And Restore Plan

**Purpose:** define native DevBoard backup export and restore flows that are easy to use, auditable, and suitable for migration to another machine.

## Decisions

- `developer_provided`: DevBoard needs clear "Export Backup" and "Restore Backup" sections.
- `developer_provided`: the process must be easy and usable from the DevBoard Server product surface.
- `developer_provided`: MemPalace is not required for backup/restore.
- `verified_from_code`: DevBoard currently runs with Laravel, PostgreSQL, Neo4j, filesystem artifact storage, Docker Compose, dashboard APIs, plugin APIs, and generated frontend routing.
- `verified_from_code`: validated artifacts are stored outside the web root and Neo4j can be rebuilt from validated artifacts.
- `inferred`: backup/restore should be an Admin/Sysadmin-only server workstream, independent from the local plugin/agent scanner workflow.

## Product Surfaces

### Export Backup

Admin/Sysadmin surface for creating a portable DevBoard backup bundle.

It should show:

- current backup readiness;
- included components;
- last successful backup;
- last restore verification;
- estimated size;
- warnings for missing external secrets or unsupported storage;
- download/export status;
- checksum and manifest details.

Minimum export modes:

- full server backup;
- metadata-only backup for dry migration checks;
- storage-only or database-only modes only after the full flow is stable.

### Restore Backup

Admin/Sysadmin surface for validating and restoring a backup bundle.

It should show:

- uploaded or selected backup bundle;
- manifest and checksum validation;
- source DevBoard version and current DevBoard version;
- required secrets/config values;
- dry-run restore report;
- destructive-operation confirmation;
- restore progress;
- post-restore smoke checks.

Restore must default to dry-run validation before any destructive change.

## Backup Bundle V1

`inferred`: the first portable format should be a single archive with a manifest and checksums.

Proposed shape:

```text
devboard-backup-v1/
  manifest.json
  checksums.sha256
  postgres.dump
  neo4j.dump
  storage.tar.zst
  quality-reports.tar.zst
  public-build-metadata.json
  compose-metadata.json
  restore-requirements.json
  audit-summary.json
```

Required manifest fields:

- backup id;
- created at;
- DevBoard git commit or release id;
- backend schema migration state;
- frontend build/source metadata;
- Docker compose files and service image tags;
- PostgreSQL dump metadata;
- Neo4j dump metadata;
- filesystem storage roots included;
- checksum algorithm;
- backup actor;
- source host label;
- compatibility version.

Secrets policy:

- do not include unencrypted secrets in downloadable bundles;
- record required secret names and fingerprints in `restore-requirements.json`;
- allow an explicit encrypted secrets attachment only after a separate encryption/key-management design exists;
- treat `APP_KEY`, database credentials, Neo4j credentials, Traefik/domain config, and external storage credentials as operator-provided restore requirements.

## Export Responsibilities

The export flow must capture:

- PostgreSQL logical dump;
- Neo4j dump or export;
- Laravel filesystem storage for DevBoard artifacts, reports, uploads, and future Kanban attachments;
- quality reports under `backend/var/quality/reports`;
- migration state and app version metadata;
- frontend deployment metadata, including generated frontend source/build provenance;
- route/API health summary;
- audit summary for the export action.

It must not capture target source repositories directly. DevBoard backs up only its control-plane data, uploaded artifacts, normalized reports, graph state, evidence, attachments, and product metadata.

## Restore Responsibilities

The restore flow must support:

- manifest parse and schema compatibility checks;
- checksum verification before unpacking into live paths;
- dry-run validation with no writes;
- maintenance-mode or offline restore requirement;
- PostgreSQL restore;
- Neo4j restore, with optional graph rebuild from validated artifacts when needed;
- filesystem storage restore;
- migration replay if the restored bundle is older than the running code;
- cache/session cleanup;
- post-restore smoke checks for login, `/api/dashboard/...`, `/api/plugin/v1/...`, artifact reads, and graph reads.

Restore must write an audit record and a human-readable restore report.

## UI And API Boundaries

- Browser UI uses `/api/dashboard/...` only.
- Plugin/local agent APIs remain under `/api/plugin/v1/...` and are not used by the browser backup UI.
- Export and restore operations require Admin/Sysadmin authorization.
- Destructive restore requires explicit confirmation and cannot run from a normal read-only assistant suggestion.
- Assistants may explain backup status or propose actions, but must not start export/restore without user approval.

## Implementation Shape

Likely backend pieces:

- backup manifest value objects;
- backup bundle writer/reader;
- PostgreSQL dumper/restorer service;
- Neo4j dumper/restorer service;
- storage archive service;
- checksum verifier;
- restore dry-run evaluator;
- backup job/progress records;
- dashboard API endpoints under `/api/dashboard/system/backups/...`;
- Admin/System frontend sections: `Export Backup` and `Restore Backup`;
- Artisan commands for operator use and automation.

Likely commands:

```text
php artisan devboard:backup-export
php artisan devboard:backup-restore --dry-run <bundle>
php artisan devboard:backup-restore <bundle>
```

## Verification Matrix

Minimum tests before considering the feature usable:

- manifest generation test;
- checksum mismatch rejection test;
- PostgreSQL dump command wiring test;
- storage archive path traversal rejection test;
- dry-run restore does not mutate data;
- restore rejects missing required secrets;
- restore rejects incompatible bundle versions unless an explicit migration path exists;
- dashboard API authorization tests;
- end-to-end local export and restore into a clean Docker stack;
- post-restore smoke covering login, dashboard API, plugin auth check, artifact read, and graph read.

## Open Design Specs Needed

- exact backup bundle schema and versioning;
- where backup bundles are stored before download;
- retention policy and cleanup job;
- encryption/key-management design for optional secret bundles;
- large backup streaming and progress reporting;
- restore rollback strategy;
- compatibility policy across DevBoard versions;
- UI copy and destructive confirmation flow.

