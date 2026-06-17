import { Link } from '@inertiajs/react';
import { GitBranch, Network, RadioTower } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';

export default function GraphShow({ sourceLabel, project, repository, linkedRun, snapshot, graph, dashboard }) {
  return (
    <AppLayout title="Graph" dashboard={dashboard}>
      <header className="rounded border border-zinc-200 bg-white p-4">
        <div className="flex flex-col gap-2 xl:flex-row xl:items-center xl:justify-between">
          <div>
            <h1 className="text-lg font-semibold">Graph</h1>
            <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-zinc-500">
              <span>Project: {project?.name ?? 'No project'}</span>
              <span>Repo: {repository?.name ?? 'No repository'}</span>
              <span>Branch: {snapshot?.branch ?? 'n/a'}</span>
              <span>Artifact: {graph.artifact_status}</span>
            </div>
          </div>
          <div className="inline-flex items-center gap-2 rounded border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-900">
            <RadioTower size={14} />
            {sourceLabel}
          </div>
        </div>
      </header>

      <section className="mt-5 grid gap-4 lg:grid-cols-4">
        <StatusTile label="Nodes" value={graph.node_count} />
        <StatusTile label="Relationships" value={graph.relationship_count} />
        <StatusTile label="Snapshot" value={snapshot?.id ?? 'n/a'} />
        <StatusTile label="Dirty status" value={snapshot?.dirty_status ?? 'n/a'} />
      </section>

      <section className="mt-5 grid gap-4 xl:grid-cols-[0.9fr_1.1fr]">
        <div className="rounded border border-zinc-200 bg-white p-4">
          <div className="flex items-center gap-2 text-sm font-semibold">
            <GitBranch size={16} />
            Snapshot Context
          </div>
          <div className="mt-3 space-y-2 text-sm text-zinc-600">
            <div>run: {snapshot?.run_id ?? 'n/a'}</div>
            <div>source: {snapshot?.source_type ?? 'n/a'}</div>
            <div>base: {snapshot?.base_sha ?? 'n/a'}</div>
            <div>head: {snapshot?.head_sha ?? 'n/a'}</div>
          </div>
          {linkedRun ? (
            <div className="mt-4">
              <Link href={linkedRun.href} className="inline-flex items-center gap-1 rounded border border-zinc-200 bg-white px-2.5 py-1.5 text-xs text-zinc-700 hover:bg-zinc-100">
                open run
              </Link>
            </div>
          ) : null}
        </div>

        <div className="rounded border border-zinc-200 bg-white p-4">
          <div className="flex items-center gap-2 text-sm font-semibold">
            <Network size={16} />
            Label Distribution
          </div>
          {graph.labels.length > 0 ? (
            <div className="mt-3 space-y-2">
              {graph.labels.map((label) => (
                <div key={label.name} className="flex items-center justify-between rounded border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm">
                  <span>{label.name}</span>
                  <span className="text-zinc-500">{label.count}</span>
                </div>
              ))}
            </div>
          ) : (
            <div className="mt-3 text-sm text-zinc-500">No graph artifact payload available.</div>
          )}
        </div>
      </section>
    </AppLayout>
  );
}

function StatusTile({ label, value }) {
  return (
    <div className="rounded border border-zinc-200 bg-white px-3 py-3">
      <div className="text-xs text-zinc-500">{label}</div>
      <div className="mt-1 text-sm font-medium">{value}</div>
    </div>
  );
}
