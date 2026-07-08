import AppLayout from '../../Layouts/AppLayout';

export default function WikiShow({ page, revision, dashboard }) {
  return (
    <AppLayout title={`Wiki: ${page.title}`} dashboard={dashboard}>
      <header className="rounded border border-zinc-200 bg-white p-4">
        <h1 className="text-lg font-semibold">{page.title}</h1>
        <div className="mt-2 text-sm text-zinc-500">{page.slug}</div>
      </header>

      <section className="mt-5 rounded border border-zinc-200 bg-white p-4">
        <div className="text-sm font-semibold">Source Banner</div>
        <div className="mt-3 grid gap-2 text-sm text-zinc-700 md:grid-cols-2">
          <div>Source status: <span className="font-medium">{page.source_status}</span></div>
          <div>Source type: <span className="font-medium">{revision.source_type}</span></div>
          <div>Last observed: <span className="font-medium">{revision.created_at ?? 'n/a'}</span></div>
          <div>Updated: <span className="font-medium">{page.updated_at}</span></div>
        </div>
        <div className="mt-3 text-sm text-zinc-700">
          Evidence: {revision.evidence_refs.length === 0 ? 'none recorded' : revision.evidence_refs.map((ref) => ref.path ?? ref.artifact_id ?? ref.description ?? ref.type).join(', ')}
        </div>
      </section>

      <section className="mt-5 rounded border border-zinc-200 bg-white p-4">
        <div className="text-sm font-semibold">Content</div>
        <pre className="mt-3 overflow-x-auto whitespace-pre-wrap text-sm text-zinc-700">{revision.content_markdown}</pre>
      </section>
    </AppLayout>
  );
}
