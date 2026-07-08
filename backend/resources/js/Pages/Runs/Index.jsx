import { Link } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';

export default function RunsIndex({ project, runs, dashboard }) {
  return (
    <AppLayout title="Runs" dashboard={dashboard}>
      <section className="rounded border border-zinc-200 bg-white p-4">
        <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <h1 className="text-lg font-semibold">Runs</h1>
            <p className="text-sm text-zinc-500">{project ? `${project.name} · local plugin activity` : 'Local plugin activity'}</p>
          </div>
          <div className="text-xs text-zinc-500">{runs.length} runs</div>
        </div>
      </section>

      <section className="mt-5 overflow-hidden rounded border border-zinc-200 bg-white">
        <div className="overflow-x-auto">
          <table className="min-w-full text-left text-sm">
            <thead className="bg-zinc-50 text-xs uppercase text-zinc-500">
              <tr>
                <th className="px-4 py-3 font-medium">Run</th>
                <th className="px-4 py-3 font-medium">Kind</th>
                <th className="px-4 py-3 font-medium">Repo</th>
                <th className="px-4 py-3 font-medium">Task</th>
                <th className="px-4 py-3 font-medium">Status</th>
                <th className="px-4 py-3 font-medium">Risk</th>
                <th className="px-4 py-3 font-medium">Source</th>
                <th className="px-4 py-3 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {runs.length === 0 ? (
                <tr>
                  <td colSpan={8} className="px-4 py-8 text-center text-sm text-zinc-500">No runs yet.</td>
                </tr>
              ) : runs.map((run) => (
                <tr key={run.id} className="border-t border-zinc-200 align-top">
                  <td className="px-4 py-3">
                    <div className="font-medium text-zinc-950">{run.id}</div>
                    <div className="mt-1 text-xs text-zinc-500">{run.branch}</div>
                  </td>
                  <td className="px-4 py-3 text-zinc-600">{run.kind}</td>
                  <td className="px-4 py-3 text-zinc-600">{run.repository_name ?? 'none'}</td>
                  <td className="px-4 py-3">
                    {run.task ? (
                      <Link href={run.task.href} className="text-zinc-900 underline-offset-2 hover:underline">
                        {run.task.title}
                      </Link>
                    ) : (
                      <span className="text-zinc-500">none</span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-zinc-600">{run.status}</td>
                  <td className="px-4 py-3 text-zinc-600">{run.risk_level}</td>
                  <td className="px-4 py-3 text-zinc-600">{run.source_label}</td>
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-2">
                      <Link href={run.detail_href} className="rounded border border-zinc-200 bg-white px-2 py-1 text-xs text-zinc-700 hover:bg-zinc-100">
                        open run
                      </Link>
                      {run.graph_href ? (
                        <Link href={run.graph_href} className="rounded border border-zinc-200 bg-white px-2 py-1 text-xs text-zinc-700 hover:bg-zinc-100">
                          open graph
                        </Link>
                      ) : null}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </AppLayout>
  );
}
