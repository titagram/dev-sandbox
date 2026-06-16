import { Link } from '@inertiajs/react';
import { Database, FileText, GitBranch, ShieldCheck } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';

export default function ProjectShow({ project, repositories, recentRuns, artifacts, wikiPages, policySummary, dashboard }) {
  return (
    <AppLayout title={`Project: ${project.name}`} dashboard={dashboard}>
      <header className="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
        <div>
          <h1 className="text-xl font-semibold">{project.name}</h1>
          <p className="mt-1 text-sm text-zinc-500">
            Status: {project.status} · Git mode: {policySummary.git_mode} · Workspace: {policySummary.workspace}
          </p>
        </div>
        <div className="rounded border border-zinc-200 bg-white px-3 py-2 text-xs text-zinc-600">
          Policy: {policySummary.code_exposure_policy}
        </div>
      </header>

      <section className="mt-5 overflow-hidden rounded border border-zinc-200 bg-white">
        <div className="flex items-center gap-2 border-b border-zinc-200 px-4 py-3 text-sm font-semibold">
          <GitBranch size={16} />
          Repositories
        </div>
        <div className="overflow-x-auto">
          <table className="min-w-[980px] w-full text-left text-sm">
            <thead className="bg-zinc-50 text-xs text-zinc-500">
              <tr>
                <th className="p-3">name</th>
                <th>branch</th>
                <th>git</th>
                <th>last local snapshot</th>
                <th>Genesis</th>
                <th>Delta</th>
                <th>Graph</th>
                <th>Wiki</th>
                <th>risk</th>
                <th>latest run</th>
              </tr>
            </thead>
            <tbody>
              {repositories.map((repo) => (
                <tr key={repo.repository_id} className="border-t border-zinc-100">
                  <td className="p-3 font-medium">{repo.name}</td>
                  <td>{repo.default_branch}</td>
                  <td>{repo.git_mode}</td>
                  <td>{repo.last_local_snapshot ?? 'none'}</td>
                  <td>{repo.genesis_status}</td>
                  <td>{repo.delta_status}</td>
                  <td>{repo.graph_status}</td>
                  <td>{repo.wiki_status}</td>
                  <td>{repo.risk_level}</td>
                  <td>
                    {repo.latest_run ? <Link className="underline-offset-2 hover:underline" href={`/runs/${repo.latest_run}`}>{repo.latest_run}</Link> : 'none'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <section className="mt-5 grid gap-4 xl:grid-cols-[1fr_420px]">
        <Panel title="Recent Activity" icon={Database} items={recentRuns.map((run) => `${run.id} ${run.status} ${run.source_label}`)} />
        <Panel title="Artifacts" icon={FileText} items={artifacts.map((artifact) => `${artifact.artifact_type} ${artifact.status}`)} />
      </section>

      <section className="mt-5 rounded border border-zinc-200 bg-white">
        <div className="flex items-center gap-2 border-b border-zinc-200 px-4 py-3 text-sm font-semibold">
          <ShieldCheck size={16} />
          Wiki Source Status
        </div>
        <div className="divide-y divide-zinc-100">
          {wikiPages.length === 0 ? (
            <div className="px-4 py-4 text-sm text-zinc-500">No wiki pages yet.</div>
          ) : wikiPages.map((page) => (
            <div key={page.id} className="grid gap-2 px-4 py-3 text-sm md:grid-cols-[220px_1fr]">
              <div>
                <div className="font-medium">{page.title}</div>
                <div className="text-xs text-zinc-500">{page.slug}</div>
              </div>
              <div className="text-xs text-zinc-600">
                <div>Source status: <span className="font-medium text-zinc-900">{page.source_status}</span></div>
                <div>Source type: <span className="font-medium text-zinc-900">{page.source_type}</span></div>
                <div>Evidence: {page.evidence_refs.length === 0 ? 'none recorded' : page.evidence_refs.map((ref) => ref.path ?? ref.description ?? ref.type).join(', ')}</div>
              </div>
            </div>
          ))}
        </div>
      </section>
    </AppLayout>
  );
}

function Panel({ title, icon: Icon, items }) {
  return (
    <div className="rounded border border-zinc-200 bg-white p-4 text-sm">
      <div className="flex items-center gap-2 font-semibold">
        <Icon size={16} />
        {title}
      </div>
      <div className="mt-2 space-y-1 text-zinc-600">{items.length ? items.map((item) => <div key={item}>{item}</div>) : 'none'}</div>
    </div>
  );
}
