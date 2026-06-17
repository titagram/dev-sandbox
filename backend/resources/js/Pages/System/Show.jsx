import AppLayout from '../../Layouts/AppLayout';

export default function SystemShow({ project, health, runtime, dashboard }) {
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
