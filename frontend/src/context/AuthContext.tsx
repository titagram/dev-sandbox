import React, { createContext, useContext, useEffect, useState } from "react";
import { api } from "@/api/devboardApi";
import { LoginPayload, User } from "@/types/devboard";

interface AuthCtx {
  user: User | null;
  loading: boolean;
  login: (p: LoginPayload) => Promise<User>;
  logout: () => Promise<void>;
}

const Ctx = createContext<AuthCtx>(null as any);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;
    api.me().then((u) => { if (active) { setUser(u); setLoading(false); } }).catch(() => active && setLoading(false));
    return () => { active = false; };
  }, []);

  const login = async (p: LoginPayload) => {
    const u = await api.login(p);
    setUser(u);
    return u;
  };
  const logout = async () => {
    await api.logout();
    setUser(null);
  };

  return <Ctx.Provider value={{ user, loading, login, logout }}>{children}</Ctx.Provider>;
}

export const useAuth = () => useContext(Ctx);
