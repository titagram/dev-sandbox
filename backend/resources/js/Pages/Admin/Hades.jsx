import { router } from '@inertiajs/react';
import { Clipboard, KeyRound, Play, ShieldCheck, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

export default function Hades({ dashboard, projects, bootstrapTokens, workspaces, jobs, memoryProposals }) {
  const firstProject = projects[0]?.id ?? '';
  const firstWorkspace = workspaces[0]?.id ?? '';
  const [projectId, setProjectId] = useState(firstProject);
  const [workspaceId, setWorkspaceId] = useState(firstWorkspace);
  const [name, setName] = useState('Local Hades bootstrap');
  const [expiresInDays, setExpiresInDays] = useState(90);
  const [created, setCreated] = useState(null);
  const [jobPayload, setJobPayload] = useState('{"paths":["README.md"]}');
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  const selectedProjectWorkspaces = useMemo(() => workspaces.filter((workspace) => workspace.project_id === projectId), [projectId, workspaces]);

  async function createBootstrapToken(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    const response = await fetch('/api/dashboard/admin/hades/bootstrap-tokens', {
      method: 'POST',
      headers: requestHeaders(),
      body: JSON.stringify({ project_id: projectId, name, expires_in_days: Number(expiresInDays) }),
    });

    setBusy(false);

    if (!response.ok) {
      setError('Bootstrap token creation failed.');
      return;
    }

    setCreated(await response.json());
    router.reload({ only: ['bootstrapTokens'] });
  }

  async function revokeBootstrapToken(tokenId) {
    setBusy(true);
    setError(null);

    const response = await fetch(`/api/dashboard/admin/hades/bootstrap-tokens/${tokenId}`, {
      method: 'DELETE',
      headers: requestHeaders(),
    });

    setBusy(false);

    if (!response.ok) {
      setError('Bootstrap token revocation failed.');
      return;
    }

    router.reload({ only: ['bootstrapTokens'] });
  }

  async function createJob(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    let payload;
    try {
      payload = JSON.parse(jobPayload);
    } catch {
      setBusy(false);
      setError('Job payload must be valid JSON.');
      return;
    }

    const response = await fetch('/api/dashboard/admin/hades/jobs', {
      method: 'POST',
      headers: requestHeaders(),
      body: JSON.stringify({ project_id: projectId, workspace_binding_id: workspaceId, capability: 'read_files', policy: 'manual_review', requires_confirmation: true, payload }),
    });

    setBusy(false);

    if (!response.ok) {
      setError('Job creation failed.');
      return;
    }

    router.reload({ only: ['jobs'] });
  }

  return (
    <AppLayout title="Admin / Hades" dashboard={dashboard}>
      <h1 className="text-xl font-semibold">Admin / Hades</h1>

      {error ? <div className="mt-3 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{error}</div> : null}

      <section className="mt-4 rounded border border-zinc-200 bg-white p-4">
        <div className="flex items-center gap-2 font-semibold"><KeyRound size={16} /> Bootstrap tokens</div>
        <form onSubmit={createBootstrapToken} className="mt-3 grid gap-3 lg:grid-cols-[minmax(180px,1fr)_minmax(180px,1fr)_120px_auto]">
          <Select label="Project" value={projectId} onChange={(event) => setProjectId(event.target.value)} options={projects.map((project) => [project.id, project.name])} />
          <Input label="Name" value={name} onChange={(event) => setName(event.target.value)} />
          <Input label="Days" type="number" min="1" max="365" value={expiresInDays} onChange={(event) => setExpiresInDays(event.target.value)} />
          <button disabled={busy || !projectId} type="submit" className="mt-5 h-9 rounded bg-zinc-950 px-3 text-sm font-medium text-white disabled:opacity-60">Create</button>
        </form>
        {created ? <OneTimeInstall payload={created} /> : null}
      </section>

      <section className="mt-4 rounded border border-zinc-200 bg-white p-4">
        <div className="flex items-center gap-2 font-semibold"><Play size={16} /> Manual job</div>
        <form onSubmit={createJob} className="mt-3 grid gap-3 lg:grid-cols-[minmax(180px,1fr)_minmax(180px,1fr)_minmax(220px,2fr)_auto]">
          <Select label="Project" value={projectId} onChange={(event) => setProjectId(event.target.value)} options={projects.map((project) => [project.id, project.name])} />
          <Select label="Workspace" value={workspaceId} onChange={(event) => setWorkspaceId(event.target.value)} options={selectedProjectWorkspaces.map((workspace) => [workspace.id, workspace.display_path])} />
          <Input label="Payload JSON" value={jobPayload} onChange={(event) => setJobPayload(event.target.value)} />
          <button disabled={busy || !workspaceId} type="submit" className="mt-5 h-9 rounded bg-zinc-950 px-3 text-sm font-medium text-white disabled:opacity-60">Queue</button>
        </form>
      </section>

      <DataTable title="Bootstrap Tokens" rows={bootstrapTokens} columns={['token_prefix', 'project_name', 'name', 'last_used_at', 'revoked_at']} action={(row) => (
        <button type="button" disabled={busy || Boolean(row.revoked_at)} className="inline-flex h-8 w-8 items-center justify-center rounded border border-zinc-200 text-zinc-600 disabled:opacity-40" onClick={() => revokeBootstrapToken(row.id)} title="Revoke token"><Trash2 size={14} /></button>
      )} />
      <DataTable title="Linked Workspaces" rows={workspaces} columns={['project_name', 'display_path', 'agent_label', 'status']} />
      <DataTable title="Jobs" rows={jobs} columns={['id', 'capability', 'status', 'policy', 'created_at']} />
      <DataTable title="Memory Proposals" rows={memoryProposals} columns={['id', 'intent', 'status', 'reason_code', 'created_at']} />
    </AppLayout>
  );
}

function requestHeaders() {
  return {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
  };
}

function Input({ label, ...props }) {
  return <label className="text-sm"><span className="mb-1 block text-xs text-zinc-500">{label}</span><input className="h-9 w-full rounded border border-zinc-300 px-3 text-sm" {...props} /></label>;
}

function Select({ label, options, ...props }) {
  return <label className="text-sm"><span className="mb-1 block text-xs text-zinc-500">{label}</span><select className="h-9 w-full rounded border border-zinc-300 px-3 text-sm" {...props}>{options.map(([value, text]) => <option key={value} value={value}>{text}</option>)}</select></label>;
}

function OneTimeInstall({ payload }) {
  return <div className="mt-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm"><div className="flex items-center gap-2 font-medium text-amber-950"><ShieldCheck size={15} /> Copy these now. The token is shown once.</div><CopyLine label="POSIX" value={payload.install.posix} /><CopyLine label="Windows" value={payload.install.windows} /></div>;
}

function CopyLine({ label, value }) {
  return <div className="mt-2 flex items-center gap-2"><span className="w-16 text-xs text-amber-900">{label}</span><code className="min-w-0 flex-1 overflow-x-auto rounded bg-white px-2 py-1 text-xs">{value}</code><button type="button" className="inline-flex h-8 items-center gap-1 rounded border border-amber-300 px-2 text-xs" onClick={() => navigator.clipboard?.writeText(value)}><Clipboard size={13} />Copy</button></div>;
}

function DataTable({ title, rows, columns, action }) {
  return <section className="mt-4 overflow-hidden rounded border border-zinc-200 bg-white"><div className="border-b border-zinc-200 px-4 py-3 text-sm font-semibold">{title}</div><div className="overflow-x-auto"><table className="min-w-[920px] w-full text-left text-sm"><thead className="bg-zinc-50 text-xs text-zinc-500"><tr>{columns.map((column) => <th key={column} className="p-3">{column}</th>)}{action ? <th className="p-3">actions</th> : null}</tr></thead><tbody>{rows.map((row) => <tr key={row.id} className="border-t border-zinc-100">{columns.map((column) => <td key={column} className="max-w-xs truncate p-3 font-mono text-xs">{String(row[column] ?? '')}</td>)}{action ? <td className="p-3">{action(row)}</td> : null}</tr>)}</tbody></table></div></section>;
}
