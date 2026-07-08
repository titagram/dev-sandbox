import { Download, ShieldAlert, Trash2 } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

export default function SystemShow({ project, health, runtime, operations, dashboard }) {
  const [retentionForm, setRetentionForm] = useState({
    days: operations.artifact_retention.default_days,
    limit: '',
    dry_run: true,
  });
  const [retentionResult, setRetentionResult] = useState(null);
  const [retentionErrors, setRetentionErrors] = useState({});
  const [retentionSubmitting, setRetentionSubmitting] = useState(false);
  const [exportForm, setExportForm] = useState({
    format: operations.audit_export.formats[0],
    action: '',
    actor_type: '',
    from: '',
    to: '',
  });
  const [exportResult, setExportResult] = useState(null);
  const [exportErrors, setExportErrors] = useState({});
  const [exportSubmitting, setExportSubmitting] = useState(false);

  async function runRetention(event) {
    event.preventDefault();

    const livePurge = !retentionForm.dry_run;
    if (livePurge && !window.confirm(`Delete stored artifact contents older than ${retentionForm.days} days now? Metadata rows will remain and be marked as purged.`)) {
      return;
    }

    setRetentionSubmitting(true);
    setRetentionErrors({});

    const response = await fetch(operations.artifact_retention.run_href, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
      },
      body: JSON.stringify({
        days: Number(retentionForm.days),
        limit: retentionForm.limit === '' ? null : Number(retentionForm.limit),
        dry_run: retentionForm.dry_run,
        confirm_purge: livePurge,
      }),
    });

    const payload = await response.json().catch(() => ({}));
    setRetentionSubmitting(false);

    if (!response.ok) {
      setRetentionErrors(payload.errors ?? { general: ['Artifact retention failed.'] });
      return;
    }

    setRetentionResult({
      ...payload,
      requested_at: new Date().toISOString(),
      mode: retentionForm.dry_run ? 'dry_run' : 'live_purge',
    });
  }

  async function runAuditExport(event) {
    event.preventDefault();
    setExportSubmitting(true);
    setExportErrors({});

    const response = await fetch(operations.audit_export.run_href, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
      },
      body: JSON.stringify({
        format: exportForm.format,
        filters: compactFilters({
          action: exportForm.action,
          actor_type: exportForm.actor_type,
          from: exportForm.from,
          to: exportForm.to,
        }),
      }),
    });

    const payload = await response.json().catch(() => ({}));
    setExportSubmitting(false);

    if (!response.ok) {
      setExportErrors(payload.errors ?? { general: ['Audit export failed.'] });
      return;
    }

    setExportResult({
      ...payload,
      requested_at: new Date().toISOString(),
    });
  }

  return (
    <AppLayout title="System" dashboard={dashboard}>
      <section className="rounded border border-zinc-200 bg-white p-4">
        <h1 className="text-lg font-semibold">System</h1>
        <p className="mt-1 text-sm text-zinc-500">{project ? `${project.name} · platform health and runtime configuration` : 'Platform health and runtime configuration'}</p>
      </section>

      <section className="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <MetricCard label="Projects" value={health.projects_total} />
        <MetricCard label="Repositories" value={health.repositories_total} />
        <MetricCard label="Active devices" value={health.active_devices} />
        <MetricCard label="Runs" value={health.runs_total} />
        <MetricCard label="Failed runs" value={health.failed_runs} />
        <MetricCard label="Artifacts" value={health.artifacts_total} />
        <MetricCard label="Graph-enabled repos" value={health.graph_enabled_repositories} />
        <MetricCard label="Active tokens" value={health.plugin_tokens_active} />
      </section>

      <section className="mt-5 rounded border border-zinc-200 bg-white p-4">
        <div className="text-sm font-semibold">Runtime</div>
        <div className="mt-3 grid gap-2 text-sm text-zinc-700 md:grid-cols-2">
          <div>Graph enabled: <span className="font-medium">{runtime.graph_enabled ? 'true' : 'false'}</span></div>
          <div>Graph import mode: <span className="font-medium">{runtime.graph_import_mode}</span></div>
          <div>Neo4j URI: <span className="font-medium">{runtime.neo4j_uri}</span></div>
          <div>Queue connection: <span className="font-medium">{runtime.queue_connection}</span></div>
        </div>
      </section>

      <section className="mt-5 grid gap-5 xl:grid-cols-2">
        <form onSubmit={runRetention} className="rounded border border-zinc-200 bg-white p-4">
          <div className="flex items-center gap-2 text-sm font-semibold">
            <Trash2 size={16} />
            Artifact retention
          </div>
          <p className="mt-1 text-sm text-zinc-500">Purges stored artifact contents while preserving database metadata and audit history.</p>

          <div className="mt-4 grid gap-3 md:grid-cols-[160px_160px_auto]">
            <label className="text-sm">
              <span className="mb-1 block text-xs text-zinc-500">Retention days</span>
              <input
                className="h-9 w-full rounded border border-zinc-300 px-3 text-sm"
                min="1"
                type="number"
                value={retentionForm.days}
                onChange={(event) => setRetentionForm((current) => ({ ...current, days: event.target.value }))}
              />
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-zinc-500">Limit</span>
              <input
                className="h-9 w-full rounded border border-zinc-300 px-3 text-sm"
                min="1"
                placeholder="optional"
                type="number"
                value={retentionForm.limit}
                onChange={(event) => setRetentionForm((current) => ({ ...current, limit: event.target.value }))}
              />
            </label>
            <label className="flex items-end gap-2 text-sm">
              <input
                checked={retentionForm.dry_run}
                className="mb-2 h-4 w-4 rounded border-zinc-300"
                type="checkbox"
                onChange={(event) => setRetentionForm((current) => ({ ...current, dry_run: event.target.checked }))}
              />
              <span className="mb-1 text-sm">Dry run only</span>
            </label>
          </div>

          {renderErrors(retentionErrors)}

          <div className="mt-4 flex items-center gap-3">
            <button
              className="inline-flex h-9 items-center gap-2 rounded bg-zinc-950 px-3 text-sm font-medium text-white disabled:opacity-60"
              disabled={retentionSubmitting}
              type="submit"
            >
              <Trash2 size={14} />
              {retentionForm.dry_run ? 'Preview purge' : 'Run purge'}
            </button>
            <div className="text-xs text-zinc-500">
              {retentionForm.dry_run ? 'Simulation only. No files are deleted.' : 'Live purge requires explicit confirmation.'}
            </div>
          </div>

          <OperationResultPanel
            emptyLabel="No retention run in this session."
            result={retentionResult}
            rows={[
              ['Mode', retentionResult?.mode === 'dry_run' ? 'dry-run' : retentionResult?.mode === 'live_purge' ? 'live purge' : null],
              ['Scanned', retentionResult?.scanned],
              ['Would purge', retentionResult?.would_purge],
              ['Purged', retentionResult?.purged],
              ['Skipped', retentionResult?.skipped],
              ['Failed', retentionResult?.failed],
              ['Requested at', retentionResult?.requested_at],
            ]}
          >
            {retentionResult?.failures?.length ? (
              <div className="mt-3 rounded border border-red-200 bg-red-50 p-3 text-xs text-red-800">
                <div className="font-medium">Failures</div>
                <ul className="mt-2 space-y-1">
                  {retentionResult.failures.map((failure) => (
                    <li key={failure.artifact_id}>
                      <code>{failure.artifact_id}</code>: {failure.message}
                    </li>
                  ))}
                </ul>
              </div>
            ) : null}
          </OperationResultPanel>
        </form>

        <form onSubmit={runAuditExport} className="rounded border border-zinc-200 bg-white p-4">
          <div className="flex items-center gap-2 text-sm font-semibold">
            <Download size={16} />
            Audit export
          </div>
          <p className="mt-1 text-sm text-zinc-500">Exports sanitized audit records to local storage for incident review and operations traceability.</p>

          <div className="mt-4 grid gap-3 md:grid-cols-2">
            <label className="text-sm">
              <span className="mb-1 block text-xs text-zinc-500">Action</span>
              <input
                className="h-9 w-full rounded border border-zinc-300 px-3 text-sm"
                placeholder="artifact.purged"
                value={exportForm.action}
                onChange={(event) => setExportForm((current) => ({ ...current, action: event.target.value }))}
              />
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-zinc-500">Actor type</span>
              <select
                className="h-9 w-full rounded border border-zinc-300 px-3 text-sm"
                value={exportForm.actor_type}
                onChange={(event) => setExportForm((current) => ({ ...current, actor_type: event.target.value }))}
              >
                <option value="">all</option>
                {operations.audit_export.actor_types.map((actorType) => (
                  <option key={actorType} value={actorType}>{actorType}</option>
                ))}
              </select>
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-zinc-500">From</span>
              <input
                className="h-9 w-full rounded border border-zinc-300 px-3 text-sm"
                type="datetime-local"
                value={exportForm.from}
                onChange={(event) => setExportForm((current) => ({ ...current, from: event.target.value }))}
              />
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-zinc-500">To</span>
              <input
                className="h-9 w-full rounded border border-zinc-300 px-3 text-sm"
                type="datetime-local"
                value={exportForm.to}
                onChange={(event) => setExportForm((current) => ({ ...current, to: event.target.value }))}
              />
            </label>
          </div>

          <div className="mt-4 flex items-center gap-4">
            {operations.audit_export.formats.map((format) => (
              <label key={format} className="inline-flex items-center gap-2 text-sm">
                <input
                  checked={exportForm.format === format}
                  className="h-4 w-4 border-zinc-300"
                  type="radio"
                  value={format}
                  onChange={(event) => setExportForm((current) => ({ ...current, format: event.target.value }))}
                />
                <span>{format}</span>
              </label>
            ))}
          </div>

          {renderErrors(exportErrors)}

          <div className="mt-4 flex items-center gap-3">
            <button
              className="inline-flex h-9 items-center gap-2 rounded bg-zinc-950 px-3 text-sm font-medium text-white disabled:opacity-60"
              disabled={exportSubmitting}
              type="submit"
            >
              <Download size={14} />
              Generate export
            </button>
            <div className="text-xs text-zinc-500">The file is stored on the local server disk and is not streamed directly by this action.</div>
          </div>

          <OperationResultPanel
            emptyLabel="No audit export in this session."
            result={exportResult}
            rows={[
              ['Format', exportResult?.format],
              ['Rows', exportResult?.row_count],
              ['Path', exportResult?.path],
              ['SHA-256', exportResult?.sha256],
              ['Requested at', exportResult?.requested_at],
            ]}
          />
        </form>
      </section>
    </AppLayout>
  );
}

function MetricCard({ label, value }) {
  return (
    <div className="rounded border border-zinc-200 bg-white px-4 py-3">
      <div className="text-xs text-zinc-500">{label}</div>
      <div className="mt-1 text-sm font-medium">{value}</div>
    </div>
  );
}

function OperationResultPanel({ result, rows, emptyLabel, children = null }) {
  return (
    <div className="mt-4 rounded border border-zinc-200 bg-zinc-50 p-3">
      <div className="flex items-center gap-2 text-xs font-medium text-zinc-700">
        <ShieldAlert size={14} />
        Latest result in this session
      </div>
      {!result ? <div className="mt-2 text-xs text-zinc-500">{emptyLabel}</div> : (
        <>
          <dl className="mt-3 grid gap-x-4 gap-y-2 text-sm md:grid-cols-2">
            {rows.filter(([, value]) => value !== null && value !== undefined && value !== '').map(([label, value]) => (
              <div key={label}>
                <dt className="text-xs text-zinc-500">{label}</dt>
                <dd className="break-all font-medium text-zinc-900">{String(value)}</dd>
              </div>
            ))}
          </dl>
          {children}
        </>
      )}
    </div>
  );
}

function renderErrors(errors) {
  const messages = Object.values(errors).flatMap((value) => Array.isArray(value) ? value : [value]).filter(Boolean);

  if (!messages.length) {
    return null;
  }

  return (
    <div className="mt-3 rounded border border-red-200 bg-red-50 p-3 text-xs text-red-800">
      {messages.map((message, index) => (
        <div key={`${message}-${index}`}>{message}</div>
      ))}
    </div>
  );
}

function compactFilters(filters) {
  return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '' && value !== null && value !== undefined));
}
