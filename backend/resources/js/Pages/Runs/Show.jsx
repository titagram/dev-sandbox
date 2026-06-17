import { Link, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, FileJson, GitBranch, RadioTower } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';

export default function RunShow({ run, linkedTask, graphView, runContext, project, repository, events, artifacts, affectedWikiPages, sourceLabel, risk, safety, summary, state, dashboard }) {
  return (
    <AppLayout title={`Run ${run.id}`} dashboard={dashboard}>
      <header className="rounded border border-zinc-200 bg-white p-4">
        <div className="flex flex-col gap-2 xl:flex-row xl:items-center xl:justify-between">
          <h1 className="text-lg font-semibold">Run {run.id}</h1>
          <div className="text-sm">Status: {run.status} · Risk: {run.risk_level}</div>
        </div>
        <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-zinc-500">
          <span>Project: {project.name}</span>
          <span>Repo: {repository?.name ?? 'none'}</span>
          <span>Branch: {run.branch}</span>
          <span>Type: {runContext.kind}</span>
          <span>Device: {runContext.device_name}</span>
          <span>Source: {sourceLabel}</span>
        </div>
        <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-500">
          <span>Started: {runContext.started_at ?? 'n/a'}</span>
          <span>Finished: {runContext.finished_at ?? 'n/a'}</span>
        </div>
        <div className="mt-3 inline-flex items-center gap-2 rounded border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-900">
          <RadioTower size={14} />
          {state.source_truth}
        </div>
        <div className="mt-3 flex flex-wrap gap-2">
          {linkedTask ? (
            <Link
              href={linkedTask.href}
              className="inline-flex items-center rounded border border-zinc-200 bg-white px-2.5 py-1.5 text-xs text-zinc-700 hover:bg-zinc-100"
            >
              open linked task
            </Link>
          ) : null}
          {graphView ? (
            <Link
              href={graphView.href}
              className="inline-flex items-center rounded border border-zinc-200 bg-white px-2.5 py-1.5 text-xs text-zinc-700 hover:bg-zinc-100"
            >
              open graph view
            </Link>
          ) : null}
          {state.retryable_import && canRetryImport(dashboard) ? (
            <button
              type="button"
              onClick={() => router.post(`/runs/${run.id}/retry-import`, {}, { preserveScroll: true })}
              className="inline-flex items-center rounded border border-zinc-200 bg-white px-2.5 py-1.5 text-xs text-zinc-700 hover:bg-zinc-100"
            >
              retry failed import
            </button>
          ) : null}
          {state.reviewed ? (
            <span className="inline-flex items-center rounded border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-xs text-emerald-900">
              reviewed
            </span>
          ) : canReviewRun(dashboard) ? (
            <button
              type="button"
              onClick={() => router.post(`/runs/${run.id}/review`, {}, { preserveScroll: true })}
              className="inline-flex items-center rounded border border-zinc-200 bg-white px-2.5 py-1.5 text-xs text-zinc-700 hover:bg-zinc-100"
            >
              mark reviewed
            </button>
          ) : null}
        </div>
      </header>

      <section className="mt-5 grid gap-4 xl:grid-cols-[380px_1fr]">
        <div className="rounded border border-zinc-200 bg-white p-4">
          <div className="font-semibold">Timeline</div>
          <div className="mt-3 space-y-3 text-sm">
            {events.map((event) => (
              <div key={event.id} className="grid grid-cols-[88px_1fr] gap-2">
                <span className="text-xs text-zinc-500">{event.created_at}</span>
                <span>
                  <span className="font-medium">{event.event_type}</span>
                  <span className="text-zinc-500"> · {event.severity} · {event.message}</span>
                </span>
              </div>
            ))}
          </div>
        </div>

        <div className="rounded border border-zinc-200 bg-white p-4">
          <div className="font-semibold">Summary</div>
          <p className="mt-2 text-sm text-zinc-600">{run.summary ?? 'No summary yet.'}</p>
          {linkedTask ? (
            <div className="mt-4 rounded border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-700">
              <div><span className="font-medium">Task:</span> <a href={linkedTask.href} className="underline-offset-2 hover:underline">{linkedTask.title}</a></div>
              <div className="mt-1 text-xs text-zinc-500">status: {linkedTask.status_name ?? 'n/a'}</div>
            </div>
          ) : null}
          <div className="mt-4 grid gap-3 lg:grid-cols-3">
            <StatusTile label="Graph" value={state.graph_status} />
            <StatusTile label="Graph mode" value={state.graph_extraction_mode ?? 'unknown'} />
            <StatusTile label="Wiki" value={state.wiki_status} />
            <StatusTile label="Risk review" value={risk.severity} />
          </div>
          <div className="mt-4 grid gap-3 lg:grid-cols-3">
            <StatusTile label="Changed files" value={summary.diff.changed_file_count ?? 'n/a'} />
            <StatusTile label="Additions" value={summary.diff.additions ?? 'n/a'} />
            <StatusTile label="Deletions" value={summary.diff.deletions ?? 'n/a'} />
          </div>
          <div className="mt-4 rounded border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-700">
            <div><span className="font-medium">Tests:</span> {summary.tests.status ?? 'not reported'}</div>
            <div className="mt-1 text-xs text-zinc-500">{summary.tests.summary ?? 'No test summary artifact.'}</div>
          </div>
          <div className="mt-4 text-sm font-semibold">Artifacts</div>
          <div className="mt-2 flex flex-wrap gap-2 text-xs">
            {artifacts.map((artifact) => (
              <a
                key={artifact.id}
                href={downloadableArtifact(artifact) ? `/runs/${run.id}/artifacts/${artifact.id}/download` : undefined}
                className="inline-flex items-center gap-1 rounded bg-zinc-100 px-2 py-1 hover:bg-zinc-200"
              >
                <FileJson size={12} />
                {artifact.artifact_type} · {artifact.status}
              </a>
            ))}
          </div>
        </div>
      </section>

      <section className="mt-5 grid gap-4 xl:grid-cols-2">
        <div className="rounded border border-zinc-200 bg-white p-4">
          <div className="flex items-center gap-2 text-sm font-semibold">
            <AlertTriangle size={16} />
            Risk Triggers
          </div>
          <div className="mt-2 text-sm text-zinc-600">{risk.report.summary ?? 'No structured risk report.'}</div>
          <div className="mt-2 flex flex-wrap gap-2 text-xs text-zinc-600">
            {risk.report.triggers.length === 0 ? 'none' : risk.report.triggers.map((trigger) => <span key={trigger} className="rounded bg-zinc-100 px-2 py-1">{trigger}</span>)}
          </div>
        </div>
        <div className="rounded border border-zinc-200 bg-white p-4">
          <div className="flex items-center gap-2 text-sm font-semibold">
            <CheckCircle2 size={16} />
            Safety Results
          </div>
          <SafetyList title="Blocked" items={safety.blocked} />
          <SafetyList title="Warnings" items={safety.warnings} />
        </div>
      </section>

      <section className="mt-5 rounded border border-zinc-200 bg-white p-4 text-sm">
        <div className="flex items-center gap-2 font-semibold">
          <GitBranch size={16} />
          Evidence
        </div>
        <div className="mt-2 text-zinc-600">file hashes · command output · graph nodes · wiki revisions</div>
        {affectedWikiPages.length > 0 ? (
          <div className="mt-4">
            <div className="text-sm font-semibold">Affected Wiki Pages</div>
            <div className="mt-2 flex flex-wrap gap-2 text-xs">
              {affectedWikiPages.map((page) => (
                <a key={page.id} href={page.href} className="rounded bg-zinc-100 px-2 py-1 text-zinc-700 hover:bg-zinc-200">
                  {page.slug}
                </a>
              ))}
            </div>
          </div>
        ) : null}
      </section>
    </AppLayout>
  );
}

function StatusTile({ label, value }) {
  return (
    <div className="rounded border border-zinc-200 bg-zinc-50 px-3 py-2">
      <div className="text-xs text-zinc-500">{label}</div>
      <div className="mt-1 text-sm font-medium">{value}</div>
    </div>
  );
}

function SafetyList({ title, items }) {
  return (
    <div className="mt-2 text-xs text-zinc-600">
      <span className="font-medium text-zinc-800">{title}: </span>
      {items.length === 0 ? 'none' : items.map((item) => `${item.path ?? item.pattern ?? 'item'}:${item.reason ?? 'flag'}`).join(', ')}
    </div>
  );
}

function downloadableArtifact(artifact) {
  return artifact.status === 'validated' || artifact.status === 'imported';
}

function canReviewRun(dashboard) {
  const roles = dashboard?.user?.roles ?? [];

  return roles.includes('PM') || roles.includes('Developer') || roles.includes('Sysadmin') || roles.includes('Admin');
}

function canRetryImport(dashboard) {
  const roles = dashboard?.user?.roles ?? [];

  return roles.includes('Developer') || roles.includes('Admin');
}
