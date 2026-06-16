import { Head, useForm } from '@inertiajs/react';
import { KeyRound, LogIn } from 'lucide-react';

export default function Login() {
  const { data, setData, post, processing, errors } = useForm({
    email: '',
    password: '',
  });

  function submit(event) {
    event.preventDefault();
    post('/login');
  }

  return (
    <div className="min-h-screen bg-zinc-100 text-zinc-950">
      <Head title="DevBoard Login" />
      <div className="grid min-h-screen place-items-center px-4">
        <form onSubmit={submit} className="w-full max-w-sm rounded border border-zinc-200 bg-white p-6 shadow-sm">
          <div className="mb-6 flex items-center gap-3">
            <div className="grid h-9 w-9 place-items-center rounded bg-zinc-950 text-white">
              <KeyRound size={18} />
            </div>
            <div>
              <h1 className="text-base font-semibold">DevBoard</h1>
              <p className="text-xs text-zinc-500">Self-hosted dashboard access</p>
            </div>
          </div>

          <label className="mb-4 block">
            <span className="mb-1 block text-xs font-medium text-zinc-600">Email</span>
            <input
              className="h-10 w-full rounded border border-zinc-300 bg-white px-3 text-sm outline-none focus:border-zinc-950"
              type="email"
              value={data.email}
              autoComplete="email"
              onChange={(event) => setData('email', event.target.value)}
            />
            {errors.email ? <span className="mt-1 block text-xs text-red-700">{errors.email}</span> : null}
          </label>

          <label className="mb-5 block">
            <span className="mb-1 block text-xs font-medium text-zinc-600">Password</span>
            <input
              className="h-10 w-full rounded border border-zinc-300 bg-white px-3 text-sm outline-none focus:border-zinc-950"
              type="password"
              value={data.password}
              autoComplete="current-password"
              onChange={(event) => setData('password', event.target.value)}
            />
            {errors.password ? <span className="mt-1 block text-xs text-red-700">{errors.password}</span> : null}
          </label>

          <button
            type="submit"
            disabled={processing}
            className="inline-flex h-10 w-full items-center justify-center gap-2 rounded bg-zinc-950 px-3 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-60"
          >
            <LogIn size={16} />
            Sign in
          </button>
        </form>
      </div>
    </div>
  );
}
