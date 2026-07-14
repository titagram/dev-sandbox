import {
  AgentChatCreateInput, AgentChatDetailResponse, AgentChatMessageInput, AgentChatResponse,
  AgentWorkCreateInput, AgentWorkDetailResponse, AgentWorkItem, AgentWorkResponse,
  AiAgentProfile, AiAgentProfileInput, AiAgentsSnapshot, AiModelProfile, AiModelProfileCreateInput, AiModelProfileInput, AiModelProvider, AiModelProviderInput, AiModelProviderValidationResult,
  Artifact, AssistantApplyResponse, AssistantRunResponse, AssistantSuggestionResponse, AuthMatrixRow, BacklogTriagePayload, BackupDryRunReport, BackupExport, BackupReadiness, DashboardGraphQueryRequest, DashboardGraphResponse, GraphView, IntakeNormalizeResponse, KanbanBoard, LoginPayload, PluginDevice,
  MemoryCreateInput,
  MemoryUpdateInput,
  PluginToken, DashboardOverview, Project, ProjectDetail, ProjectInput, ProjectLifecycleInput, ProjectStatusFilter, QualityCurrentState, QualityGate, QualityOverview,
  ProjectMemoryEntry, ProjectMemoryImportBatch, ProjectMemoryImportInput, ProjectMemoryQuery, ProjectMemoryResponse, ProjectWorkspaceBinding,
  QualityReport, RepositoryDeclarationInput, RoadmapPhase, RouteInventoryEntry, RouteSmokeRow, RunDetail, RunSummary,
  SecurityCheck, SystemStatus, TaskAttachment, TaskClarificationPayload, TaskCreateInput, TaskDetail, TaskMutationInput, TruthRegistryEntry, User, WikiPageDetail, WikiPageSummary, WikiPageWriteInput, WikiRefreshRequest, WikiRefreshRequestInput,
  HadesAdminSnapshot, HadesBootstrapTokenCreateInput, HadesBootstrapTokenCreateResponse, HadesJobCreateInput, HadesJobCreateResponse, HadesMemoryProposalReviewInput, HadesMemoryProposalReviewResponse,
} from "@/types/devboard";
import { API_BASE_URL } from "@/api/apiBaseUrl";

/**
 * Single adapter contract for all Hades Agent data access.
 * Components depend ONLY on this interface — never on raw endpoints.
 * Two implementations exist: MockApi (default) and HttpApi (Laravel).
 */
export interface DevboardApi {
  // Auth
  login(payload: LoginPayload): Promise<User>;
  logout(): Promise<void>;
  me(): Promise<User | null>;

  // Dashboard
  getOverview(): Promise<DashboardOverview>;
  getKanban(projectId?: string): Promise<KanbanBoard>;
  createTask(projectId: string, input: TaskCreateInput): Promise<TaskDetail>;
  updateTask(taskId: string, input: TaskMutationInput | Partial<TaskDetail>): Promise<TaskDetail>;
  getTask(taskId: string): Promise<TaskDetail>;
  clarifyTask(taskId: string): Promise<AssistantRunResponse<TaskClarificationPayload>>;
  resolveAssistantSuggestion(suggestionId: string, status: "accepted" | "rejected"): Promise<AssistantSuggestionResponse<TaskClarificationPayload>>;
  applyAssistantSuggestion(suggestionId: string): Promise<AssistantApplyResponse>;
  uploadTaskAttachment(taskId: string, file: File): Promise<TaskAttachment>;
  deleteTaskAttachment(taskId: string, attachmentId: string): Promise<TaskDetail>;
  getProjectMemory(projectId: string, query?: ProjectMemoryQuery): Promise<ProjectMemoryResponse>;
  createProjectMemory(projectId: string, input: MemoryCreateInput): Promise<ProjectMemoryEntry>;
  updateProjectMemory(projectId: string, memoryId: string, input: MemoryUpdateInput): Promise<ProjectMemoryEntry>;
  deleteProjectMemory(projectId: string, memoryId: string): Promise<void>;
  getProjectWorkspaceBindings(projectId: string): Promise<ProjectWorkspaceBinding[]>;
  getProjectMemoryImports(projectId: string): Promise<ProjectMemoryImportBatch[]>;
  getProjectMemoryImport(projectId: string, batchId: string): Promise<ProjectMemoryImportBatch>;
  createProjectMemoryImport(projectId: string, input: ProjectMemoryImportInput): Promise<ProjectMemoryImportBatch>;
  cancelProjectMemoryImport(projectId: string, batchId: string, message?: string): Promise<ProjectMemoryImportBatch>;
  getAgentChats(projectId: string): Promise<AgentChatResponse>;
  getAgentChat(projectId: string, threadId: string): Promise<AgentChatDetailResponse>;
  createAgentChat(projectId: string, input: AgentChatCreateInput): Promise<AgentChatDetailResponse>;
  sendAgentChatMessage(projectId: string, threadId: string, input: AgentChatMessageInput): Promise<AgentChatDetailResponse>;
  archiveAgentChat(projectId: string, threadId: string, message?: string): Promise<AgentChatDetailResponse>;
  getAgentWork(projectId: string): Promise<AgentWorkResponse>;
  getAgentWorkDetail(projectId: string, workItemId: string): Promise<AgentWorkDetailResponse>;
  createAgentWork(projectId: string, input: AgentWorkCreateInput): Promise<AgentWorkItem>;
  cancelAgentWork(workItemId: string, message?: string): Promise<AgentWorkItem>;
  archiveAgentWork(workItemId: string, message?: string): Promise<AgentWorkItem>;
  getProjects(status?: ProjectStatusFilter): Promise<Project[]>;
  getProject(projectId: string): Promise<ProjectDetail>;
  triageProjectBacklog(projectId: string): Promise<AssistantRunResponse<BacklogTriagePayload>>;
  createProject(payload: ProjectInput): Promise<ProjectDetail>;
  createProjectRepository(projectId: string, input: RepositoryDeclarationInput): Promise<ProjectDetail>;
  updateProject(projectId: string, payload: ProjectInput): Promise<ProjectDetail>;
  archiveProject(projectId: string, payload?: ProjectLifecycleInput): Promise<Project>;
  restoreProject(projectId: string, payload?: ProjectLifecycleInput): Promise<Project>;
  deleteProject(projectId: string, payload?: ProjectLifecycleInput): Promise<Project>;

  // Runs
  getRuns(projectId?: string): Promise<RunSummary[]>;
  getRun(runId: string): Promise<RunDetail>;
  retryImport(runId: string): Promise<RunDetail>;
  reviewRun(runId: string): Promise<RunDetail>;

  // Wiki
  getWiki(projectId?: string): Promise<WikiPageSummary[]>;
  getWikiPage(pageId: string, projectId?: string): Promise<WikiPageDetail>;
  createWikiPage(projectId: string, input: WikiPageWriteInput): Promise<WikiPageDetail>;
  updateWikiPage(pageId: string, input: WikiPageWriteInput, projectId?: string): Promise<WikiPageDetail>;
  getWikiRefreshRequests(projectId: string): Promise<WikiRefreshRequest[]>;
  createWikiRefreshRequest(projectId: string, input: WikiRefreshRequestInput): Promise<WikiRefreshRequest>;

  // Graph
  getGraph(projectId?: string, params?: { snapshotId?: string; runId?: string }): Promise<GraphView>;
  queryProjectGraph(projectId: string, request: DashboardGraphQueryRequest): Promise<DashboardGraphResponse>;

  // Artifacts
  getArtifacts(projectId?: string): Promise<Artifact[]>;
  downloadArtifact(runId: string, artifactId: string): Promise<{ url: string; name: string }>;

  // Quality
  getQualityOverview(): Promise<QualityOverview>;
  getQualityCurrentState(): Promise<QualityCurrentState>;
  getQualityReports(): Promise<QualityReport[]>;
  getRouteInventory(): Promise<RouteInventoryEntry[]>;
  getRouteSmoke(): Promise<{ rows: RouteSmokeRow[]; matrix: AuthMatrixRow[] }>;
  getQualityGate(gate: string): Promise<QualityGate>;
  getQualityRoadmap(): Promise<{ phases: RoadmapPhase[]; checks: SecurityCheck[]; truth: TruthRegistryEntry[] }>;
  runQualityCheck(tool: string, confirm?: boolean): Promise<QualityReport>;

  // Admin
  getPluginTokens(): Promise<PluginToken[]>;
  createPluginToken(name: string, scopes: string[]): Promise<PluginToken>;
  rotatePluginToken(tokenId: string): Promise<PluginToken>;
  revokePluginToken(tokenId: string): Promise<void>;
  getPluginDevices(): Promise<PluginDevice[]>;
  revokeDevice(deviceId: string): Promise<void>;
  getAiAgents(): Promise<AiAgentsSnapshot>;
  updateAiModelProvider(providerKey: string, input: AiModelProviderInput): Promise<AiModelProvider>;
  validateAiModelProvider(providerKey: string): Promise<AiModelProviderValidationResult>;
  createAiModelProfile(input: AiModelProfileCreateInput): Promise<AiModelProfile>;
  updateAiModelProfile(profileKey: string, input: AiModelProfileInput): Promise<AiModelProfile>;
  deleteAiModelProfile(profileKey: string): Promise<void>;
  updateAiAgentProfile(agentKey: string, input: AiAgentProfileInput): Promise<AiAgentProfile>;
  getHadesAdmin(): Promise<HadesAdminSnapshot>;
  createHadesBootstrapToken(input: HadesBootstrapTokenCreateInput): Promise<HadesBootstrapTokenCreateResponse>;
  revokeHadesBootstrapToken(tokenId: string): Promise<void>;
  createHadesJob(input: HadesJobCreateInput): Promise<HadesJobCreateResponse>;
  reviewHadesMemoryProposal(proposalId: string, input: HadesMemoryProposalReviewInput): Promise<HadesMemoryProposalReviewResponse>;

  // Intake
  normalizeIntake(projectId: string, rawText: string): Promise<IntakeNormalizeResponse>;

  // System
  getSystem(): Promise<SystemStatus>;
  setArtifactRetention(days: number, autoPurge: boolean): Promise<SystemStatus>;
  runAuditExport(rangeDays: number): Promise<SystemStatus>;
  getBackupReadiness(): Promise<BackupReadiness>;
  exportBackup(): Promise<BackupExport>;
  validateBackupBundle(file: File): Promise<BackupDryRunReport>;
}

export { API_BASE_URL };

// Mock by default so the app is fully usable before Laravel exists.
export const USE_MOCK = process.env.REACT_APP_USE_MOCK !== "false";

const impl: DevboardApi = USE_MOCK
  ? require("./mockApi").mockApi
  : require("./httpApi").httpApi;

export const api: DevboardApi = impl;
