import React, { useEffect, useState } from "react";
import { toast } from "sonner";
import { api } from "@/api/devboardApi";
import { useApi } from "@/hooks/useApi";
import { PageHeader, Panel } from "@/components/devboard/Layout";
import { DataState } from "@/components/devboard/DataState";
import { Pill } from "@/components/devboard/Badges";
import { ConfirmDialog } from "@/components/devboard/ConfirmDialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from "@/components/ui/dialog";
import {
  AiAgentProfile, AiAgentsSnapshot, AiModelProfile, AiModelProvider,
  AiModelProviderValidationResult, PluginToken,
} from "@/types/devboard";
import { relativeTime } from "@/lib/format";
import { Bot, KeyRound, Plus, RotateCw, Trash2, Copy, ShieldAlert, Laptop, Check, Power, Save, SlidersHorizontal, Workflow } from "lucide-react";

const PLUGIN_SCOPES = [
  "projects.read",
  "repositories.read",
  "policies.read",
  "runs.write",
  "artifacts.write",
  "wiki.write",
  "graph.write",
];

const OPENCODE_GO_PROVIDER_KEY = "opencode_go";
const OPENCODE_GO_PROFILE_KEY = "opencode_go_default";

const OPENCODE_GO_PLACEHOLDER_PROVIDER: AiModelProvider = {
  id: "provider-opencode-go",
  provider_key: OPENCODE_GO_PROVIDER_KEY,
  display_name: "OpenCode Go",
  provider_type: "openai_compatible",
  base_url: null,
  api_key_configured: false,
  api_key_last_four: null,
  api_key_updated_at: null,
  enabled: false,
  metadata: {},
};

type ProviderForm = {
  display_name: string;
  base_url: string;
  api_key: string;
  enabled: boolean;
  clear_api_key: boolean;
};

type ModelProfileForm = {
  display_name: string;
  model_name: string;
  runtime_profile: string;
  max_context: string;
  max_output_tokens: string;
  temperature: string;
  timeout_seconds: string;
  enabled: boolean;
};

type AgentProfileForm = {
  default_model_profile_id: string;
  enabled: boolean;
};

const providerForm = (provider: AiModelProvider): ProviderForm => ({
  display_name: provider.display_name,
  base_url: provider.base_url || "",
  api_key: "",
  enabled: provider.enabled,
  clear_api_key: false,
});

const modelProfileForm = (profile: AiModelProfile): ModelProfileForm => ({
  display_name: profile.display_name,
  model_name: profile.model_name,
  runtime_profile: profile.runtime_profile,
  max_context: profile.max_context === null ? "" : String(profile.max_context),
  max_output_tokens: String(profile.max_output_tokens),
  temperature: String(profile.temperature),
  timeout_seconds: String(profile.timeout_seconds),
  enabled: profile.enabled,
});

const agentProfileForm = (agent: AiAgentProfile): AgentProfileForm => ({
  default_model_profile_id: agent.default_model_profile_id || "",
  enabled: agent.enabled,
});

const openCodeDefaultProfileForm = (): ModelProfileForm => ({
  display_name: "OpenCode Go default",
  model_name: "opencode-go",
  runtime_profile: "text",
  max_context: "",
  max_output_tokens: "4096",
  temperature: "0.2",
  timeout_seconds: "60",
  enabled: true,
});

function ensureOpenCodeGoProvider(providers: AiModelProvider[]): AiModelProvider[] {
  return providers.some((provider) => provider.provider_key === OPENCODE_GO_PROVIDER_KEY)
    ? providers
    : [...providers, OPENCODE_GO_PLACEHOLDER_PROVIDER];
}

function validationTone(status: AiModelProviderValidationResult["status"]): "green" | "amber" | "red" | "slate" {
  if (status === "valid" || status === "ready_for_runtime") return "green";
  if (status === "invalid" || status === "missing_configuration") return "red";
  if (status === "unknown" || status === "disabled") return "amber";
  return "slate";
}

function AiRegistryPanel({ state }: { state: any }) {
  return (
    <Panel title={<span className="inline-flex items-center gap-1.5"><Bot className="h-4 w-4 text-muted-foreground" />AI agents & model keys</span>}>
      <DataState state={state} loadingRows={4}>
        {(snapshot: AiAgentsSnapshot) => <AiRegistryContent snapshot={snapshot} />}
      </DataState>
    </Panel>
  );
}

function AiRegistryContent({ snapshot }: { snapshot: AiAgentsSnapshot }) {
  const [providers, setProviders] = useState(snapshot.providers);
  const [modelProfiles, setModelProfiles] = useState(snapshot.modelProfiles);
  const [agentProfiles, setAgentProfiles] = useState(snapshot.agentProfiles);
  const [providerForms, setProviderForms] = useState<Record<string, ProviderForm>>({});
  const [modelForms, setModelForms] = useState<Record<string, ModelProfileForm>>({});
  const [agentForms, setAgentForms] = useState<Record<string, AgentProfileForm>>({});
  const [openCodeProfileForm, setOpenCodeProfileForm] = useState<ModelProfileForm>(openCodeDefaultProfileForm);
  const [validationResults, setValidationResults] = useState<Record<string, AiModelProviderValidationResult>>({});
  const [saving, setSaving] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState<string | null>(null);
  const [clearProvider, setClearProvider] = useState<AiModelProvider | null>(null);
  const [deleteModelProfile, setDeleteModelProfile] = useState<AiModelProfile | null>(null);

  useEffect(() => {
    const nextProviders = ensureOpenCodeGoProvider(snapshot.providers);
    setProviders(nextProviders);
    setModelProfiles(snapshot.modelProfiles);
    setAgentProfiles(snapshot.agentProfiles);
    setProviderForms(Object.fromEntries(nextProviders.map((provider) => [provider.provider_key, providerForm(provider)])));
    setModelForms(Object.fromEntries(snapshot.modelProfiles.map((profile) => [profile.profile_key, modelProfileForm(profile)])));
    setAgentForms(Object.fromEntries(snapshot.agentProfiles.map((agent) => [agent.agent_key, agentProfileForm(agent)])));
  }, [snapshot]);

  const patchProviderForm = (providerKey: string, patch: Partial<ProviderForm>) => {
    setProviderForms((current) => ({ ...current, [providerKey]: { ...current[providerKey], ...patch } }));
  };
  const patchModelForm = (profileKey: string, patch: Partial<ModelProfileForm>) => {
    setModelForms((current) => ({ ...current, [profileKey]: { ...current[profileKey], ...patch } }));
  };
  const patchAgentForm = (agentKey: string, patch: Partial<AgentProfileForm>) => {
    setAgentForms((current) => ({ ...current, [agentKey]: { ...current[agentKey], ...patch } }));
  };
  const patchOpenCodeProfileForm = (patch: Partial<ModelProfileForm>) => {
    setOpenCodeProfileForm((current) => ({ ...current, ...patch }));
  };

  const saveProvider = async (event: React.FormEvent, providerKey: string) => {
    event.preventDefault();
    const form = providerForms[providerKey];
    if (!form) return;

    setSaving(`provider:${providerKey}`);
    setError(null);
    setSaved(null);
    try {
      const provider = await api.updateAiModelProvider(providerKey, {
        display_name: form.display_name,
        base_url: form.base_url || null,
        api_key: form.api_key || null,
        clear_api_key: form.clear_api_key,
        enabled: form.enabled,
      });
      setProviders((current) => current.map((item) => item.provider_key === providerKey ? provider : item));
      patchProviderForm(providerKey, providerForm(provider));
      setSaved(`provider:${providerKey}`);
      toast.success("Model provider saved");
    } catch (err: any) {
      setError(err?.message || "Model provider update failed");
    } finally {
      setSaving(null);
    }
  };

  const validateProvider = async (providerKey: string) => {
    const saveKey = `provider-validate:${providerKey}`;
    setSaving(saveKey);
    setError(null);
    setSaved(null);
    try {
      const validation = await api.validateAiModelProvider(providerKey);
      setValidationResults((current) => ({ ...current, [providerKey]: validation }));
      setSaved(saveKey);
      if (validation.status === "valid" || validation.status === "ready_for_runtime") {
        toast.success("Provider validation passed");
      } else {
        toast.warning(validation.message || "Provider validation did not pass");
      }
    } catch (err: any) {
      setError(err?.message || "Provider validation failed");
    } finally {
      setSaving(null);
    }
  };

  const createOpenCodeProfile = async (event: React.FormEvent) => {
    event.preventDefault();
    const saveKey = `model-create:${OPENCODE_GO_PROFILE_KEY}`;
    const form = openCodeProfileForm;
    setSaving(saveKey);
    setError(null);
    setSaved(null);
    try {
      const profile = await api.createAiModelProfile({
        provider_key: OPENCODE_GO_PROVIDER_KEY,
        profile_key: OPENCODE_GO_PROFILE_KEY,
        display_name: form.display_name,
        model_name: form.model_name,
        runtime_profile: form.runtime_profile,
        max_context: form.max_context ? Number.parseInt(form.max_context, 10) : null,
        max_output_tokens: Number.parseInt(form.max_output_tokens, 10),
        temperature: Number.parseFloat(form.temperature),
        timeout_seconds: Number.parseInt(form.timeout_seconds, 10),
        enabled: form.enabled,
      });
      setModelProfiles((current) => current.some((item) => item.profile_key === profile.profile_key)
        ? current.map((item) => item.profile_key === profile.profile_key ? profile : item)
        : [...current, profile]);
      setModelForms((current) => ({ ...current, [profile.profile_key]: modelProfileForm(profile) }));
      setOpenCodeProfileForm(openCodeDefaultProfileForm());
      setSaved(saveKey);
      toast.success("OpenCode Go model profile created");
    } catch (err: any) {
      setError(err?.message || "Model profile create failed");
    } finally {
      setSaving(null);
    }
  };

  const hasOpenCodeGoProfile = modelProfiles.some((profile) => profile.profile_key === OPENCODE_GO_PROFILE_KEY);
  const createOpenCodeSaveKey = `model-create:${OPENCODE_GO_PROFILE_KEY}`;

  const saveModelProfile = async (event: React.FormEvent, profileKey: string) => {
    event.preventDefault();
    const form = modelForms[profileKey];
    if (!form) return;

    setSaving(`model:${profileKey}`);
    setError(null);
    setSaved(null);
    try {
      const profile = await api.updateAiModelProfile(profileKey, {
        display_name: form.display_name,
        model_name: form.model_name,
        runtime_profile: form.runtime_profile,
        max_context: form.max_context ? Number.parseInt(form.max_context, 10) : null,
        max_output_tokens: Number.parseInt(form.max_output_tokens, 10),
        temperature: Number.parseFloat(form.temperature),
        timeout_seconds: Number.parseInt(form.timeout_seconds, 10),
        enabled: form.enabled,
      });
      setModelProfiles((current) => current.map((item) => item.profile_key === profileKey ? profile : item));
      patchModelForm(profileKey, modelProfileForm(profile));
      setSaved(`model:${profileKey}`);
      toast.success("Model profile saved");
    } catch (err: any) {
      setError(err?.message || "Model profile update failed");
    } finally {
      setSaving(null);
    }
  };

  const clearProviderCredential = async () => {
    if (!clearProvider) return;
    const providerKey = clearProvider.provider_key;
    const form = providerForms[providerKey] || providerForm(clearProvider);
    const saveKey = `provider-clear:${providerKey}`;
    setSaving(saveKey);
    setError(null);
    setSaved(null);
    try {
      const provider = await api.updateAiModelProvider(providerKey, {
        display_name: form.display_name || clearProvider.display_name,
        base_url: form.base_url || null,
        api_key: null,
        clear_api_key: true,
        enabled: form.enabled,
      });
      setProviders((current) => current.map((item) => item.provider_key === providerKey ? provider : item));
      patchProviderForm(providerKey, providerForm(provider));
      setValidationResults((current) => {
        const next = { ...current };
        delete next[providerKey];
        return next;
      });
      setSaved(saveKey);
      setClearProvider(null);
      toast.success("Provider key cleared");
    } catch (err: any) {
      setError(err?.message || "Provider key clear failed");
    } finally {
      setSaving(null);
    }
  };

  const deleteSelectedModelProfile = async () => {
    if (!deleteModelProfile) return;
    const profileKey = deleteModelProfile.profile_key;
    const profileId = deleteModelProfile.id;
    const saveKey = `model-delete:${profileKey}`;
    setSaving(saveKey);
    setError(null);
    setSaved(null);
    try {
      await api.deleteAiModelProfile(profileKey);
      setModelProfiles((current) => current.filter((item) => item.profile_key !== profileKey));
      setAgentProfiles((current) => current.map((agent) => agent.default_model_profile_id === profileId
        ? { ...agent, default_model_profile_id: null }
        : agent));
      setAgentForms((current) => Object.fromEntries(
        Object.entries(current).map(([agentKey, form]) => [
          agentKey,
          form.default_model_profile_id === profileId ? { ...form, default_model_profile_id: "" } : form,
        ]),
      ));
      setModelForms((current) => {
        const next = { ...current };
        delete next[profileKey];
        return next;
      });
      setDeleteModelProfile(null);
      toast.success("Model profile deleted");
    } catch (err: any) {
      setError(err?.message || "Model profile delete failed");
    } finally {
      setSaving(null);
    }
  };

  const saveAgentProfile = async (agentKey: string) => {
    const form = agentForms[agentKey];
    if (!form) return;

    setSaving(`agent:${agentKey}`);
    setError(null);
    setSaved(null);
    try {
      const agent = await api.updateAiAgentProfile(agentKey, {
        default_model_profile_id: form.default_model_profile_id || null,
        enabled: form.enabled,
      });
      setAgentProfiles((current) => current.map((item) => item.agent_key === agentKey ? agent : item));
      patchAgentForm(agentKey, agentProfileForm(agent));
      setSaved(`agent:${agentKey}`);
      toast.success("Agent profile saved");
    } catch (err: any) {
      setError(err?.message || "Agent profile update failed");
    } finally {
      setSaving(null);
    }
  };

  return (
    <div className="space-y-5" data-testid="ai-registry-panel">
      <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
        <Pill tone="teal">Server-side agents</Pill>
        <span>Provider keys are encrypted server-side and never returned to the browser.</span>
      </div>
      {error && <div className="rounded-md border border-red-500/30 bg-red-500/5 px-3 py-2 text-sm text-red-600 dark:text-red-400">{error}</div>}

      <section className="space-y-3">
        <div className="flex items-center gap-2 text-sm font-semibold"><KeyRound className="h-4 w-4 text-muted-foreground" />Model provider credentials</div>
        <div className="grid gap-3">
          {providers.map((provider) => {
            const form = providerForms[provider.provider_key] || providerForm(provider);
            const saveKey = `provider:${provider.provider_key}`;
            const clearKey = `provider-clear:${provider.provider_key}`;
            const validateKey = `provider-validate:${provider.provider_key}`;
            const validation = validationResults[provider.provider_key];
            return (
              <form key={provider.id} onSubmit={(event) => saveProvider(event, provider.provider_key)} className="rounded-md border border-border/70 bg-background/50 p-3" data-testid={`ai-provider-${provider.provider_key}`}>
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <div className="text-sm font-medium">{provider.display_name}</div>
                    <div className="mt-0.5 font-mono text-[11px] text-muted-foreground">{provider.provider_key} / {provider.provider_type}</div>
                    {provider.provider_key === OPENCODE_GO_PROVIDER_KEY && (
                      <div className="mt-1 text-[11px] text-muted-foreground">OpenAI-compatible OpenCode Go provider. Configure server-side credentials here; browser never receives stored keys.</div>
                    )}
                  </div>
                  <div className="flex flex-wrap items-center gap-1.5">
                    <AiProviderPill provider={provider} />
                    {validation && <Pill tone={validationTone(validation.status)}>Validate: {validation.status}</Pill>}
                  </div>
                </div>
                <div className="mt-3 grid gap-3 lg:grid-cols-[180px_1fr_1fr_120px]">
                  <Field label="Display name" value={form.display_name} onChange={(value) => patchProviderForm(provider.provider_key, { display_name: value })} />
                  <Field
                    label="Base URL"
                    value={form.base_url}
                    placeholder={provider.provider_key === OPENCODE_GO_PROVIDER_KEY ? "http://127.0.0.1:4096/v1" : "https://api.openai.com/v1"}
                    onChange={(value) => patchProviderForm(provider.provider_key, { base_url: value })}
                  />
                  <Field
                    label="API key"
                    type="password"
                    value={form.api_key}
                    placeholder={provider.api_key_configured ? `configured ending ${provider.api_key_last_four}` : "paste key"}
                    onChange={(value) => patchProviderForm(provider.provider_key, { api_key: value, clear_api_key: false })}
                  />
                  <label className="flex items-end gap-2 text-sm">
                    <input className="mb-2 h-4 w-4" type="checkbox" checked={form.enabled} onChange={(event) => patchProviderForm(provider.provider_key, { enabled: event.target.checked })} />
                    <span className="mb-1">Enabled</span>
                  </label>
                </div>
                {validation?.message && (
                  <div className="mt-2 text-xs text-muted-foreground" data-testid={`ai-provider-validation-${provider.provider_key}`}>
                    {validation.message}
                    {validation.redacted_error && <span className="text-red-600 dark:text-red-400"> · {validation.redacted_error}</span>}
                  </div>
                )}
                <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                  <span className="text-xs text-muted-foreground">
                    {provider.api_key_configured ? `Stored key ending ${provider.api_key_last_four}` : "No stored key"}
                  </span>
                  <div className="flex items-center gap-2">
                    {saved === saveKey && <span className="text-xs text-emerald-600 dark:text-emerald-400">Saved.</span>}
                    {saved === clearKey && <span className="text-xs text-emerald-600 dark:text-emerald-400">Key cleared.</span>}
                    {saved === validateKey && <span className="text-xs text-emerald-600 dark:text-emerald-400">Validated.</span>}
                    <Button
                      size="sm"
                      type="button"
                      variant="outline"
                      disabled={!provider.api_key_configured || saving === clearKey}
                      onClick={() => setClearProvider(provider)}
                      data-testid={`ai-provider-clear-key-${provider.provider_key}`}
                    >
                      {saving === clearKey ? <RotateCw className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Trash2 className="mr-1.5 h-3.5 w-3.5" />}
                      Clear key
                    </Button>
                    <Button size="sm" type="button" variant="outline" disabled={saving === validateKey} onClick={() => validateProvider(provider.provider_key)} data-testid={`ai-provider-validate-${provider.provider_key}`}>
                      {saving === validateKey ? <RotateCw className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Check className="mr-1.5 h-3.5 w-3.5" />}
                      Validate
                    </Button>
                    <Button size="sm" type="submit" disabled={saving === saveKey} data-testid={`ai-provider-save-${provider.provider_key}`}>
                      {saving === saveKey ? <RotateCw className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Save className="mr-1.5 h-3.5 w-3.5" />}
                      Save provider
                    </Button>
                  </div>
                </div>
              </form>
            );
          })}
        </div>
      </section>

      <section className="space-y-6">

        <div className="space-y-3">
          <div className="flex items-center gap-2 text-sm font-semibold"><Bot className="h-4 w-4 text-muted-foreground" />Controlled agent flows</div>
          <div className="overflow-x-auto rounded-md border border-border/70">
            <table className="w-full min-w-[980px] text-sm">
              <thead>
                <tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                  <th className="px-3 py-2 font-medium">Agent</th>
                  <th className="px-3 py-2 font-medium">Type</th>
                  <th className="px-3 py-2 font-medium">Parent</th>
                  <th className="px-3 py-2 font-medium">Model</th>
                  <th className="px-3 py-2 font-medium">Approval</th>
                  <th className="px-3 py-2 font-medium">Enabled</th>
                  <th className="px-3 py-2 font-medium">Save</th>
                </tr>
              </thead>
              <tbody>
                {agentProfiles.map((agent) => {
                  const form = agentForms[agent.agent_key] || agentProfileForm(agent);
                  const saveKey = `agent:${agent.agent_key}`;
                  return (
                    <tr key={agent.id} className="border-b border-border/60 align-top last:border-0">
                      <td className="px-3 py-2.5">
                        <div className="font-medium">{agent.display_name}</div>
                        <div className="mt-1 max-w-md text-xs text-muted-foreground">{agent.description}</div>
                        <div className="mt-1 font-mono text-[11px] text-muted-foreground">{agent.agent_key}</div>
                      </td>
                      <td className="px-3 py-2.5">{agent.agent_type}</td>
                      <td className="px-3 py-2.5">{agent.parent_agent_key || "none"}</td>
                      <td className="px-3 py-2.5">
                        <select className="h-9 w-56 rounded-md border border-input bg-background px-2 text-sm" value={form.default_model_profile_id} onChange={(event) => patchAgentForm(agent.agent_key, { default_model_profile_id: event.target.value })}>
                          <option value="">No default</option>
                          {modelProfiles.map((profile) => <option key={profile.id} value={profile.id}>{profile.display_name}</option>)}
                        </select>
                      </td>
                      <td className="px-3 py-2.5">{agent.requires_human_approval ? "required" : "not required"}</td>
                      <td className="px-3 py-2.5">
                        <input className="h-4 w-4" type="checkbox" checked={form.enabled} onChange={(event) => patchAgentForm(agent.agent_key, { enabled: event.target.checked })} />
                      </td>
                      <td className="px-3 py-2.5">
                        <Button size="sm" type="button" variant="outline" disabled={saving === saveKey} onClick={() => saveAgentProfile(agent.agent_key)}>
                          {saving === saveKey ? <RotateCw className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Power className="mr-1.5 h-3.5 w-3.5" />}
                          Save
                        </Button>
                        {saved === saveKey && <div className="mt-1 text-xs text-emerald-600 dark:text-emerald-400">Saved.</div>}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>

        <div className="space-y-3">
          <div className="flex items-center gap-2 text-sm font-semibold"><Workflow className="h-4 w-4 text-muted-foreground" />Model profiles</div>
          {!hasOpenCodeGoProfile && (
            <form
              className="rounded-md border border-border/70 bg-background/50 p-3"
              onSubmit={createOpenCodeProfile}
              data-testid="ai-model-profile-create-opencode-go"
            >
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <div className="text-sm font-medium">OpenCode Go default</div>
                  <div className="mt-0.5 font-mono text-[11px] text-muted-foreground">{OPENCODE_GO_PROVIDER_KEY} / {OPENCODE_GO_PROFILE_KEY}</div>
                </div>
                <Pill tone="amber">Not created</Pill>
              </div>
              <div className="mt-3 grid gap-3">
                <Field label="Display name" value={openCodeProfileForm.display_name} onChange={(value) => patchOpenCodeProfileForm({ display_name: value })} />
                <Field label="Model" value={openCodeProfileForm.model_name} onChange={(value) => patchOpenCodeProfileForm({ model_name: value })} />
                <Field label="Runtime profile" value={openCodeProfileForm.runtime_profile} onChange={(value) => patchOpenCodeProfileForm({ runtime_profile: value })} />
              </div>
              <div className="mt-3 grid gap-3 sm:grid-cols-2">
                <Field label="Max context" type="number" value={openCodeProfileForm.max_context} onChange={(value) => patchOpenCodeProfileForm({ max_context: value })} />
                <Field label="Max output" type="number" value={openCodeProfileForm.max_output_tokens} onChange={(value) => patchOpenCodeProfileForm({ max_output_tokens: value })} />
                <Field label="Temperature" type="number" step="0.1" value={openCodeProfileForm.temperature} onChange={(value) => patchOpenCodeProfileForm({ temperature: value })} />
                <Field label="Timeout seconds" type="number" value={openCodeProfileForm.timeout_seconds} onChange={(value) => patchOpenCodeProfileForm({ timeout_seconds: value })} />
              </div>
              <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                <label className="inline-flex items-center gap-2 text-sm">
                  <input className="h-4 w-4" type="checkbox" checked={openCodeProfileForm.enabled} onChange={(event) => patchOpenCodeProfileForm({ enabled: event.target.checked })} />
                  Enabled
                </label>
                <div className="flex items-center gap-2">
                  {saved === createOpenCodeSaveKey && <span className="text-xs text-emerald-600 dark:text-emerald-400">Created.</span>}
                  <Button size="sm" type="submit" disabled={saving === createOpenCodeSaveKey}>
                    {saving === createOpenCodeSaveKey ? <RotateCw className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Plus className="mr-1.5 h-3.5 w-3.5" />}
                    Create profile
                  </Button>
                </div>
              </div>
            </form>
          )}
          {modelProfiles.length ? (
            <div className="space-y-3">
              {modelProfiles.map((profile) => {
                const form = modelForms[profile.profile_key] || modelProfileForm(profile);
                const saveKey = `model:${profile.profile_key}`;
                const deleteKey = `model-delete:${profile.profile_key}`;
                const assignedAgents = agentProfiles.filter((agent) => agent.default_model_profile_id === profile.id);
                return (
                  <form key={profile.id} className="rounded-md border border-border/70 bg-background/50 p-3" onSubmit={(event) => saveModelProfile(event, profile.profile_key)}>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div>
                        <div className="text-sm font-medium">{profile.display_name}</div>
                        <div className="mt-0.5 font-mono text-[11px] text-muted-foreground">{profile.provider_key} / {profile.profile_key}</div>
                      </div>
                      <div className="flex flex-wrap items-center gap-1.5">
                        {assignedAgents.length > 0 && <Pill tone="amber">Assigned to {assignedAgents.length}</Pill>}
                        <Pill tone={profile.enabled ? "green" : "slate"}>{profile.enabled ? "Enabled" : "Disabled"}</Pill>
                      </div>
                    </div>
                    <div className="mt-3 grid gap-3">
                      <Field label="Display name" value={form.display_name} onChange={(value) => patchModelForm(profile.profile_key, { display_name: value })} />
                      <Field label="Model" value={form.model_name} onChange={(value) => patchModelForm(profile.profile_key, { model_name: value })} />
                      <Field label="Runtime profile" value={form.runtime_profile} onChange={(value) => patchModelForm(profile.profile_key, { runtime_profile: value })} />
                    </div>
                    <div className="mt-3 grid gap-3 sm:grid-cols-2">
                      <Field label="Max context" type="number" value={form.max_context} onChange={(value) => patchModelForm(profile.profile_key, { max_context: value })} />
                      <Field label="Max output" type="number" value={form.max_output_tokens} onChange={(value) => patchModelForm(profile.profile_key, { max_output_tokens: value })} />
                      <Field label="Temperature" type="number" step="0.1" value={form.temperature} onChange={(value) => patchModelForm(profile.profile_key, { temperature: value })} />
                      <Field label="Timeout seconds" type="number" value={form.timeout_seconds} onChange={(value) => patchModelForm(profile.profile_key, { timeout_seconds: value })} />
                    </div>
                    <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                      <label className="inline-flex items-center gap-2 text-sm">
                        <input className="h-4 w-4" type="checkbox" checked={form.enabled} onChange={(event) => patchModelForm(profile.profile_key, { enabled: event.target.checked })} />
                        Enabled
                      </label>
                      <div className="flex items-center gap-2">
                        {saved === saveKey && <span className="text-xs text-emerald-600 dark:text-emerald-400">Saved.</span>}
                        <Button
                          size="sm"
                          type="button"
                          variant="outline"
                          className="text-red-500 hover:text-red-600"
                          disabled={saving === deleteKey}
                          title={assignedAgents.length > 0 ? "Delete model profile and clear these agent assignments" : "Delete model profile"}
                          onClick={() => setDeleteModelProfile(profile)}
                          data-testid={`ai-model-profile-delete-${profile.profile_key}`}
                        >
                          {saving === deleteKey ? <RotateCw className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Trash2 className="mr-1.5 h-3.5 w-3.5" />}
                          Delete
                        </Button>
                        <Button size="sm" type="submit" disabled={saving === saveKey}>
                          {saving === saveKey ? <RotateCw className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <SlidersHorizontal className="mr-1.5 h-3.5 w-3.5" />}
                          Save model
                        </Button>
                      </div>
                    </div>
                  </form>
                );
              })}
            </div>
          ) : (
            <p className="rounded-md border border-border/70 bg-muted/30 p-3 text-sm text-muted-foreground">No model profiles configured.</p>
          )}
        </div>
      </section>

      <ConfirmDialog
        open={!!clearProvider}
        onOpenChange={(open) => !open && setClearProvider(null)}
        title="Clear provider key"
        confirmLabel="Clear key"
        destructive
        description={<>Clearing the stored key for <span className="font-mono">{clearProvider?.display_name}</span> disables runtime calls that depend on that provider until a new key is saved.</>}
        requireApproval="I understand this stored provider credential will be removed."
        onConfirm={clearProviderCredential}
        testId="clear-provider-key-confirm"
      />

      <ConfirmDialog
        open={!!deleteModelProfile}
        onOpenChange={(open) => !open && setDeleteModelProfile(null)}
        title="Delete model profile"
        confirmLabel="Delete profile"
        destructive
        description={<>Deleting <span className="font-mono">{deleteModelProfile?.display_name}</span> removes this model routing profile and clears any controlled-agent assignments that use it.</>}
        requireText={deleteModelProfile?.profile_key}
        onConfirm={deleteSelectedModelProfile}
        testId="delete-model-profile-confirm"
      />
    </div>
  );
}

function Field({
  label,
  value,
  onChange,
  type = "text",
  step,
  placeholder,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  type?: string;
  step?: string;
  placeholder?: string;
}) {
  return (
    <label className="text-sm">
      <span className="mb-1 block text-xs text-muted-foreground">{label}</span>
      <Input type={type} step={step} value={value} placeholder={placeholder} autoComplete="off" onChange={(event) => onChange(event.target.value)} />
    </label>
  );
}

function AiProviderPill({ provider }: { provider: AiModelProvider }) {
  if (provider.api_key_configured && provider.enabled) return <Pill tone="green">Ready</Pill>;
  if (provider.api_key_configured) return <Pill tone="amber">Key saved, disabled</Pill>;
  return <Pill tone="slate">Missing key</Pill>;
}

export default function AdminPage() {
  const tokensState = useApi(() => api.getPluginTokens(), []);
  const devicesState = useApi(() => api.getPluginDevices(), []);
  const aiState = useApi(() => api.getAiAgents(), []);

  const [createOpen, setCreateOpen] = useState(false);
  const [name, setName] = useState("");
  const [scopes, setScopes] = useState<string[]>(PLUGIN_SCOPES);
  const [reveal, setReveal] = useState<{ token: string; label: string } | null>(null);
  const [rotate, setRotate] = useState<PluginToken | null>(null);
  const [revoke, setRevoke] = useState<PluginToken | null>(null);
  const [revokeDevice, setRevokeDevice] = useState<{ id: string; hostname: string } | null>(null);
  const [copied, setCopied] = useState(false);

  const toggleScope = (s: string) => setScopes((p) => (p.includes(s) ? p.filter((x) => x !== s) : [...p, s]));

  const create = async () => {
    if (!name.trim()) { toast.error("Token name is required."); return; }
    const t = await api.createPluginToken(name.trim(), scopes);
    setCreateOpen(false); setName(""); setScopes(PLUGIN_SCOPES);
    setReveal({ token: t.plain_token!, label: t.name });
    tokensState.reload();
  };
  const doRotate = async () => { const t = await api.rotatePluginToken(rotate!.id); setReveal({ token: t.plain_token!, label: `${t.name} (rotated)` }); tokensState.reload(); };
  const doRevoke = async () => { await api.revokePluginToken(revoke!.id); toast.success("Token revoked"); tokensState.reload(); };
  const doRevokeDevice = async () => { await api.revokeDevice(revokeDevice!.id); toast.success("Device revoked"); devicesState.reload(); };

  const copy = (v: string) => { navigator.clipboard?.writeText(v); setCopied(true); setTimeout(() => setCopied(false), 1500); };

  return (
    <div className="space-y-5" data-testid="admin-page">
      <PageHeader title="Admin — Plugin Tokens & Devices" subtitle="Tokens authenticate the local Python CLI/MCP plugin. Secrets are shown once and never stored or displayed again."
        actions={<Button size="sm" onClick={() => setCreateOpen(true)} data-testid="create-token-btn"><Plus className="mr-1.5 h-3.5 w-3.5" /> Create token</Button>} />

      <AiRegistryPanel state={aiState} />

      <Panel title="Plugin tokens" dense>
        <DataState state={tokensState} isEmpty={(d) => d.length === 0}>
          {(tokens) => (
            <table className="w-full text-sm">
              <thead><tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                <th className="px-4 py-2 font-medium">Name</th><th className="px-3 py-2 font-medium">Prefix</th><th className="px-3 py-2 font-medium">Scopes</th>
                <th className="px-3 py-2 font-medium">Created</th><th className="px-3 py-2 font-medium">Last used</th><th className="px-3 py-2 font-medium">Status</th><th className="px-3 py-2 text-right font-medium">Actions</th>
              </tr></thead>
              <tbody>{tokens.map((t) => (
                <tr key={t.id} className="border-b border-border/60 last:border-0 hover:bg-accent/30" data-testid={`token-row-${t.id}`}>
                  <td className="px-4 py-2.5 font-medium">{t.name}</td>
                  <td className="px-3 py-2.5 font-mono text-xs">{t.prefix}<span className="text-muted-foreground">…••••</span></td>
                  <td className="px-3 py-2.5"><div className="flex flex-wrap gap-1">{t.scopes.map((s) => <Pill key={s} tone="slate">{s}</Pill>)}</div></td>
                  <td className="px-3 py-2.5 text-xs text-muted-foreground">{relativeTime(t.created_at)}</td>
                  <td className="px-3 py-2.5 text-xs text-muted-foreground">{relativeTime(t.last_used_at)}</td>
                  <td className="px-3 py-2.5">{t.revoked ? <Pill tone="red">Revoked</Pill> : <Pill tone="green">Active</Pill>}</td>
                  <td className="px-3 py-2.5">
                    <div className="flex items-center justify-end gap-1">
                      <Button size="sm" variant="ghost" disabled={t.revoked} onClick={() => setRotate(t)} data-testid={`rotate-${t.id}`} title="Rotate"><RotateCw className="h-3.5 w-3.5" /></Button>
                      <Button size="sm" variant="ghost" disabled={t.revoked} className="text-red-500 hover:text-red-600" onClick={() => setRevoke(t)} data-testid={`revoke-${t.id}`} title="Revoke"><Trash2 className="h-3.5 w-3.5" /></Button>
                    </div>
                  </td>
                </tr>
              ))}</tbody>
            </table>
          )}
        </DataState>
      </Panel>

      <Panel title="Registered devices" dense>
        <DataState state={devicesState} isEmpty={(d) => d.length === 0}>
          {(devices) => (
            <table className="w-full text-sm">
              <thead><tr className="border-b border-border text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                <th className="px-4 py-2 font-medium">Host</th><th className="px-3 py-2 font-medium">OS</th><th className="px-3 py-2 font-medium">Plugin</th>
                <th className="px-3 py-2 font-medium">Token</th><th className="px-3 py-2 font-medium">Last seen</th><th className="px-3 py-2 font-medium">Status</th><th className="px-3 py-2 text-right font-medium">Actions</th>
              </tr></thead>
              <tbody>{devices.map((d) => (
                <tr key={d.id} className="border-b border-border/60 last:border-0 hover:bg-accent/30" data-testid={`device-row-${d.id}`}>
                  <td className="px-4 py-2.5"><span className="flex items-center gap-2 font-medium"><Laptop className="h-3.5 w-3.5 text-muted-foreground" />{d.hostname}</span></td>
                  <td className="px-3 py-2.5 text-xs">{d.os}</td>
                  <td className="px-3 py-2.5 font-mono text-xs">v{d.plugin_version}</td>
                  <td className="px-3 py-2.5 font-mono text-xs">{d.token_prefix}…</td>
                  <td className="px-3 py-2.5 text-xs text-muted-foreground">{relativeTime(d.last_seen_at)}</td>
                  <td className="px-3 py-2.5"><Pill tone={d.status === "active" ? "green" : d.status === "stale" ? "amber" : "red"}>{d.status}</Pill></td>
                  <td className="px-3 py-2.5"><div className="flex justify-end"><Button size="sm" variant="ghost" disabled={d.status === "revoked"} className="text-red-500 hover:text-red-600" onClick={() => setRevokeDevice({ id: d.id, hostname: d.hostname })} data-testid={`revoke-device-${d.id}`} title="Revoke device"><Trash2 className="h-3.5 w-3.5" /></Button></div></td>
                </tr>
              ))}</tbody>
            </table>
          )}
        </DataState>
      </Panel>

      {/* Create token dialog */}
      <Dialog open={createOpen} onOpenChange={setCreateOpen}>
        <DialogContent data-testid="create-token-dialog">
          <DialogHeader><DialogTitle>Create plugin token</DialogTitle><DialogDescription>The plaintext token is shown only once. Store it in the plugin securely.</DialogDescription></DialogHeader>
          <div className="space-y-4">
            <div className="space-y-1.5"><Label className="text-xs">Token name</Label><Input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. dev-mbp primary" data-testid="token-name-input" autoFocus /></div>
            <div className="space-y-1.5"><Label className="text-xs">Scopes</Label><div className="flex flex-wrap gap-2">{PLUGIN_SCOPES.map((s) => (
              <button key={s} type="button" onClick={() => toggleScope(s)} data-testid={`scope-${s}`} className={`rounded-md border px-2.5 py-1 text-xs font-mono ${scopes.includes(s) ? "border-primary bg-primary/10 text-primary" : "border-border text-muted-foreground"}`}>{s}</button>
            ))}</div></div>
          </div>
          <DialogFooter><Button variant="outline" onClick={() => setCreateOpen(false)}>Cancel</Button><Button onClick={create} data-testid="confirm-create-token"><KeyRound className="mr-1.5 h-3.5 w-3.5" /> Create</Button></DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Reveal-once dialog */}
      <Dialog open={!!reveal} onOpenChange={(v) => !v && setReveal(null)}>
        <DialogContent data-testid="token-reveal-dialog">
          <DialogHeader><DialogTitle className="flex items-center gap-2"><ShieldAlert className="h-4 w-4 text-amber-500" /> Copy your token now</DialogTitle>
            <DialogDescription>This is the only time the plaintext token for <span className="font-medium">{reveal?.label}</span> will be shown. It is not stored and cannot be retrieved again.</DialogDescription></DialogHeader>
          <div className="flex items-center gap-2 rounded-md border border-border bg-muted/30 p-3">
            <code className="flex-1 break-all font-mono text-xs" data-testid="plain-token-value">{reveal?.token}</code>
            <Button size="sm" variant="outline" onClick={() => copy(reveal!.token)} data-testid="copy-token-btn">{copied ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}</Button>
          </div>
          <DialogFooter><Button onClick={() => setReveal(null)} data-testid="reveal-done-btn">I've stored it</Button></DialogFooter>
        </DialogContent>
      </Dialog>

      <ConfirmDialog open={!!rotate} onOpenChange={(v) => !v && setRotate(null)} title="Rotate token" confirmLabel="Rotate token" destructive
        description={<>Rotating <span className="font-mono">{rotate?.name}</span> immediately invalidates the current secret. The plugin must be updated with the new token. A new plaintext token will be shown once.</>}
        requireApproval="I understand the existing token will stop working immediately after rotation." onConfirm={doRotate} testId="rotate-confirm" />

      <ConfirmDialog open={!!revoke} onOpenChange={(v) => !v && setRevoke(null)} title="Revoke token" confirmLabel="Revoke token" destructive
        description={<>Revoking <span className="font-mono">{revoke?.name}</span> permanently disables it. Any device using it will lose access.</>}
        requireText={revoke?.name} onConfirm={doRevoke} testId="revoke-confirm" />

      <ConfirmDialog open={!!revokeDevice} onOpenChange={(v) => !v && setRevokeDevice(null)} title="Revoke device" confirmLabel="Revoke device" destructive
        description={<>Revoking <span className="font-mono">{revokeDevice?.hostname}</span> blocks it from syncing until it re-registers with a valid token.</>}
        requireApproval="I understand this device will be blocked from syncing immediately." onConfirm={doRevokeDevice} testId="revoke-device-confirm" />
    </div>
  );
}
