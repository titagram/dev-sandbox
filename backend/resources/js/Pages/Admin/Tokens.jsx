import { router } from '@inertiajs/react';
import { Clipboard, KeyRound, Trash2 } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

const defaultScopes = ['projects.read', 'repositories.read', 'runs.write', 'artifacts.write', 'wiki.write'];

export default function Tokens({ tokens, dashboard }) {
  const [name, setName] = useState('Gabriele local plugin');
  const [expiresInDays, setExpiresInDays] = useState(90);
  const [plainToken, setPlainToken] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState(null);

  async function createToken(event) {
    event.preventDefault();
    setSubmitting(true);
    setError(null);

    const response = await fetch('/admin/plugin-tokens', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
      },
      body: JSON.stringify({
        name,
        scopes: defaultScopes,
        expires_in_days: Number(expiresInDays),
      }),
    });

    setSubmitting(false);

    if (!response.ok) {
      setError('Token creation failed.');
      return;
    }

    const payload = await response.json();
    setPlainToken(payload.plain_token);
    router.reload({ only: ['tokens'] });
  }

  function revokeToken(tokenId) {
    router.delete(`/admin/plugin-tokens/${tokenId}`, {
      preserveScroll: true,
      only: ['tokens'],
    });
  }

  return (
    <AppLayout title="Admin / Plugin Tokens" dashboard={dashboard}>
      <h1 className="text-xl font-semibold">Admin / Plugin Tokens</h1>

      <section className="mt-4 rounded border border-zinc-200 bg-white p-4">
        <div className="flex items-center gap-2 font-semibold">
          <KeyRound size={16} />
          Create Token
        </div>
        <form onSubmit={createToken} className="mt-3 grid gap-3 lg:grid-cols-[1fr_120px_auto]">
          <label className="text-sm">
            <span className="mb-1 block text-xs text-zinc-500">Name</span>
            <input className="h-9 w-full rounded border border-zinc-300 px-3 text-sm" value={name} onChange={(event) => setName(event.target.value)} />
          </label>
          <label className="text-sm">
            <span className="mb-1 block text-xs text-zinc-500">Expiry days</span>
            <input className="h-9 w-full rounded border border-zinc-300 px-3 text-sm" type="number" min="1" max="365" value={expiresInDays} onChange={(event) => setExpiresInDays(event.target.value)} />
          </label>
          <button disabled={submitting} type="submit" className="mt-5 h-9 rounded bg-zinc-950 px-3 text-sm font-medium text-white disabled:opacity-60">
            Create token
          </button>
        </form>
        <div className="mt-2 text-xs text-zinc-500">Scopes: {defaultScopes.join(', ')}</div>
        <div className="mt-1 text-xs text-zinc-500">The full token is shown once and is never rendered in the active token list.</div>
        {error ? <div className="mt-2 text-xs text-red-700">{error}</div> : null}
        {plainToken ? (
          <div className="mt-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm">
            <div className="font-medium text-amber-950">Copy this token now. It will not be shown again.</div>
            <div className="mt-2 flex items-center gap-2">
              <code className="min-w-0 flex-1 overflow-x-auto rounded bg-white px-2 py-1 text-xs">{plainToken}</code>
              <button type="button" className="inline-flex h-8 items-center gap-1 rounded border border-amber-300 px-2 text-xs" onClick={() => navigator.clipboard?.writeText(plainToken)}>
                <Clipboard size={13} />
                Copy
              </button>
            </div>
          </div>
        ) : null}
      </section>

      <section className="mt-4 overflow-hidden rounded border border-zinc-200 bg-white">
        <div className="border-b border-zinc-200 px-4 py-3 text-sm font-semibold">Active Tokens</div>
        <div className="overflow-x-auto">
          <table className="min-w-[920px] w-full text-left text-sm">
            <thead className="bg-zinc-50 text-xs text-zinc-500">
              <tr>
                <th className="p-3">token prefix</th>
                <th>user</th>
                <th>device</th>
                <th>scopes</th>
                <th>last used</th>
                <th>state</th>
                <th>revoke</th>
              </tr>
            </thead>
            <tbody>
              {tokens.map((token) => (
                <tr key={token.id} className="border-t border-zinc-100">
                  <td className="p-3 font-mono text-xs">{token.token_prefix}</td>
                  <td>{token.user_email}</td>
                  <td>{token.device_name ?? 'unbound'}</td>
                  <td className="max-w-md truncate">{token.scopes}</td>
                  <td>{token.last_used_at ?? 'never'}</td>
                  <td>{token.revoked_at ? 'revoked' : 'active'}</td>
                  <td>
                    <button
                      type="button"
                      disabled={Boolean(token.revoked_at)}
                      className="inline-flex h-8 w-8 items-center justify-center rounded border border-zinc-200 text-zinc-600 disabled:opacity-40"
                      onClick={() => revokeToken(token.id)}
                      title="Revoke token"
                    >
                      <Trash2 size={14} />
                    </button>
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
