import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { AlertTriangle, ArrowUpRight, Bot, CheckCircle2, RadioTower, ShieldCheck, XCircle } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';

export default function TaskShow({ task, assistant, dashboard }) {
  const [suggestion, setSuggestion] = useState(assistant?.latest_suggestion ?? null);
  const [taskDescription, setTaskDescription] = useState(task.description ?? '');
  const [clarifying, setClarifying] = useState(false);
  const [resolving, setResolving] = useState(null);
  const [error, setError] = useState(null);

  async function clarifyTask() {
    setClarifying(true);
    setError(null);

    const response = await fetch(assistant.clarify_href, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
      },
    });
    const payload = await response.json().catch(() => ({}));
    setClarifying(false);

    if (!response.ok) {
      setError(payload.message ?? 'Task clarification failed.');
      return;
    }

    setSuggestion(payload.suggestion);
  }

  async function applySuggestion() {
    if (!suggestion?.id) {
      return;
    }

    setResolving('applied');
    setError(null);

    const response = await fetch(`${assistant.apply_suggestion_href}/${suggestion.id}/apply`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
      },
    });
    const payload = await response.json().catch(() => ({}));
    setResolving(null);

    if (!response.ok) {
      setError(payload.message ?? payload.error?.message ?? 'Suggestion apply failed.');
      return;
    }

    setSuggestion(payload.suggestion);
    if (payload.task?.description !== undefined) {
      setTaskDescription(payload.task.description ?? '');
    }
  }

  async function resolveSuggestion(status) {
    if (!suggestion?.id) {
      return;
    }

    setResolving(status);
    setError(null);

    const response = await fetch(`${assistant.resolve_suggestion_href}/${suggestion.id}`, {
      method: 'PATCH',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
      },
      body: JSON.stringify({ status }),
    });
    const payload = await response.json().catch(() => ({}));
    setResolving(null);

    if (!response.ok) {
      setError(payload.message ?? 'Suggestion update failed.');
      return;
    }

    setSuggestion(payload.suggestion);
  }

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
          <p className="mt-2 whitespace-pre-wrap text-sm text-zinc-600">{taskDescription || 'No description.'}</p>
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
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-2 text-sm font-semibold">
            <Bot size={16} />
            Task Clarifier
          </div>
          {assistant?.can_clarify ? (
            <button
              className="inline-flex h-9 items-center gap-2 rounded bg-zinc-950 px-3 text-sm font-medium text-white disabled:opacity-60"
              disabled={clarifying}
              type="button"
              onClick={clarifyTask}
            >
              <Bot size={14} />
              {clarifying ? 'Clarifying' : 'Clarify task'}
            </button>
          ) : null}
        </div>

        {error ? <div className="mt-3 rounded border border-red-200 bg-red-50 p-3 text-xs text-red-800">{error}</div> : null}

        {suggestion ? (
          <div className="mt-4 grid gap-4 xl:grid-cols-2">
            <SuggestionList title="Questions" items={suggestion.structured_payload?.questions ?? []} />
            <SuggestionList title="Acceptance criteria" items={suggestion.structured_payload?.acceptance_criteria ?? []} />
            <SuggestionList title="Risks" items={suggestion.structured_payload?.risks ?? []} />
            <SuggestionList title="Missing context" items={suggestion.structured_payload?.missing_context ?? []} />
            <div className="rounded border border-zinc-200 bg-zinc-50 p-3 text-sm xl:col-span-2">
              <div className="text-xs text-zinc-500">Suggestion state</div>
              <div className="mt-2 flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-zinc-700">
                  <span>Status: {suggestion.status}</span>
                  <span>Confidence: {Math.round(Number(suggestion.confidence ?? 0) * 100)}%</span>
                  <span>Approval: {suggestion.approval_required ? 'required' : 'not required'}</span>
                  {suggestion.resolved_at ? <span>Resolved: {suggestion.resolved_at}</span> : null}
                </div>
                {assistant?.can_clarify && suggestion.status === 'pending' ? (
                  <div className="flex flex-wrap items-center gap-2">
                    <button
                      className="inline-flex h-8 items-center gap-2 rounded border border-emerald-200 bg-emerald-50 px-3 text-xs font-medium text-emerald-800 disabled:opacity-60"
                      disabled={Boolean(resolving)}
                      type="button"
                      onClick={() => resolveSuggestion('accepted')}
                    >
                      <CheckCircle2 size={14} />
                      {resolving === 'accepted' ? 'Accepting' : 'Accept'}
                    </button>
                    <button
                      className="inline-flex h-8 items-center gap-2 rounded border border-red-200 bg-red-50 px-3 text-xs font-medium text-red-800 disabled:opacity-60"
                      disabled={Boolean(resolving)}
                      type="button"
                      onClick={() => resolveSuggestion('rejected')}
                    >
                      <XCircle size={14} />
                      {resolving === 'rejected' ? 'Rejecting' : 'Reject'}
                    </button>
                  </div>
                ) : null}
                {assistant?.can_clarify && suggestion.status === 'accepted' ? (
                  <button
                    className="inline-flex h-8 items-center gap-2 rounded border border-emerald-200 bg-emerald-50 px-3 text-xs font-medium text-emerald-800 disabled:opacity-60"
                    disabled={Boolean(resolving)}
                    type="button"
                    onClick={applySuggestion}
                  >
                    <CheckCircle2 size={14} />
                    {resolving === 'applied' ? 'Applying' : 'Apply'}
                  </button>
                ) : null}
              </div>
            </div>
          </div>
        ) : (
          <div className="mt-3 text-sm text-zinc-500">No clarification suggestion yet.</div>
        )}
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

function SuggestionList({ title, items }) {
  return (
    <div className="rounded border border-zinc-200 bg-zinc-50 p-3">
      <div className="text-sm font-medium">{title}</div>
      {items.length ? (
        <ul className="mt-2 space-y-1 text-sm text-zinc-600">
          {items.map((item) => (
            <li key={item} className="leading-6">{item}</li>
          ))}
        </ul>
      ) : (
        <div className="mt-2 text-sm text-zinc-500">None.</div>
      )}
    </div>
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
