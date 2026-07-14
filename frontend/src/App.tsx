import React from "react";
import { BrowserRouter, Routes, Route, Navigate, useLocation } from "react-router-dom";
import { AuthProvider, useAuth } from "@/context/AuthContext";
import { ThemeProvider } from "@/context/ThemeContext";
import { Toaster } from "@/components/ui/sonner";
import { canAccess, navForRole } from "@/lib/nav";
import { Loader2, ShieldX } from "lucide-react";

import AppShell from "@/components/devboard/AppShell";
import LoginPage from "@/pages/LoginPage";
import OverviewPage from "@/pages/OverviewPage";
import ProjectsPage from "@/pages/ProjectsPage";
import ProjectDetailPage from "@/pages/ProjectDetailPage";
import KanbanPage from "@/pages/KanbanPage";
import ProjectMemoryPage from "@/pages/ProjectMemoryPage";
import AgentWorkPage from "@/pages/AgentWorkPage";
import AskAgentsPage from "@/pages/AskAgentsPage";
import AgentChatPage from "@/pages/AgentChatPage";
import EngineeringPage from "@/pages/EngineeringPage";
import RunsPage from "@/pages/RunsPage";
import RunDetailPage from "@/pages/RunDetailPage";
import TaskDetailPage from "@/pages/TaskDetailPage";
import WikiPage from "@/pages/WikiPage";
import WikiPageDetailPage from "@/pages/WikiPageDetailPage";
import GraphPage from "@/pages/GraphPage";
import ArtifactsPage from "@/pages/ArtifactsPage";
import QualityCenterPage from "@/pages/quality/QualityCenterPage";
import RouteInventoryPage from "@/pages/quality/RouteInventoryPage";
import RouteSmokePage from "@/pages/quality/RouteSmokePage";
import QualityGatePage from "@/pages/quality/QualityGatePage";
import AdminPage from "@/pages/AdminPage";
import HadesAdminPage from "@/pages/HadesAdminPage";
import SystemPage from "@/pages/SystemPage";

function FullLoader() {
  return (
    <div className="grid min-h-screen place-items-center bg-background">
      <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
    </div>
  );
}

function NotAuthorized() {
  return (
    <div className="grid place-items-center py-24 text-center">
      <ShieldX className="mb-3 h-9 w-9 text-red-500" />
      <h1 className="text-lg font-semibold">Not authorized</h1>
      <p className="mt-1 max-w-sm text-sm text-muted-foreground">
        Your role does not have access to this section. Use the navigation to return to an allowed area.
      </p>
    </div>
  );
}

function Protected({ children }: { children: React.ReactNode }) {
  const { user, loading } = useAuth();
  if (loading) return <FullLoader />;
  if (!user) return <Navigate to="/login" replace />;
  return <>{children}</>;
}

/** Guards a section by nav key; agents have no dashboard navigation. */
function Section({ navKey, children }: { navKey: string; children: React.ReactNode }) {
  const { user } = useAuth();
  if (!user || user.role === "agent") return <NotAuthorized />;
  const { ok } = canAccess(user.role, navKey);
  if (!ok) return <NotAuthorized />;
  return <>{children}</>;
}

function IndexRedirect() {
  const { user } = useAuth();
  const first = user ? navForRole(user.role)[0] : null;
  return <Navigate to={first ? first.path : "/login"} replace />;
}

function LoginGate() {
  const { user, loading } = useAuth();
  const loc = useLocation();
  if (loading) return <FullLoader />;
  if (user) {
    const first = navForRole(user.role)[0];
    return <Navigate to={(loc.state as any)?.from || (first ? first.path : "/projects")} replace />;
  }
  return <LoginPage />;
}

export default function App() {
  return (
    <ThemeProvider>
      <AuthProvider>
        <BrowserRouter>
          <Routes>
            <Route path="/login" element={<LoginGate />} />
            <Route element={<Protected><AppShell /></Protected>}>
              <Route index element={<IndexRedirect />} />
              <Route path="/overview" element={<Section navKey="overview"><OverviewPage /></Section>} />
              <Route path="/projects" element={<Section navKey="projects"><ProjectsPage /></Section>} />
              <Route path="/projects/:projectId" element={<Section navKey="projects"><ProjectDetailPage /></Section>} />
              <Route path="/projects/:projectId/kanban" element={<Section navKey="kanban"><KanbanPage /></Section>} />
              <Route path="/projects/:projectId/agent-work" element={<Section navKey="agent-work"><AgentWorkPage /></Section>} />
              <Route path="/projects/:projectId/ask" element={<Section navKey="ask"><AskAgentsPage /></Section>} />
              <Route path="/projects/:projectId/agent-chat" element={<Section navKey="agent-chat"><AgentChatPage /></Section>} />
              <Route path="/projects/:projectId/memory" element={<Section navKey="memory"><ProjectMemoryPage /></Section>} />
              <Route path="/projects/:projectId/engineering" element={<Section navKey="engineering"><EngineeringPage /></Section>} />
              <Route path="/projects/:projectId/runs" element={<Section navKey="runs"><RunsPage /></Section>} />
              <Route path="/projects/:projectId/runs/:runId" element={<Section navKey="runs"><RunDetailPage /></Section>} />
              <Route path="/projects/:projectId/wiki" element={<Section navKey="wiki"><WikiPage /></Section>} />
              <Route path="/projects/:projectId/wiki/:pageId" element={<Section navKey="wiki"><WikiPageDetailPage /></Section>} />
              <Route path="/projects/:projectId/graph" element={<Section navKey="graph"><GraphPage /></Section>} />
              <Route path="/projects/:projectId/artifacts" element={<Section navKey="artifacts"><ArtifactsPage /></Section>} />
              <Route path="/kanban" element={<Section navKey="kanban"><KanbanPage /></Section>} />
              <Route path="/ask" element={<Section navKey="ask"><AskAgentsPage /></Section>} />
              <Route path="/agent-chat" element={<Section navKey="agent-chat"><AgentChatPage /></Section>} />
              <Route path="/memory" element={<Section navKey="memory"><ProjectMemoryPage /></Section>} />
              <Route path="/engineering" element={<Section navKey="engineering"><EngineeringPage /></Section>} />
              <Route path="/runs" element={<Section navKey="runs"><RunsPage /></Section>} />
              <Route path="/runs/:runId" element={<Section navKey="runs"><RunDetailPage /></Section>} />
              <Route path="/tasks/:taskId" element={<Section navKey="kanban"><TaskDetailPage /></Section>} />
              <Route path="/wiki" element={<Section navKey="wiki"><WikiPage /></Section>} />
              <Route path="/wiki/:pageId" element={<Section navKey="wiki"><WikiPageDetailPage /></Section>} />
              <Route path="/graph" element={<Section navKey="graph"><GraphPage /></Section>} />
              <Route path="/artifacts" element={<Section navKey="artifacts"><ArtifactsPage /></Section>} />
              <Route path="/quality" element={<Section navKey="quality"><QualityCenterPage /></Section>} />
              <Route path="/quality/route-inventory" element={<Section navKey="quality"><RouteInventoryPage /></Section>} />
              <Route path="/quality/route-smoke" element={<Section navKey="quality"><RouteSmokePage /></Section>} />
              <Route path="/quality/gates/:gate" element={<Section navKey="quality"><QualityGatePage /></Section>} />
              <Route path="/admin" element={<Section navKey="admin"><AdminPage /></Section>} />
              <Route path="/admin/hades" element={<Section navKey="hades"><HadesAdminPage /></Section>} />
              <Route path="/system" element={<Section navKey="system"><SystemPage /></Section>} />
            </Route>
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
          <Toaster position="top-right" />
        </BrowserRouter>
      </AuthProvider>
    </ThemeProvider>
  );
}
