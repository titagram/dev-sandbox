import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { Bot, Database, FileText, GitBranch, ListChecks, MessageSquare, Network, Search, Send, ShieldCheck } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';

export default function ProjectShow({ project, repositories, recentRuns, artifacts, wikiPages, policySummary, assistant, agentWork, memory, dashboard }) {
  const [triageSuggestion, setTriageSuggestion] = useState(assistant?.latest_backlog_triage_suggestion ?? null);
  const [triaging, setTriaging] = useState(false);
  const [triageError, setTriageError] = useState(null);
  const [workItems, setWorkItems] = useState(agentWork?.items ?? []);
  const [selectedWork, setSelectedWork] = useState(null);
  const [workDetailLoading, setWorkDetailLoading] = useState(false);
  const [workDetailError, setWorkDetailError] = useState(null);
  const [chatForm, setChatForm] = useState({ agent: 'socrates', title: '', prompt: '' });
  const [chatSubmitting, setChatSubmitting] = useState(false);
  const [chatError, setChatError] = useState(null);
  const [memoryView, setMemoryView] = useState(memory ?? { domain: 'all', query: null, domains: {}, entries: [] });
  const [memoryDomain, setMemoryDomain] = useState('all');
  const [memoryQuery, setMemoryQuery] = useState('');
  const [memoryLoading, setMemoryLoading] = useState(false);
  const [memoryError, setMemoryError] = useState(null);

  const agentOptions = (assistant?.agent_options ?? [
    { agent_key: 'local_agent', label: 'Local agent' },
  ]).map((agent) => ({
    key: agent.agent_key,
    label: agent.label,
    description: agent.description ?? '',
  }));

  async function runBacklogTriage() {
    setTriaging(true);
    setTriageError(null);

    const response = await fetch(assistant.triage_href, {
      method: 'POST',
      headers: jsonHeaders(),
    });
    const payload = await response.json().catch(() => ({}));
    setTriaging(false);

    if (!response.ok) {
      setTriageError(payload.message ?? payload.error?.message ?? 'Backlog triage failed.');
      return;
    }

    setTriageSuggestion(payload.suggestion);
  }

  async function loadWorkDetail(workId) {
    setWorkDetailLoading(true);
    setWorkDetailError(null);

    const response = await fetch(`${assistant.agent_work_href}/${workId}`, {
      headers: { Accept: 'application/json' },
    });
    const payload = await response.json().catch(() => ({}));
    setWorkDetailLoading(false);

    if (!response.ok) {
      setWorkDetailError(payload.message ?? payload.error?.message ?? 'Agent work detail failed.');
      return;
    }

    setSelectedWork(payload.item);
  }

  async function submitAgentQuestion(event) {
    event.preventDefault();
    setChatSubmitting(true);
    setChatError(null);

    const title = chatForm.title.trim() || `${agentLabel(chatForm.agent, agentOptions)} chat`;
    const response = await fetch(assistant.agent_work_href, {
      method: 'POST',
      headers: jsonHeaders(),
      body: JSON.stringify({
        assigned_agent_key: chatForm.agent,
        priority: 'normal',
        title,
        prompt: chatForm.prompt,
        payload: {
          source: 'project_agent_chat',
          question: chatForm.prompt,
        },
        requires_memory_entry: true,
      }),
    });
    const payload = await response.json().catch(() => ({}));
    setChatSubmitting(false);

    if (!response.ok) {
      setChatError(payload.message ?? payload.error?.message ?? 'Agent request failed.');
      return;
    }

    setWorkItems((current) => [payload, ...current.filter((item) => item.id !== payload.id)]);
    setChatForm((current) => ({ ...current, title: '', prompt: '' }));
    loadWorkDetail(payload.id);
  }

  async function loadMemory(nextDomain = memoryDomain, nextQuery = memoryQuery) {
    setMemoryLoading(true);
    setMemoryError(null);
    setMemoryDomain(nextDomain);

    const params = new URLSearchParams();
    if (nextDomain && nextDomain !== 'all') {
      params.set('domain', nextDomain);
    }
    if (nextQuery.trim()) {
      params.set('q', nextQuery.trim());
    }

    const suffix = params.toString() ? `?${params.toString()}` : '';
    const response = await fetch(`/api/dashboard/projects/${project.id}/memory${suffix}`, {
      headers: { Accept: 'application/json' },
    });
    const payload = await response.json().catch(() => ({}));
    setMemoryLoading(false);

    if (!response.ok) {
      setMemoryError(payload.message ?? payload.error?.message ?? 'Memory query failed.');
      return;
    }

    setMemoryView(payload);
  }

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

      <section className="mt-5 rounded border border-zinc-200 bg-white p-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-2 text-sm font-semibold">
            <Bot size={16} />
            Backlog Triage
          </div>
          {assistant?.can_triage ? (
            <button
              className="inline-flex h-9 items-center gap-2 rounded bg-zinc-950 px-3 text-sm font-medium text-white disabled:opacity-60"
              disabled={triaging}
              type="button"
              onClick={runBacklogTriage}
            >
              <Bot size={14} />
              {triaging ? 'Triaging' : 'Triage backlog'}
            </button>
          ) : null}
        </div>

        {triageError ? <div className="mt-3 rounded border border-red-200 bg-red-50 p-3 text-xs text-red-800">{triageError}</div> : null}

        {triageSuggestion ? (
          <div className="mt-4 grid gap-4 xl:grid-cols-2">
            <div className="rounded border border-zinc-200 bg-zinc-50 p-3 text-sm xl:col-span-2">
              <div className="text-xs text-zinc-500">Summary</div>
              <div className="mt-2 text-zinc-700">{triageSuggestion.structured_payload?.summary ?? triageSuggestion.title}</div>
              <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-500">
                <span>Status: {triageSuggestion.status}</span>
                <span>Confidence: {Math.round(Number(triageSuggestion.confidence ?? 0) * 100)}%</span>
              </div>
            </div>
            <TriageList
              title="Groups"
              items={(triageSuggestion.structured_payload?.groups ?? []).map((group) => `${group.label}: ${group.reason}`)}
            />
            <TriageList
              title="Recommendations"
              items={(triageSuggestion.structured_payload?.recommendations ?? []).map((recommendation) => `${recommendation.title}: ${recommendation.body}`)}
            />
            <TriageList title="Risks" items={triageSuggestion.structured_payload?.risks ?? []} />
          </div>
        ) : (
          <div className="mt-3 text-sm text-zinc-500">No backlog triage suggestion yet.</div>
        )}
      </section>

      <section className="mt-5 grid gap-4 xl:grid-cols-[380px_1fr]">
        <form onSubmit={submitAgentQuestion} className="rounded border border-zinc-200 bg-white p-4">
          <div className="flex items-center gap-2 text-sm font-semibold">
            <MessageSquare size={16} />
            Agent Chat
          </div>
          {chatError ? <div className="mt-3 rounded border border-red-200 bg-red-50 p-3 text-xs text-red-800">{chatError}</div> : null}
          <div className="mt-4 grid gap-3">
            <label className="text-sm">
              <span className="mb-1 block text-xs text-zinc-500">Agent</span>
              <select
                className="h-9 w-full rounded border border-zinc-300 bg-white px-3 text-sm"
                value={chatForm.agent}
                onChange={(event) => setChatForm((current) => ({ ...current, agent: event.target.value }))}
              >
                {agentOptions.map((agent) => (
                  <option key={agent.key} value={agent.key}>{agent.label}</option>
                ))}
              </select>
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-zinc-500">Title</span>
              <input
                className="h-9 w-full rounded border border-zinc-300 px-3 text-sm"
                value={chatForm.title}
                onChange={(event) => setChatForm((current) => ({ ...current, title: event.target.value }))}
              />
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-zinc-500">Message</span>
              <textarea
                className="min-h-28 w-full rounded border border-zinc-300 px-3 py-2 text-sm"
                value={chatForm.prompt}
                onChange={(event) => setChatForm((current) => ({ ...current, prompt: event.target.value }))}
              />
            </label>
            <button
              className="inline-flex h-9 items-center justify-center gap-2 rounded bg-zinc-950 px-3 text-sm font-medium text-white disabled:opacity-60"
              disabled={chatSubmitting || chatForm.prompt.trim().length < 8}
              type="submit"
            >
              <Send size={14} />
              {chatSubmitting ? 'Sending' : 'Send'}
            </button>
          </div>
        </form>

        <div className="rounded border border-zinc-200 bg-white p-4">
          <div className="flex items-center gap-2 text-sm font-semibold">
            <ListChecks size={16} />
            Agent Work Detail
          </div>
          {workDetailError ? <div className="mt-3 rounded border border-red-200 bg-red-50 p-3 text-xs text-red-800">{workDetailError}</div> : null}
          {workDetailLoading ? (
            <div className="mt-4 text-sm text-zinc-500">Loading.</div>
          ) : selectedWork ? (
            <WorkDetail work={selectedWork} />
          ) : (
            <div className="mt-4 text-sm text-zinc-500">Select a work item.</div>
          )}
        </div>
      </section>

      <section className="mt-5 rounded border border-zinc-200 bg-white">
        <div className="flex flex-col gap-3 border-b border-zinc-200 px-4 py-3 lg:flex-row lg:items-center lg:justify-between">
          <div className="flex items-center gap-2 text-sm font-semibold">
            <Bot size={16} />
            Agent Work
          </div>
          <div className="flex flex-wrap gap-2 text-xs text-zinc-500">
            <span>Queued: {workItems.filter((item) => item.status === 'queued').length}</span>
            <span>Running: {workItems.filter((item) => item.status === 'running').length}</span>
            <span>Done: {workItems.filter((item) => item.status === 'completed').length}</span>
          </div>
        </div>
        <div className="divide-y divide-zinc-100">
          {workItems.length === 0 ? (
            <div className="px-4 py-4 text-sm text-zinc-500">No agent work yet.</div>
          ) : workItems.slice(0, 12).map((item) => (
            <button
              key={item.id}
              className="grid w-full gap-2 px-4 py-3 text-left text-sm hover:bg-zinc-50 lg:grid-cols-[180px_1fr_120px_120px]"
              type="button"
              onClick={() => loadWorkDetail(item.id)}
            >
              <div className="text-xs text-zinc-500">{agentLabel(item.assigned_agent_key, agentOptions)}</div>
              <div>
                <div className="font-medium text-zinc-900">{item.title}</div>
                <div className="mt-1 line-clamp-1 text-xs text-zinc-500">{item.prompt}</div>
              </div>
              <StatusBadge value={item.status} />
              <div className="text-xs text-zinc-500">{item.priority}</div>
            </button>
          ))}
        </div>
      </section>

      <section className="mt-5 rounded border border-zinc-200 bg-white p-4">
        <div className="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
          <div className="flex items-center gap-2 text-sm font-semibold">
            <Database size={16} />
            Memory Domains
          </div>
          <form
            className="flex flex-col gap-2 sm:flex-row"
            onSubmit={(event) => {
              event.preventDefault();
              loadMemory(memoryDomain, memoryQuery);
            }}
          >
            <select
              className="h-9 rounded border border-zinc-300 bg-white px-3 text-sm"
              value={memoryDomain}
              onChange={(event) => loadMemory(event.target.value, memoryQuery)}
            >
              <option value="all">All</option>
              <option value="logbook">Logbook</option>
              <option value="wiki">Wiki</option>
              <option value="agent_notes">Agent notes</option>
            </select>
            <input
              className="h-9 min-w-0 rounded border border-zinc-300 px-3 text-sm sm:w-72"
              value={memoryQuery}
              onChange={(event) => setMemoryQuery(event.target.value)}
            />
            <button
              className="inline-flex h-9 items-center justify-center gap-2 rounded border border-zinc-300 px-3 text-sm font-medium text-zinc-700 hover:bg-zinc-50 disabled:opacity-60"
              disabled={memoryLoading}
              type="submit"
            >
              <Search size={14} />
              {memoryLoading ? 'Searching' : 'Search'}
            </button>
          </form>
        </div>
        {memoryError ? <div className="mt-3 rounded border border-red-200 bg-red-50 p-3 text-xs text-red-800">{memoryError}</div> : null}
        <div className="mt-3 flex flex-wrap gap-2 text-xs text-zinc-500">
          <span>Logbook: {memoryView.domains?.logbook ?? 0}</span>
          <span>Wiki: {memoryView.domains?.wiki ?? 0}</span>
          <span>Agent notes: {memoryView.domains?.agent_notes ?? 0}</span>
        </div>
        <div className="mt-4 grid gap-3 lg:grid-cols-2">
          {(memoryView.entries ?? []).slice(0, 8).map((entry) => (
            <div key={entry.id} className="rounded border border-zinc-200 bg-zinc-50 p-3 text-sm">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="font-medium">{entry.summary}</div>
                <span className="rounded border border-zinc-200 bg-white px-2 py-0.5 text-xs text-zinc-500">{entry.domain}</span>
              </div>
              <div className="mt-2 text-xs text-zinc-500">{entry.source} · {entry.kind}</div>
              {entry.payload?.content_excerpt ? (
                <div className="mt-2 line-clamp-3 text-sm text-zinc-600">{entry.payload.content_excerpt}</div>
              ) : null}
            </div>
          ))}
          {(memoryView.entries ?? []).length === 0 ? (
            <div className="text-sm text-zinc-500">No memory entries found.</div>
          ) : null}
        </div>
      </section>

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
                  <td>
                    {repo.graph_href ? (
                      <Link className="inline-flex items-center gap-1 underline-offset-2 hover:underline" href={repo.graph_href}>
                        <Network size={13} />
                        {repo.graph_status}
                      </Link>
                    ) : repo.graph_status}
                  </td>
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

function csrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function jsonHeaders() {
  return {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': csrfToken(),
  };
}

function agentLabel(agentKey, agentOptions = []) {
  const dynamicLabel = agentOptions.find((agent) => agent.key === agentKey)?.label;
  if (dynamicLabel) {
    return dynamicLabel;
  }

  return {
    socrates: 'Socrates',
    platon: 'Platon',
    aristoteles: 'Aristoteles',
    local_agent: 'Local agent',
  }[agentKey] ?? agentKey;
}

function StatusBadge({ value }) {
  const tone = {
    completed: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    running: 'border-sky-200 bg-sky-50 text-sky-800',
    queued: 'border-amber-200 bg-amber-50 text-amber-900',
    failed: 'border-red-200 bg-red-50 text-red-800',
    canceled: 'border-zinc-200 bg-zinc-50 text-zinc-600',
  }[value] ?? 'border-zinc-200 bg-zinc-50 text-zinc-600';

  return <span className={`inline-flex w-fit rounded border px-2 py-0.5 text-xs ${tone}`}>{value}</span>;
}

function WorkDetail({ work }) {
  const answer = work.result_memory_entry?.payload?.answer ?? work.result_memory_entry?.summary ?? work.failure_reason;

  return (
    <div className="mt-4 grid gap-4">
      <div>
        <div className="flex flex-wrap items-center gap-2">
          <StatusBadge value={work.status} />
          <span className="text-xs text-zinc-500">{agentLabel(work.assigned_agent_key)} · {work.priority}</span>
        </div>
        <div className="mt-2 text-sm font-medium">{work.title}</div>
        <div className="mt-2 whitespace-pre-wrap text-sm text-zinc-600">{work.prompt}</div>
      </div>

      {answer ? (
        <div className="rounded border border-zinc-200 bg-zinc-50 p-3">
          <div className="text-xs font-medium text-zinc-500">Result</div>
          <div className="mt-2 whitespace-pre-wrap text-sm text-zinc-700">{answer}</div>
        </div>
      ) : null}

      <div>
        <div className="text-xs font-medium text-zinc-500">Chat</div>
        <div className="mt-2 space-y-2">
          {(work.chat?.messages ?? []).length ? work.chat.messages.map((message) => (
            <div key={message.id} className="rounded border border-zinc-200 bg-white p-2 text-sm">
              <div className="text-xs text-zinc-500">{message.role}</div>
              <div className="mt-1 whitespace-pre-wrap text-zinc-700">{message.content}</div>
            </div>
          )) : <div className="text-sm text-zinc-500">No persisted messages.</div>}
        </div>
      </div>

      <div>
        <div className="text-xs font-medium text-zinc-500">Events</div>
        <div className="mt-2 flex flex-wrap gap-2">
          {(work.events ?? []).map((event) => (
            <span key={event.id} className="rounded border border-zinc-200 bg-white px-2 py-1 text-xs text-zinc-600">
              {event.event_type}
            </span>
          ))}
        </div>
      </div>
    </div>
  );
}

function TriageList({ title, items }) {
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
