import { Link } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';

export default function ArtifactsIndex({ project, artifacts, summary, dashboard }) {
  return (
    <AppLayout title="Artifacts" dashboard={dashboard}>
      <section className="rounded border border-zinc-200 bg-white p-4">
        <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <h1 className="text-lg font-semibold">Artifacts</h1>
            <p className="text-sm text-zinc-500">{project ? `${project.name} · uploaded and validated outputs` : 'Uploaded and validated outputs'}</p>
          </div>
        </div>
      </section>

      <section className="mt-5 grid gap-4 md:grid-cols-3">
        <SummaryTile label="Artifacts" value={summary.total_artifacts} />
        <SummaryTile label="Downloadable" value={summary.downloadable_artifacts} />
        <SummaryTile label="Failed/Rejected" value={summary.failed_artifacts} />
      </section>

      <section className="mt-5 overflow-hidden rounded border border-zinc-200 bg-white">
        <div className="overflow-x-auto">
          <table className="min-w-full text-left text-sm">
            <thead className="bg-zinc-50 text-xs uppercase text-zinc-500">
              <tr>
                <th className="px-4 py-3 font-medium">Artifact</th>
                <th className="px-4 py-3 font-medium">Repository</th>
                <th className="px-4 py-3 font-medium">Status</th>
                <th className="px-4 py-3 font-medium">Source</th>
                <th className="px-4 py-3 font-medium">Size</th>
                <th className="px-4 py-3 font-medium">Run</th>
                <th className="px-4 py-3 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {artifacts.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-4 py-8 text-center text-sm text-zinc-500">No artifacts yet.</td>
                </tr>
              ) : artifacts.map((artifact) => (
                <tr key={artifact.id} className="border-t border-zinc-200 align-top">
                  <td className="px-4 py-3">
                    <div className="font-medium text-zinc-950">{artifact.artifact_type}</div>
                    <div className="mt-1 text-xs text-zinc-500">{artifact.mime_type}</div>
                  </td>
                  <td className="px-4 py-3 text-zinc-600">{artifact.repository_name ?? 'none'}</td>
                  <td className="px-4 py-3 text-zinc-600">{artifact.status}</td>
                  <td className="px-4 py-3 text-zinc-600">{artifact.source_label}</td>
                  <td className="px-4 py-3 text-zinc-600">{artifact.size_bytes}</td>
                  <td className="px-4 py-3">
                    {artifact.run ? (
                      <Link href={artifact.run.href} className="text-zinc-900 underline-offset-2 hover:underline">
                        {artifact.run.id}
                      </Link>
                    ) : (
                      <span className="text-zinc-500">none</span>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    {artifact.download_href ? (
                      <a href={artifact.download_href} className="rounded border border-zinc-200 bg-white px-2 py-1 text-xs text-zinc-700 hover:bg-zinc-100">
                        download
                      </a>
                    ) : (
                      <span className="text-xs text-zinc-500">not available</span>
                    )}
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

function SummaryTile({ label, value }) {
  return (
    <div className="rounded border border-zinc-200 bg-white px-4 py-3">
      <div className="text-xs text-zinc-500">{label}</div>
      <div className="mt-1 text-sm font-medium">{value}</div>
    </div>
  );
}
