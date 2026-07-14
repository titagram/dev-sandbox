import React, { useState } from "react";
import { useNavigate } from "react-router-dom";
import { Terminal, ArrowRight, ShieldCheck, Lock } from "lucide-react";
import { API_BASE_URL, USE_MOCK } from "@/api/devboardApi";
import { useAuth } from "@/context/AuthContext";
import { useTheme } from "@/context/ThemeContext";
import { Role } from "@/types/devboard";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { navForRole, ROLE_LABEL } from "@/lib/nav";
import { cn } from "@/lib/utils";
import { Moon, Sun } from "lucide-react";

const ROLES: { role: Role; email: string; desc: string }[] = [
  { role: "admin", email: "admin@devboard.local", desc: "All sections incl. Admin & System." },
  { role: "pm", email: "pm@devboard.local", desc: "Projects, Kanban, Runs, Wiki, Graph, Artifacts." },
  { role: "developer", email: "dev@devboard.local", desc: "Adds Quality (read-only)." },
  { role: "sysadmin", email: "sysadmin@devboard.local", desc: "Projects, Runs, Artifacts, Quality, System." },
];

export default function LoginPage() {
  const { login } = useAuth();
  const { theme, toggle } = useTheme();
  const nav = useNavigate();
  const [role, setRole] = useState<Role>("admin");
  const [email, setEmail] = useState("admin@devboard.local");
  const [password, setPassword] = useState("devboard");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const pick = (r: Role, em: string) => { setRole(r); setEmail(em); setError(null); };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true); setError(null);
    try {
      const u = await login({ email, password, role });
      const first = navForRole(u.role)[0];
      nav(first ? first.path : "/projects");
    } catch (err: any) {
      setError(err?.message || "Sign in failed.");
    } finally { setBusy(false); }
  };

  return (
    <div className="grid min-h-screen grid-cols-1 bg-background lg:grid-cols-2">
      {/* Left: operational context panel */}
      <div className="relative hidden flex-col justify-between border-r border-border bg-card/40 p-10 lg:flex">
        <div className="absolute inset-0 grid-noise opacity-40" />
        <div className="relative">
          <div className="flex items-center gap-2">
            <div className="grid h-9 w-9 place-items-center rounded-md bg-primary text-primary-foreground">
              <Terminal className="h-4 w-4" />
            </div>
            <div>
              <p className="text-base font-semibold tracking-tight">Hades Agent</p>
              <p className="text-[10px] uppercase tracking-widest text-muted-foreground">Self-hosted operations</p>
            </div>
          </div>
        </div>
        <div className="relative space-y-5">
          <h2 className="text-lg font-medium leading-snug">
            Operational dashboard for teams running AI coding agents, local plugins,
            graph imports, and automated quality verification.
          </h2>
          <ul className="space-y-2 text-sm text-muted-foreground">
            {["Local-only Git sources — snapshots are never labelled as remote truth",
              "Every technical fact shows source metadata & status",
              "Quality verifies domain truth, not only HTTP 200",
              "Destructive operations require explicit human approval"].map((t) => (
              <li key={t} className="flex items-start gap-2">
                <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-primary" /><span>{t}</span>
              </li>
            ))}
          </ul>
        </div>
        <p className="relative font-mono text-[11px] text-muted-foreground">
          API base: <span className="text-foreground">{USE_MOCK ? "/api/dashboard" : API_BASE_URL}</span> · {USE_MOCK ? "mock adapter active" : "HTTP adapter active"}
        </p>
      </div>

      {/* Right: sign in */}
      <div className="flex flex-col">
        <div className="flex justify-end p-4">
          <Button variant="ghost" size="icon" onClick={toggle} data-testid="theme-toggle">
            {theme === "dark" ? <Sun className="h-4 w-4" /> : <Moon className="h-4 w-4" />}
          </Button>
        </div>
        <div className="flex flex-1 items-center justify-center px-6 pb-16">
          <form onSubmit={submit} className="w-full max-w-sm space-y-6" data-testid="login-form">
            <div>
              <h1 className="text-xl font-semibold tracking-tight">Sign in</h1>
              <p className="mt-1 text-sm text-muted-foreground">Select a role to explore the dashboard with realistic mock data.</p>
            </div>

            <div className="grid grid-cols-2 gap-2">
              {ROLES.map((r) => (
                <button
                  type="button"
                  key={r.role}
                  onClick={() => pick(r.role, r.email)}
                  data-testid={`role-${r.role}`}
                  className={cn(
                    "rounded-md border px-3 py-2.5 text-left transition-colors",
                    role === r.role ? "border-primary bg-primary/10" : "border-border hover:bg-accent",
                  )}
                >
                  <span className="text-sm font-medium">{ROLE_LABEL[r.role]}</span>
                  <span className="mt-0.5 block text-[11px] leading-tight text-muted-foreground">{r.desc}</span>
                </button>
              ))}
            </div>

            <div className="space-y-3">
              <div className="space-y-1.5">
                <Label htmlFor="email" className="text-xs">Email</Label>
                <Input id="email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} data-testid="login-email" />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="password" className="text-xs">Password</Label>
                <Input id="password" type="password" value={password} onChange={(e) => setPassword(e.target.value)} data-testid="login-password" />
              </div>
            </div>

            {error && <p className="text-xs text-red-500" data-testid="login-error">{error}</p>}

            <Button type="submit" className="w-full" disabled={busy} data-testid="login-submit">
              {busy ? "Signing in…" : <>Sign in as {ROLE_LABEL[role]} <ArrowRight className="ml-1.5 h-4 w-4" /></>}
            </Button>

            <p className="flex items-center justify-center gap-1.5 text-[11px] text-muted-foreground">
              <Lock className="h-3 w-3" /> Cookie/session auth · credentials: include (Laravel)
            </p>
          </form>
        </div>
      </div>
    </div>
  );
}
