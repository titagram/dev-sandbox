import { Link } from '@inertiajs/react';
import { CircleAlert, Filter, GitBranch, ShieldCheck } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';

const filters = ['Owner', 'Risk', 'Repository', 'Run status', 'Source status'];

export default function KanbanIndex({ project, columns, recentRuns, health, dashboard }) {
  return (
    <AppLayout title={project ? `Project: ${project.name}` : 'No project'} dashboard={dashboard}>
      <section className="mb-4 flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
        <div>
          <h1 className="text-xl font-semibold">Kanban</h1>
          <p className="text-sm text-zinc-500">Board: Delivery · PM-first operational view</p>
        </div>
        <div className="flex flex-wrap gap-2">
          {filters.map((filter) => (
            <button key={filter} type="button" className="inline-flex h-8 items-center gap-1.5 rounded border border-zinc-200 bg-white px-2.5 text-xs text-zinc-700">
              <Filter size={13} />
              {filter}
            </button>
          ))}
        </div>
      </section>

      <section className="overflow-x-auto pb-2">
        <div className="grid min-w-[980px] grid-cols-6 gap-3">
          {columns.map((column) => (
            <div key={column.id} className="min-h-80 rounded border border-zinc-200 bg-white">
              <div className="border-b border-zinc-200 px-3 py-2 text-sm font-semibold">{column.name}</div>
              <div className="space-y-2 p-3">
                {column.tasks.length === 0 ? (
                  <div className="rounded border border-dashed border-zinc-200 bg-zinc-50 px-3 py-6 text-center text-xs text-zinc-500">No task</div>
                ) : column.tasks.map((task) => (
                  <article key={task.id} className="rounded border border-zinc-200 bg-zinc-50 p-3 text-xs">
                    <div className="flex items-start justify-between gap-2">
                      <div className="font-medium text-zinc-950">{task.title}</div>
                      {task.blocked ? <CircleAlert className="shrink-0 text-red-700" size={15} /> : null}
                    </div>
                    <div className="mt-2 text-zinc-500">owner: {task.owner}</div>
                    <div className="text-zinc-500">risk: {task.risk_level}</div>
                    <div className="mt-2 flex flex-wrap gap-1">
                      {task.repository_badges.map((repo) => (
                        <span key={repo} className="inline-flex items-center gap-1 rounded bg-zinc-200 px-1.5 py-1 text-[11px] text-zinc-700">
                          <GitBranch size={11} />
                          {repo}
                        </span>
                      ))}
                    </div>
                    <div className="mt-2 text-zinc-500">
                      run:{' '}
                      {task.linked_run ? (
                        <Link href={`/runs/${task.linked_run.id}`} className="font-medium text-zinc-900 underline-offset-2 hover:underline">
                          {task.linked_run.status}
                        </Link>
                      ) : 'none'}
                    </div>
                    <div className="mt-1 inline-flex items-center gap-1 text-zinc-500">
                      <ShieldCheck size={12} />
                      wiki: {task.wiki_source_status}
                    </div>
                  </article>
                ))}
              </div>
            </div>
          ))}
        </div>
      </section>

      <section className="mt-4 grid gap-3 xl:grid-cols-[1fr_420px]">
        <div className="rounded border border-zinc-200 bg-white px-4 py-3 text-sm">
          <strong>Recent Runs</strong>
          <div className="mt-2 flex flex-wrap gap-2 text-xs text-zinc-600">
            {recentRuns.length === 0 ? 'No runs yet' : recentRuns.map((run) => (
              <Link key={run.id} href={`/runs/${run.id}`} className="rounded bg-zinc-100 px-2 py-1 hover:bg-zinc-200">
                {run.id} · {run.status} · {run.source_label}
              </Link>
            ))}
          </div>
        </div>
        <div className="rounded border border-zinc-200 bg-white px-4 py-3 text-sm">
          <strong>Project Health</strong>
          <div className="mt-2 text-xs text-zinc-600">
            {health.repositories_total} repos · {health.initialized} initialized · {health.needsGenesis} needs Genesis · {health.staleWikiPages} stale wiki pages
          </div>
        </div>
      </section>
    </AppLayout>
  );
}
