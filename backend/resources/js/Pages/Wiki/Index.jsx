import { Link } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';

export default function WikiIndex({ project, pages, summary, sourceLabel, dashboard }) {
  return (
    <AppLayout title="Wiki" dashboard={dashboard}>
      <section className="rounded border border-zinc-200 bg-white p-4">
        <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <h1 className="text-lg font-semibold">Wiki</h1>
            <p className="text-sm text-zinc-500">{project ? `${project.name} · technical and business knowledge` : 'Technical and business knowledge'}</p>
          </div>
          <div className="text-xs text-zinc-500">{sourceLabel}</div>
        </div>
      </section>

      <section className="mt-5 grid gap-4 md:grid-cols-3">
        <SummaryTile label="Pages" value={summary.total_pages} />
        <SummaryTile label="Technical" value={summary.technical_pages} />
        <SummaryTile label="Stale" value={summary.stale_pages} />
      </section>

      <section className="mt-5 overflow-hidden rounded border border-zinc-200 bg-white">
        <div className="overflow-x-auto">
          <table className="min-w-full text-left text-sm">
            <thead className="bg-zinc-50 text-xs uppercase text-zinc-500">
              <tr>
                <th className="px-4 py-3 font-medium">Page</th>
                <th className="px-4 py-3 font-medium">Type</th>
                <th className="px-4 py-3 font-medium">Repository</th>
                <th className="px-4 py-3 font-medium">Source status</th>
                <th className="px-4 py-3 font-medium">Source type</th>
                <th className="px-4 py-3 font-medium">Evidence</th>
                <th className="px-4 py-3 font-medium">Updated</th>
              </tr>
            </thead>
            <tbody>
              {pages.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-4 py-8 text-center text-sm text-zinc-500">No wiki pages yet.</td>
                </tr>
              ) : pages.map((page) => (
                <tr key={page.id} className="border-t border-zinc-200">
                  <td className="px-4 py-3">
                    <Link href={page.href} className="font-medium text-zinc-950 underline-offset-2 hover:underline">
                      {page.title}
                    </Link>
                    <div className="mt-1 text-xs text-zinc-500">{page.slug}</div>
                  </td>
                  <td className="px-4 py-3 text-zinc-600">{page.page_type}</td>
                  <td className="px-4 py-3 text-zinc-600">{page.repository_name ?? 'none'}</td>
                  <td className="px-4 py-3 text-zinc-600">{page.source_status}</td>
                  <td className="px-4 py-3 text-zinc-600">{page.source_type}</td>
                  <td className="px-4 py-3 text-zinc-600">{page.evidence_count}</td>
                  <td className="px-4 py-3 text-zinc-600">{page.updated_at}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </AppLayout>
  );
}

function SummaryTile({ label, value }) {
  return (
    <div className="rounded border border-zinc-200 bg-white px-4 py-3">
      <div className="text-xs text-zinc-500">{label}</div>
      <div className="mt-1 text-sm font-medium">{value}</div>
    </div>
  );
}
