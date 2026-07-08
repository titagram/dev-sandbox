import { Link } from '@inertiajs/react';
import {
  Activity,
  Bot,
  Boxes,
  GitBranch,
  KanbanSquare,
  KeyRound,
  Library,
  LogOut,
  Network,
  Server,
} from 'lucide-react';

const icons = {
  projects: Boxes,
  kanban: KanbanSquare,
  runs: Activity,
  wiki: Library,
  graph: Network,
  artifacts: GitBranch,
  admin: KeyRound,
  'ai-agents': Bot,
  system: Server,
};

export default function AppLayout({ title, dashboard, children }) {
  const navigation = dashboard?.navigation ?? [];
  const roles = dashboard?.user?.roles?.join(', ') ?? 'guest';

  return (
    <div className="min-h-screen bg-zinc-100 text-zinc-950">
      <header className="flex min-h-14 items-center justify-between gap-4 border-b border-zinc-200 bg-white px-5">
        <div className="flex items-center gap-3">
          <div className="grid h-7 w-7 place-items-center rounded bg-zinc-950 text-xs font-semibold text-white">DB</div>
          <div>
            <div className="text-sm font-semibold">DevBoard</div>
            <div className="text-[11px] text-zinc-500">self-hosted workspace</div>
          </div>
        </div>
        <div className="flex items-center gap-3 text-xs text-zinc-500">
          <span className="hidden sm:inline">{title}</span>
          {dashboard?.user ? (
            <span className="hidden max-w-56 truncate md:inline">
              {dashboard.user.name} · {roles}
            </span>
          ) : null}
          {dashboard?.user ? (
            <Link
              href="/logout"
              method="post"
              as="button"
              className="inline-flex h-8 w-8 items-center justify-center rounded border border-zinc-200 text-zinc-600 hover:bg-zinc-100"
              title="Sign out"
            >
              <LogOut size={15} />
            </Link>
          ) : null}
        </div>
      </header>
      <div className="grid min-h-[calc(100vh-56px)] grid-cols-1 lg:grid-cols-[190px_1fr]">
        <aside className="border-b border-zinc-200 bg-white px-3 py-2 lg:border-b-0 lg:border-r lg:py-4">
          <nav className="flex gap-1 overflow-x-auto lg:block lg:space-y-1">
            {navigation.map(({ label, href, key }) => {
              const Icon = icons[key] ?? Boxes;

              return (
                <Link key={label} href={href} className="flex shrink-0 items-center gap-2 rounded px-2 py-2 text-sm text-zinc-700 hover:bg-zinc-100">
                  <Icon size={16} />
                  {label}
                </Link>
              );
            })}
          </nav>
        </aside>
        <main className="p-5">{children}</main>
      </div>
    </div>
  );
}
