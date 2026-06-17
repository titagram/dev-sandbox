import { Link } from '@inertiajs/react';
import { AlertTriangle, ArrowUpRight, RadioTower, ShieldCheck } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';

export default function TaskShow({ task, dashboard }) {
  return (
    <AppLayout title={`Task ${task.title}`} dashboard={dashboard}>
      <header className="rounded border border-zinc-200 bg-white p-4">
        <div className="flex flex-col gap-2 xl:flex-row xl:items-center xl:justify-between">
          <div>
            <h1 className="text-lg font-semibold">{task.title}</h1>
            <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-zinc-500">
              <span>Status: {task.status.name}</span>
              <span>Priority: {task.priority}</span>
              <span>Risk: {task.risk_level}</span>
              <span>Owner: {task.owner.name}</span>
            </div>
          </div>
          {task.blocked ? (
            <div className="inline-flex items-center gap-2 rounded border border-red-200 bg-red-50 px-3 py-1.5 text-xs text-red-900">
              <AlertTriangle size={14} />
              blocked
            </div>
          ) : null}
        </div>
        <div className="mt-3 inline-flex items-center gap-2 rounded border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-900">
          <RadioTower size={14} />
          {task.source_label}
        </div>
      </header>

      <section className="mt-5 grid gap-4 xl:grid-cols-[1.2fr_0.8fr]">
        <div className="rounded border border-zinc-200 bg-white p-4">
          <div className="font-semibold">Description</div>
          <p className="mt-2 text-sm text-zinc-600">{task.description ?? 'No description.'}</p>
        </div>

        <div className="rounded border border-zinc-200 bg-white p-4">
          <div className="font-semibold">Operational Status</div>
          <div className="mt-3 grid gap-3 sm:grid-cols-2">
            <StatusTile label="Wiki" value={task.wiki_source_status} />
            <StatusTile label="Due" value={task.due_at ?? 'n/a'} />
          </div>
        </div>
      </section>

      <section className="mt-5 rounded border border-zinc-200 bg-white p-4">
        <div className="flex items-center gap-2 text-sm font-semibold">
          <ArrowUpRight size={16} />
          Linked Run
        </div>
        {task.linked_run ? (
          <div className="mt-3 rounded border border-zinc-200 bg-zinc-50 px-3 py-3 text-sm text-zinc-700">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div>
                <div className="font-medium">{task.linked_run.id}</div>
                <div className="mt-1 text-xs text-zinc-500">
                  {task.linked_run.repository_name ?? 'No repository'} · {task.linked_run.status} · risk {task.linked_run.risk_level}
                </div>
              </div>
              <Link href={task.linked_run.href} className="inline-flex items-center gap-1 rounded border border-zinc-200 bg-white px-2.5 py-1.5 text-xs text-zinc-700 hover:bg-zinc-100">
                open run
              </Link>
            </div>
            <div className="mt-2 text-xs text-zinc-500">{task.linked_run.summary ?? 'No run summary.'}</div>
          </div>
        ) : (
          <div className="mt-3 text-sm text-zinc-500">No linked run.</div>
        )}
      </section>

      <section className="mt-5 rounded border border-zinc-200 bg-white p-4 text-sm">
        <div className="flex items-center gap-2 font-semibold">
          <ShieldCheck size={16} />
          Source Status
        </div>
        <div className="mt-2 text-zinc-600">Wiki/source status: {task.wiki_source_status}</div>
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
