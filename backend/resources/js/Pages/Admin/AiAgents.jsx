import { Bot, KeyRound, Power, Save, ShieldCheck, SlidersHorizontal, Workflow } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

function defaultNewAgentForm() {
  return {
    agent_key: '',
    display_name: '',
    description: '',
    agent_type: 'specialist',
    delegation_mode: 'controlled_registry',
    parent_agent_key: '',
    default_model_profile_id: '',
    requires_human_approval: true,
    enabled: true,
    visibility_scope: 'global',
    project_ids: '',
    allowed_tools: 'search_project_memory',
    output_schema: '{ "type": "object" }',
    trigger_events: 'manual_chat',
  };
}

function listFromLines(value) {
  if (typeof value !== 'string' || value.length === 0) {
    return [];
  }

  return value
    .split(/[\n,]/)
    .map((line) => line.trim())
    .filter(Boolean);
}

function jsonObjectFromText(value) {
  if (typeof value !== 'string' || value.trim() === '') {
    return {};
  }

  try {
    const parsed = JSON.parse(value);

    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
      return {};
    }

    return parsed;
  } catch {
    return {};
  }
}

export default function AiAgents({ providers, modelProfiles, agentProfiles, dashboard }) {
  const [providerRows, setProviderRows] = useState(providers);
  const [modelProfileRows, setModelProfileRows] = useState(modelProfiles);
  const [agentRows, setAgentRows] = useState(agentProfiles);
  const [newAgentForm, setNewAgentForm] = useState(() => defaultNewAgentForm());
  const [forms, setForms] = useState(() => Object.fromEntries(providers.map((provider) => [
    provider.provider_key,
    {
      display_name: provider.display_name,
      base_url: provider.base_url ?? '',
      api_key: '',
      enabled: provider.enabled,
      clear_api_key: false,
    },
  ])));
  const [modelForms, setModelForms] = useState(() => Object.fromEntries(modelProfiles.map((profile) => [
    profile.profile_key,
    {
      display_name: profile.display_name,
      model_name: profile.model_name,
      runtime_profile: profile.runtime_profile,
      max_context: profile.max_context ?? '',
      max_output_tokens: profile.max_output_tokens,
      temperature: profile.temperature,
      timeout_seconds: profile.timeout_seconds,
      enabled: profile.enabled,
    },
  ])));
  const [agentForms, setAgentForms] = useState(() => Object.fromEntries(agentProfiles.map((agent) => [
    agent.agent_key,
    {
      default_model_profile_id: agent.default_model_profile_id ?? '',
      enabled: agent.enabled,
    },
  ])));
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(null);
  const [savedProvider, setSavedProvider] = useState(null);
  const [savedModelProfile, setSavedModelProfile] = useState(null);
  const [savedAgent, setSavedAgent] = useState(null);

  function updateForm(providerKey, patch) {
    setForms((current) => ({
      ...current,
      [providerKey]: {
        ...current[providerKey],
        ...patch,
      },
    }));
  }

  function updateModelForm(profileKey, patch) {
    setModelForms((current) => ({
      ...current,
      [profileKey]: {
        ...current[profileKey],
        ...patch,
      },
    }));
  }

  function updateAgentForm(agentKey, patch) {
    setAgentForms((current) => ({
      ...current,
      [agentKey]: {
        ...current[agentKey],
        ...patch,
      },
    }));
  }

  function updateNewAgentForm(patch) {
    setNewAgentForm((current) => ({
      ...current,
      ...patch,
    }));
  }

  async function saveProvider(event, providerKey) {
    event.preventDefault();
    setSaving(providerKey);
    setErrors({});
    setSavedProvider(null);
    setSavedModelProfile(null);
    setSavedAgent(null);

    const form = forms[providerKey];
    const response = await fetch(`/api/dashboard/admin/ai-model-providers/${providerKey}`, {
      method: 'PUT',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
      },
      body: JSON.stringify({
        display_name: form.display_name,
        base_url: form.base_url || null,
        api_key: form.api_key || null,
        clear_api_key: form.clear_api_key,
        enabled: form.enabled,
      }),
    });

    const payload = await response.json().catch(() => ({}));
    setSaving(null);

    if (!response.ok) {
      setErrors(payload.errors ?? { general: ['Provider update failed.'] });
      return;
    }

    setProviderRows((current) => current.map((provider) => (
      provider.provider_key === providerKey ? payload.provider : provider
    )));
    updateForm(providerKey, {
      api_key: '',
      clear_api_key: false,
      enabled: payload.provider.enabled,
      base_url: payload.provider.base_url ?? '',
      display_name: payload.provider.display_name,
    });
    setSavedProvider(providerKey);
  }

  async function saveModelProfile(event, profileKey) {
    event.preventDefault();
    setSaving(`model:${profileKey}`);
    setErrors({});
    setSavedProvider(null);
    setSavedModelProfile(null);
    setSavedAgent(null);

    const form = modelForms[profileKey];
    const response = await fetch(`/api/dashboard/admin/ai-model-profiles/${profileKey}`, {
      method: 'PUT',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
      },
      body: JSON.stringify({
        display_name: form.display_name,
        model_name: form.model_name,
        runtime_profile: form.runtime_profile,
        max_context: form.max_context === '' ? null : Number.parseInt(form.max_context, 10),
        max_output_tokens: Number.parseInt(form.max_output_tokens, 10),
        temperature: Number.parseFloat(form.temperature),
        timeout_seconds: Number.parseInt(form.timeout_seconds, 10),
        enabled: form.enabled,
      }),
    });

    const payload = await response.json().catch(() => ({}));
    setSaving(null);

    if (!response.ok) {
      setErrors(payload.errors ?? { general: ['Model profile update failed.'] });
      return;
    }

    setModelProfileRows((current) => current.map((profile) => (
      profile.profile_key === profileKey ? payload.model_profile : profile
    )));
    updateModelForm(profileKey, {
      display_name: payload.model_profile.display_name,
      model_name: payload.model_profile.model_name,
      runtime_profile: payload.model_profile.runtime_profile,
      max_context: payload.model_profile.max_context ?? '',
      max_output_tokens: payload.model_profile.max_output_tokens,
      temperature: payload.model_profile.temperature,
      timeout_seconds: payload.model_profile.timeout_seconds,
      enabled: payload.model_profile.enabled,
    });
    setSavedModelProfile(profileKey);
  }

  async function saveAgentProfile(agentKey) {
    setSaving(`agent:${agentKey}`);
    setErrors({});
    setSavedProvider(null);
    setSavedModelProfile(null);
    setSavedAgent(null);

    const form = agentForms[agentKey];
    const response = await fetch(`/api/dashboard/admin/ai-agent-profiles/${agentKey}`, {
      method: 'PATCH',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
      },
      body: JSON.stringify({
        default_model_profile_id: form.default_model_profile_id || null,
        enabled: form.enabled,
      }),
    });

    const payload = await response.json().catch(() => ({}));
    setSaving(null);

    if (!response.ok) {
      setErrors(payload.errors ?? { general: ['Agent profile update failed.'] });
      return;
    }

    setAgentRows((current) => current.map((agent) => (
      agent.agent_key === agentKey ? payload.agent_profile : agent
    )));
    updateAgentForm(agentKey, {
      default_model_profile_id: payload.agent_profile.default_model_profile_id ?? '',
      enabled: payload.agent_profile.enabled,
    });
    setSavedAgent(agentKey);
  }

  async function createAgentProfile(event) {
    event.preventDefault();
    setSaving('create-agent');
    setErrors({});
    setSavedProvider(null);
    setSavedModelProfile(null);
    setSavedAgent(null);

    const outputSchemaText = newAgentForm.output_schema;
    let outputSchema;

    try {
      const parsedOutputSchema = JSON.parse(outputSchemaText);

      if (!parsedOutputSchema || typeof parsedOutputSchema !== 'object' || Array.isArray(parsedOutputSchema)) {
        throw new Error('invalid');
      }

      outputSchema = jsonObjectFromText(outputSchemaText);
    } catch {
      setSaving(null);
      setErrors({ output_schema: ['Output schema must be valid JSON object.'] });
      return;
    }

    const response = await fetch('/api/dashboard/admin/ai-agent-profiles', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
      },
      body: JSON.stringify({
        agent_key: newAgentForm.agent_key,
        display_name: newAgentForm.display_name,
        description: newAgentForm.description,
        agent_type: newAgentForm.agent_type,
        delegation_mode: newAgentForm.delegation_mode,
        parent_agent_key: newAgentForm.parent_agent_key || null,
        default_model_profile_id: newAgentForm.default_model_profile_id || null,
        requires_human_approval: newAgentForm.requires_human_approval,
        enabled: newAgentForm.enabled,
        visibility_scope: newAgentForm.visibility_scope,
        project_ids: newAgentForm.visibility_scope === 'project' ? listFromLines(newAgentForm.project_ids) : [],
        allowed_tools: listFromLines(newAgentForm.allowed_tools),
        output_schema: outputSchema,
        trigger_events: listFromLines(newAgentForm.trigger_events),
      }),
    });

    const payload = await response.json().catch(() => ({}));
    setSaving(null);

    if (!response.ok) {
      setErrors(payload.errors ?? { general: ['Agent profile create failed.'] });
      return;
    }

    setAgentRows((current) => [...current, payload.agent_profile].sort((left, right) => (
      left.display_name.localeCompare(right.display_name)
    )));
    setAgentForms((current) => ({
      ...current,
      [payload.agent_profile.agent_key]: {
        default_model_profile_id: payload.agent_profile.default_model_profile_id ?? '',
        enabled: payload.agent_profile.enabled,
      },
    }));
    setNewAgentForm(defaultNewAgentForm());
    setSavedAgent(payload.agent_profile.agent_key);
  }

  return (
    <AppLayout title="Admin / AI Agents" dashboard={dashboard}>
      <section className="rounded border border-zinc-200 bg-white p-4">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-lg font-semibold">AI Agents</h1>
            <p className="mt-1 max-w-3xl text-sm text-zinc-500">
              Controlled server-side registry for model providers, model profiles, and DevBoard assistant flows.
            </p>
          </div>
          <div className="inline-flex items-center gap-2 rounded border border-zinc-200 px-3 py-2 text-xs text-zinc-600">
            <ShieldCheck size={14} />
            controlled registry
          </div>
        </div>
      </section>

      <section className="mt-5 rounded border border-zinc-200 bg-white p-4">
        <div className="flex items-center gap-2 text-sm font-semibold">
          <KeyRound size={16} />
          Model provider credentials
        </div>
        <p className="mt-1 text-sm text-zinc-500">
          API keys are encrypted server-side. Saved keys are never returned to the browser.
        </p>

        <div className="mt-4 grid gap-4">
          {providerRows.map((provider) => {
            const form = forms[provider.provider_key] ?? {};
            const isSaving = saving === provider.provider_key;

            return (
              <form key={provider.provider_key} onSubmit={(event) => saveProvider(event, provider.provider_key)} className="rounded border border-zinc-200 p-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <div className="text-sm font-semibold">{provider.display_name}</div>
                    <div className="mt-1 text-xs text-zinc-500">
                      {provider.provider_key} / {provider.provider_type}
                    </div>
                  </div>
                  <StatusPill enabled={provider.enabled} configured={provider.api_key_configured} />
                </div>

                <div className="mt-4 grid gap-3 lg:grid-cols-[180px_1fr_1fr_130px]">
                  <label className="text-sm">
                    <span className="mb-1 block text-xs text-zinc-500">Display name</span>
                    <input
                      className="h-9 w-full rounded border border-zinc-300 px-3 text-sm"
                      value={form.display_name ?? ''}
                      onChange={(event) => updateForm(provider.provider_key, { display_name: event.target.value })}
                    />
                  </label>
                  <label className="text-sm">
                    <span className="mb-1 block text-xs text-zinc-500">Base URL</span>
                    <input
                      className="h-9 w-full rounded border border-zinc-300 px-3 text-sm"
                      placeholder="https://api.openai.com/v1"
                      value={form.base_url ?? ''}
                      onChange={(event) => updateForm(provider.provider_key, { base_url: event.target.value })}
                    />
                  </label>
                  <label className="text-sm">
                    <span className="mb-1 block text-xs text-zinc-500">API key</span>
                    <input
                      autoComplete="off"
                      className="h-9 w-full rounded border border-zinc-300 px-3 text-sm"
                      placeholder={provider.api_key_configured ? `configured ending ${provider.api_key_last_four}` : 'paste key'}
                      type="password"
                      value={form.api_key ?? ''}
                      onChange={(event) => updateForm(provider.provider_key, { api_key: event.target.value, clear_api_key: false })}
                    />
                  </label>
                  <label className="flex items-end gap-2 text-sm">
                    <input
                      checked={Boolean(form.enabled)}
                      className="mb-2 h-4 w-4 rounded border-zinc-300"
                      type="checkbox"
                      onChange={(event) => updateForm(provider.provider_key, { enabled: event.target.checked })}
                    />
                    <span className="mb-1">Enabled</span>
                  </label>
                </div>

                <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                  <label className="inline-flex items-center gap-2 text-xs text-zinc-600">
                    <input
                      checked={Boolean(form.clear_api_key)}
                      className="h-4 w-4 rounded border-zinc-300"
                      disabled={!provider.api_key_configured}
                      type="checkbox"
                      onChange={(event) => updateForm(provider.provider_key, { clear_api_key: event.target.checked, api_key: '' })}
                    />
                    Clear stored key
                  </label>
                  <div className="flex items-center gap-3">
                    {savedProvider === provider.provider_key ? (
                      <span className="text-xs text-emerald-700">Saved.</span>
                    ) : null}
                    <button
                      className="inline-flex h-9 items-center gap-2 rounded bg-zinc-950 px-3 text-sm font-medium text-white disabled:opacity-60"
                      disabled={isSaving}
                      type="submit"
                    >
                      <Save size={14} />
                      {isSaving ? 'Saving' : 'Save provider'}
                    </button>
                  </div>
                </div>
              </form>
            );
          })}
        </div>
      </section>

      <section className="mt-5 rounded border border-zinc-200 bg-white p-4">
        <div className="flex items-center gap-2 text-sm font-semibold">
          <ShieldCheck size={16} />
          Create custom agent
        </div>
        <p className="mt-1 text-sm text-zinc-500">
          Create a backend agent profile from the dashboard without leaving this page.
        </p>

        {renderErrors(errors)}

        <form className="mt-4 grid gap-4" onSubmit={createAgentProfile}>
          <div className="grid gap-3 xl:grid-cols-3">
            <label className="text-sm">
              <span className="mb-1 block text-xs text-zinc-500">Key</span>
              <input
                className="h-9 w-full rounded border border-zinc-300 px-3 text-sm"
                value={newAgentForm.agent_key}
                onChange={(event) => updateNewAgentForm({ agent_key: event.target.value })}
              />
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-zinc-500">Display name</span>
              <input
                className="h-9 w-full rounded border border-zinc-300 px-3 text-sm"
                value={newAgentForm.display_name}
                onChange={(event) => updateNewAgentForm({ display_name: event.target.value })}
              />
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-zinc-500">Type</span>
              <input
                className="h-9 w-full rounded border border-zinc-300 px-3 text-sm"
                value={newAgentForm.agent_type}
                onChange={(event) => updateNewAgentForm({ agent_type: event.target.value })}
              />
            </label>
          </div>

          <label className="text-sm">
            <span className="mb-1 block text-xs text-zinc-500">Description</span>
            <textarea
              className="min-h-20 w-full rounded border border-zinc-300 px-3 py-2 text-sm"
              value={newAgentForm.description}
              onChange={(event) => updateNewAgentForm({ description: event.target.value })}
            />
          </label>

          <div className="grid gap-3 xl:grid-cols-3">
            <label className="text-sm">
              <span className="mb-1 block text-xs text-zinc-500">Delegation mode</span>
              <input
                className="h-9 w-full rounded border border-zinc-300 px-3 text-sm"
                value={newAgentForm.delegation_mode}
                onChange={(event) => updateNewAgentForm({ delegation_mode: event.target.value })}
              />
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-zinc-500">Parent agent</span>
              <select
                className="h-9 w-full rounded border border-zinc-300 px-2 text-sm"
                value={newAgentForm.parent_agent_key}
                onChange={(event) => updateNewAgentForm({ parent_agent_key: event.target.value })}
              >
                <option value="">No parent</option>
                {agentRows.map((agent) => (
                  <option key={agent.id} value={agent.agent_key}>
                    {agent.display_name} ({agent.agent_key})
                  </option>
                ))}
              </select>
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-zinc-500">Default model profile</span>
              <select
                className="h-9 w-full rounded border border-zinc-300 px-2 text-sm"
                value={newAgentForm.default_model_profile_id}
                onChange={(event) => updateNewAgentForm({ default_model_profile_id: event.target.value })}
              >
                <option value="">No default</option>
                {modelProfileRows.map((profile) => (
                  <option key={profile.id} value={profile.id}>
                    {profile.display_name}
                  </option>
                ))}
              </select>
            </label>
          </div>

          <div className="grid gap-3 xl:grid-cols-3">
            <label className="text-sm">
              <span className="mb-1 block text-xs text-zinc-500">Visibility scope</span>
              <select
                className="h-9 w-full rounded border border-zinc-300 px-2 text-sm"
                value={newAgentForm.visibility_scope}
                onChange={(event) => updateNewAgentForm({ visibility_scope: event.target.value })}
              >
                <option value="global">global</option>
                <option value="project">project</option>
              </select>
            </label>
            <label className="flex items-end gap-2 text-sm">
              <input
                checked={Boolean(newAgentForm.enabled)}
                className="mb-2 h-4 w-4 rounded border-zinc-300"
                type="checkbox"
                onChange={(event) => updateNewAgentForm({ enabled: event.target.checked })}
              />
              <span className="mb-1">Enabled</span>
            </label>
            <label className="flex items-end gap-2 text-sm">
              <input
                checked={Boolean(newAgentForm.requires_human_approval)}
                className="mb-2 h-4 w-4 rounded border-zinc-300"
                type="checkbox"
                onChange={(event) => updateNewAgentForm({ requires_human_approval: event.target.checked })}
              />
              <span className="mb-1">Requires approval</span>
            </label>
          </div>

          <div className="grid gap-3 lg:grid-cols-2">
            <label className="text-sm">
              <span className="mb-1 block text-xs text-zinc-500">Project IDs</span>
              <textarea
                className="min-h-20 w-full rounded border border-zinc-300 px-3 py-2 text-sm"
                placeholder="demo-project, staging-project"
                value={newAgentForm.project_ids}
                onChange={(event) => updateNewAgentForm({ project_ids: event.target.value })}
              />
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-zinc-500">Allowed tools</span>
              <textarea
                className="min-h-20 w-full rounded border border-zinc-300 px-3 py-2 text-sm"
                placeholder="search_project_memory"
                value={newAgentForm.allowed_tools}
                onChange={(event) => updateNewAgentForm({ allowed_tools: event.target.value })}
              />
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-zinc-500">Trigger events</span>
              <textarea
                className="min-h-20 w-full rounded border border-zinc-300 px-3 py-2 text-sm"
                placeholder="manual_chat"
                value={newAgentForm.trigger_events}
                onChange={(event) => updateNewAgentForm({ trigger_events: event.target.value })}
              />
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-zinc-500">Output schema</span>
              <textarea
                className="min-h-20 w-full rounded border border-zinc-300 px-3 py-2 text-sm"
                value={newAgentForm.output_schema}
                onChange={(event) => updateNewAgentForm({ output_schema: event.target.value })}
              />
            </label>
          </div>

          <div className="flex items-center justify-end gap-3">
            <button
              className="inline-flex h-9 items-center gap-2 rounded bg-zinc-950 px-3 text-sm font-medium text-white disabled:opacity-60"
              disabled={saving === 'create-agent'}
              type="submit"
            >
              <Save size={14} />
              {saving === 'create-agent' ? 'Creating' : 'Create agent'}
            </button>
          </div>
        </form>
      </section>

      <section className="mt-5 grid gap-5 xl:grid-cols-[1.2fr_0.8fr]">
        <div className="rounded border border-zinc-200 bg-white p-4">
          <div className="flex items-center gap-2 text-sm font-semibold">
            <Bot size={16} />
            Controlled agent flows
          </div>
          <div className="mt-4 overflow-x-auto">
            <table className="min-w-[1040px] w-full text-left text-sm">
              <thead className="bg-zinc-50 text-xs text-zinc-500">
                <tr>
                  <th className="p-3">agent</th>
                  <th>type</th>
                  <th>parent</th>
                  <th>model</th>
                  <th>triggers</th>
                  <th>approval</th>
                  <th>state</th>
                  <th>save</th>
                </tr>
              </thead>
              <tbody>
                {agentRows.map((agent) => {
                  const form = agentForms[agent.agent_key] ?? {};
                  const isSaving = saving === `agent:${agent.agent_key}`;

                  return (
                    <tr key={agent.id} className="border-t border-zinc-100 align-top">
                      <td className="p-3">
                        <div className="font-medium">{agent.display_name}</div>
                        <div className="mt-1 max-w-md text-xs text-zinc-500">{agent.description}</div>
                        <div className="mt-1 font-mono text-xs text-zinc-500">{agent.agent_key}</div>
                      </td>
                      <td className="py-3">{agent.agent_type}</td>
                      <td className="py-3">{agent.parent_agent_key ?? 'none'}</td>
                      <td className="py-3 pr-3">
                        <select
                          className="h-9 w-52 rounded border border-zinc-300 px-2 text-sm"
                          value={form.default_model_profile_id ?? ''}
                          onChange={(event) => updateAgentForm(agent.agent_key, { default_model_profile_id: event.target.value })}
                        >
                          <option value="">No default</option>
                          {modelProfileRows.map((profile) => (
                            <option key={profile.id} value={profile.id}>
                              {profile.display_name}
                            </option>
                          ))}
                        </select>
                      </td>
                      <td className="max-w-56 truncate py-3">{agent.trigger_events.join(', ')}</td>
                      <td className="py-3">{agent.requires_human_approval ? 'required' : 'not required'}</td>
                      <td className="py-3">
                        <label className="inline-flex items-center gap-2">
                          <input
                            checked={Boolean(form.enabled)}
                            className="h-4 w-4 rounded border-zinc-300"
                            type="checkbox"
                            onChange={(event) => updateAgentForm(agent.agent_key, { enabled: event.target.checked })}
                          />
                          <span>{form.enabled ? 'enabled' : 'disabled'}</span>
                        </label>
                      </td>
                      <td className="py-3 pr-3">
                        <button
                          className="inline-flex h-9 items-center gap-2 rounded border border-zinc-300 px-3 text-sm font-medium text-zinc-700 disabled:opacity-60"
                          disabled={isSaving}
                          type="button"
                          onClick={() => saveAgentProfile(agent.agent_key)}
                        >
                          <Power size={14} />
                          {isSaving ? 'Saving' : 'Save'}
                        </button>
                        {savedAgent === agent.agent_key ? (
                          <div className="mt-1 text-xs text-emerald-700">Saved.</div>
                        ) : null}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>

        <div className="rounded border border-zinc-200 bg-white p-4">
          <div className="flex items-center gap-2 text-sm font-semibold">
            <Workflow size={16} />
            Model profiles
          </div>
          {modelProfileRows.length ? (
            <div className="mt-4 space-y-3">
              {modelProfileRows.map((profile) => {
                const form = modelForms[profile.profile_key] ?? {};
                const isSaving = saving === `model:${profile.profile_key}`;

                return (
                  <form key={profile.id} className="rounded border border-zinc-200 p-3 text-sm" onSubmit={(event) => saveModelProfile(event, profile.profile_key)}>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div>
                        <div className="font-medium">{profile.display_name}</div>
                        <div className="mt-1 text-xs text-zinc-500">{profile.provider_key} / {profile.profile_key}</div>
                      </div>
                      <span className={`inline-flex items-center rounded border px-2 py-1 text-xs ${profile.enabled ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-zinc-200 bg-zinc-50 text-zinc-600'}`}>
                        {profile.enabled ? 'enabled' : 'disabled'}
                      </span>
                    </div>

                    <div className="mt-3 grid gap-3">
                      <label>
                        <span className="mb-1 block text-xs text-zinc-500">Display name</span>
                        <input
                          className="h-9 w-full rounded border border-zinc-300 px-3 text-sm"
                          value={form.display_name ?? ''}
                          onChange={(event) => updateModelForm(profile.profile_key, { display_name: event.target.value })}
                        />
                      </label>
                      <label>
                        <span className="mb-1 block text-xs text-zinc-500">Model</span>
                        <input
                          className="h-9 w-full rounded border border-zinc-300 px-3 text-sm"
                          value={form.model_name ?? ''}
                          onChange={(event) => updateModelForm(profile.profile_key, { model_name: event.target.value })}
                        />
                      </label>
                      <label>
                        <span className="mb-1 block text-xs text-zinc-500">Runtime profile</span>
                        <input
                          className="h-9 w-full rounded border border-zinc-300 px-3 text-sm"
                          value={form.runtime_profile ?? ''}
                          onChange={(event) => updateModelForm(profile.profile_key, { runtime_profile: event.target.value })}
                        />
                      </label>
                    </div>

                    <div className="mt-3 grid gap-3 sm:grid-cols-2">
                      <NumberField label="Max context" value={form.max_context ?? ''} onChange={(value) => updateModelForm(profile.profile_key, { max_context: value })} />
                      <NumberField label="Max output" value={form.max_output_tokens ?? ''} onChange={(value) => updateModelForm(profile.profile_key, { max_output_tokens: value })} />
                      <NumberField label="Temperature" step="0.1" value={form.temperature ?? ''} onChange={(value) => updateModelForm(profile.profile_key, { temperature: value })} />
                      <NumberField label="Timeout seconds" value={form.timeout_seconds ?? ''} onChange={(value) => updateModelForm(profile.profile_key, { timeout_seconds: value })} />
                    </div>

                    <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                      <label className="inline-flex items-center gap-2 text-sm">
                        <input
                          checked={Boolean(form.enabled)}
                          className="h-4 w-4 rounded border-zinc-300"
                          type="checkbox"
                          onChange={(event) => updateModelForm(profile.profile_key, { enabled: event.target.checked })}
                        />
                        Enabled
                      </label>
                      <div className="flex items-center gap-3">
                        {savedModelProfile === profile.profile_key ? (
                          <span className="text-xs text-emerald-700">Saved.</span>
                        ) : null}
                        <button
                          className="inline-flex h-9 items-center gap-2 rounded bg-zinc-950 px-3 text-sm font-medium text-white disabled:opacity-60"
                          disabled={isSaving}
                          type="submit"
                        >
                          <SlidersHorizontal size={14} />
                          {isSaving ? 'Saving' : 'Save model'}
                        </button>
                      </div>
                    </div>
                  </form>
                );
              })}
            </div>
          ) : (
            <div className="mt-4 rounded border border-zinc-200 bg-zinc-50 p-3 text-sm text-zinc-500">
              No model profiles configured yet. Provider keys can be stored now; runtime profiles are added in the next agent execution slice.
            </div>
          )}
        </div>
      </section>
    </AppLayout>
  );
}

function NumberField({ label, step = '1', value, onChange }) {
  return (
    <label>
      <span className="mb-1 block text-xs text-zinc-500">{label}</span>
      <input
        className="h-9 w-full rounded border border-zinc-300 px-3 text-sm"
        min="0"
        step={step}
        type="number"
        value={value}
        onChange={(event) => onChange(event.target.value)}
      />
    </label>
  );
}

function StatusPill({ enabled, configured }) {
  const label = configured ? (enabled ? 'ready' : 'key saved, disabled') : 'missing key';
  const classes = configured && enabled
    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
    : configured
      ? 'border-amber-200 bg-amber-50 text-amber-800'
      : 'border-zinc-200 bg-zinc-50 text-zinc-600';

  return (
    <span className={`inline-flex items-center rounded border px-2 py-1 text-xs ${classes}`}>
      {label}
    </span>
  );
}

function renderErrors(errors) {
  const messages = Object.values(errors).flatMap((value) => Array.isArray(value) ? value : [value]).filter(Boolean);

  if (!messages.length) {
    return null;
  }

  return (
    <div className="mt-3 rounded border border-red-200 bg-red-50 p-3 text-xs text-red-800">
      {messages.map((message, index) => (
        <div key={`${message}-${index}`}>{message}</div>
      ))}
    </div>
  );
}
