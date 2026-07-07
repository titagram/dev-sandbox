<?php

use App\Http\Controllers\Hades\AgentJobResultController as HadesAgentJobResultController;
use App\Http\Controllers\Hades\AgentJobsController as HadesAgentJobsController;
use App\Http\Controllers\Hades\AgentJobStatusController as HadesAgentJobStatusController;
use App\Http\Controllers\Hades\AgentRegisterController as HadesAgentRegisterController;
use App\Http\Controllers\Hades\ArtifactController as HadesArtifactController;
use App\Http\Controllers\Hades\BugEvidenceController as HadesBugEvidenceController;
use App\Http\Controllers\Hades\BugReportController as HadesBugReportController;
use App\Http\Controllers\Hades\CapabilitiesController as HadesCapabilitiesController;
use App\Http\Controllers\Hades\DiagnosisReportController as HadesDiagnosisReportController;
use App\Http\Controllers\Hades\DoctorReportController as HadesDoctorReportController;
use App\Http\Controllers\Hades\HealthController as HadesHealthController;
use App\Http\Controllers\Hades\MemoryImportBundleController as HadesMemoryImportBundleController;
use App\Http\Controllers\Hades\MemoryProposalController as HadesMemoryProposalController;
use App\Http\Controllers\Hades\MemorySearchController as HadesMemorySearchController;
use App\Http\Controllers\Hades\MemorySnapshotController as HadesMemorySnapshotController;
use App\Http\Controllers\Hades\PersephoneController as HadesPersephoneController;
use App\Http\Controllers\Hades\ProjectAwarenessStatusController as HadesProjectAwarenessStatusController;
use App\Http\Controllers\Hades\SourceSliceController as HadesSourceSliceController;
use App\Http\Controllers\Hades\TokenVerifyController as HadesTokenVerifyController;
use App\Http\Controllers\Hades\WorkspaceBindController as HadesWorkspaceBindController;
use App\Http\Controllers\Hades\WorkspaceUnlinkController as HadesWorkspaceUnlinkController;
use App\Http\Controllers\Plugin\AgentWorkItemController;
use App\Http\Controllers\Plugin\AuthCheckController;
use App\Http\Controllers\Plugin\DeltaChunkController;
use App\Http\Controllers\Plugin\DeltaFinalizeController;
use App\Http\Controllers\Plugin\DeltaLocalSnapshotController;
use App\Http\Controllers\Plugin\DeltaStartController;
use App\Http\Controllers\Plugin\GenesisChunkController;
use App\Http\Controllers\Plugin\GenesisFinalizeController;
use App\Http\Controllers\Plugin\GenesisStartController;
use App\Http\Controllers\Plugin\GenesisStatusController;
use App\Http\Controllers\Plugin\ListProjectsController;
use App\Http\Controllers\Plugin\ListRepositoriesController;
use App\Http\Controllers\Plugin\RegisterDeviceController;
use App\Http\Controllers\Plugin\RegisterLocalWorkspaceController;
use App\Http\Controllers\Plugin\RepositoryInstructionsController;
use App\Http\Controllers\Plugin\RepositoryPolicyController;
use App\Http\Controllers\Plugin\RunEventController;
use App\Http\Controllers\Plugin\RunFinishController;
use App\Http\Controllers\Plugin\RunHeartbeatController;
use App\Http\Controllers\Plugin\RunStartController;
use App\Http\Controllers\Plugin\SharedMemoryPackController;
use App\Http\Controllers\Plugin\WikiRevisionController;
use Illuminate\Support\Facades\Route;

Route::prefix('plugin/v1')->group(function () {
    Route::middleware(['throttle:plugin-api-light', 'plugin.token'])->group(function () {
        Route::post('/auth/check', AuthCheckController::class);
        Route::post('/devices/register', RegisterDeviceController::class);
    });

    Route::get('/projects', ListProjectsController::class)
        ->middleware('throttle:plugin-api-light')
        ->middleware('plugin.token:projects.read');

    Route::get('/projects/{project}/repositories', ListRepositoriesController::class)
        ->middleware('throttle:plugin-api-light')
        ->middleware('plugin.token:projects.read,repositories.read');

    Route::get('/projects/{project}/shared-memory-pack', SharedMemoryPackController::class)
        ->middleware('throttle:plugin-api-light')
        ->middleware('plugin.token:projects.read');

    Route::get('/agent-work-items', [AgentWorkItemController::class, 'index'])
        ->middleware('throttle:plugin-api-light')
        ->middleware('plugin.token:projects.read');

    Route::post('/agent-work-items/{workItem}/claim', [AgentWorkItemController::class, 'claim'])
        ->middleware('throttle:plugin-api-light')
        ->middleware('plugin.token:runs.write');

    Route::post('/agent-work-items/{workItem}/heartbeat', [AgentWorkItemController::class, 'heartbeat'])
        ->middleware('throttle:plugin-api-light')
        ->middleware('plugin.token:runs.write');

    Route::post('/agent-work-items/{workItem}/complete', [AgentWorkItemController::class, 'complete'])
        ->middleware('throttle:plugin-api-light')
        ->middleware('plugin.token:runs.write');

    Route::post('/agent-work-items/{workItem}/fail', [AgentWorkItemController::class, 'fail'])
        ->middleware('throttle:plugin-api-light')
        ->middleware('plugin.token:runs.write');

    Route::post('/repositories/{repository}/local-workspaces', RegisterLocalWorkspaceController::class)
        ->middleware('throttle:plugin-api-light')
        ->middleware('plugin.token:repositories.read');

    Route::get('/repositories/{repository}/policy', RepositoryPolicyController::class)
        ->middleware('throttle:plugin-api-light')
        ->middleware('plugin.token:repositories.read,policies.read');

    Route::get('/repositories/{repository}/instructions', RepositoryInstructionsController::class)
        ->middleware('throttle:plugin-api-light')
        ->middleware('plugin.token:repositories.read,policies.read');

    Route::post('/runs', RunStartController::class)
        ->middleware('throttle:plugin-api-light')
        ->middleware('plugin.token:runs.write');

    Route::post('/runs/{run}/heartbeat', RunHeartbeatController::class)
        ->middleware('throttle:plugin-api-light')
        ->middleware('plugin.token:runs.write');

    Route::post('/runs/{run}/events', RunEventController::class)
        ->middleware('throttle:plugin-api-light')
        ->middleware('plugin.token:runs.write');

    Route::post('/runs/{run}/finish', RunFinishController::class)
        ->middleware('throttle:plugin-api-light')
        ->middleware('plugin.token:runs.write');

    Route::post('/runs/{run}/local-snapshots', DeltaLocalSnapshotController::class)
        ->middleware('throttle:plugin-api-light')
        ->middleware('plugin.token:runs.write');

    Route::post('/runs/{run}/delta-syncs', DeltaStartController::class)
        ->middleware('throttle:plugin-api-heavy')
        ->middleware('plugin.token:runs.write,artifacts.write');

    Route::put('/delta-syncs/{deltaSync}/artifacts/{artifact}/chunks/{chunk}', DeltaChunkController::class)
        ->middleware('throttle:plugin-api-heavy')
        ->middleware('plugin.token:artifacts.write');

    Route::post('/delta-syncs/{deltaSync}/finalize', DeltaFinalizeController::class)
        ->middleware('throttle:plugin-api-heavy')
        ->middleware('plugin.token:artifacts.write');

    Route::post('/repositories/{repository}/genesis-imports', GenesisStartController::class)
        ->middleware('throttle:plugin-api-heavy')
        ->middleware('plugin.token:repositories.read,artifacts.write');

    Route::put('/genesis-imports/{genesisImport}/artifacts/{artifact}/chunks/{chunk}', GenesisChunkController::class)
        ->middleware('throttle:plugin-api-heavy')
        ->middleware('plugin.token:artifacts.write');

    Route::post('/genesis-imports/{genesisImport}/finalize', GenesisFinalizeController::class)
        ->middleware('throttle:plugin-api-heavy')
        ->middleware('plugin.token:artifacts.write');

    Route::get('/genesis-imports/{genesisImport}', GenesisStatusController::class)
        ->middleware('throttle:plugin-api-light')
        ->middleware('plugin.token:artifacts.write');

    Route::post('/runs/{run}/wiki/revisions', WikiRevisionController::class)
        ->middleware('throttle:plugin-api-light')
        ->middleware('plugin.token:wiki.write');
});

Route::prefix('hades/v1')->group(function () {
    Route::get('/health', HadesHealthController::class)
        ->middleware('throttle:plugin-api-light');

    Route::post('/token/verify', HadesTokenVerifyController::class)
        ->middleware('throttle:plugin-api-light');

    Route::post('/agents/register', HadesAgentRegisterController::class)
        ->middleware('throttle:plugin-api-light');

    Route::get('/capabilities', HadesCapabilitiesController::class)
        ->middleware('throttle:plugin-api-light')
        ->middleware('hades.agent');

    Route::post('/workspaces/bind', HadesWorkspaceBindController::class)
        ->middleware(['throttle:plugin-api-light', 'hades.agent']);

    Route::post('/workspaces/{workspaceBinding}/unlink', HadesWorkspaceUnlinkController::class)
        ->middleware(['throttle:plugin-api-light', 'hades.agent']);

    Route::get('/memory/snapshot', HadesMemorySnapshotController::class)
        ->middleware(['throttle:plugin-api-light', 'hades.agent']);

    Route::get('/memory/search', HadesMemorySearchController::class)
        ->middleware(['throttle:plugin-api-light', 'hades.agent']);

    Route::post('/memory/proposals', HadesMemoryProposalController::class)
        ->middleware(['throttle:plugin-api-light', 'hades.agent']);

    Route::post('/memory/import-bundles', HadesMemoryImportBundleController::class)
        ->middleware(['throttle:plugin-api-light', 'hades.agent']);

    Route::get('/project-awareness/status', HadesProjectAwarenessStatusController::class)
        ->middleware(['throttle:plugin-api-light', 'hades.agent']);

    Route::post('/bug-reports', [HadesBugReportController::class, 'store'])
        ->middleware(['throttle:plugin-api-light', 'hades.agent']);

    Route::get('/bug-reports/{bugReport}', [HadesBugReportController::class, 'show'])
        ->middleware(['throttle:plugin-api-light', 'hades.agent']);

    Route::post('/bug-evidence', [HadesBugEvidenceController::class, 'store'])
        ->middleware(['throttle:plugin-api-light', 'hades.agent']);

    Route::get('/bug-evidence/search', [HadesBugEvidenceController::class, 'search'])
        ->middleware(['throttle:plugin-api-light', 'hades.agent']);

    Route::post('/source-slices', [HadesSourceSliceController::class, 'store'])
        ->middleware(['throttle:plugin-api-light', 'hades.agent']);

    Route::get('/source-slices', [HadesSourceSliceController::class, 'index'])
        ->middleware(['throttle:plugin-api-light', 'hades.agent']);

    Route::post('/diagnosis-reports', [HadesDiagnosisReportController::class, 'store'])
        ->middleware(['throttle:plugin-api-light', 'hades.agent']);

    Route::post('/diagnosis-reports/{diagnosisReport}/promote', [HadesDiagnosisReportController::class, 'promote'])
        ->middleware(['throttle:plugin-api-light', 'hades.agent']);

    Route::get('/agent/jobs', HadesAgentJobsController::class)
        ->middleware(['throttle:plugin-api-light', 'hades.agent']);

    Route::post('/agent/jobs/{job}/status', HadesAgentJobStatusController::class)
        ->middleware(['throttle:plugin-api-light', 'hades.agent']);

    Route::post('/agent/jobs/{job}/result', HadesAgentJobResultController::class)
        ->middleware(['throttle:plugin-api-light', 'hades.agent']);

    Route::post('/artifacts', HadesArtifactController::class)
        ->middleware(['throttle:plugin-api-heavy', 'hades.agent']);

    Route::post('/doctor/reports', HadesDoctorReportController::class)
        ->middleware(['throttle:plugin-api-light', 'hades.agent']);

    Route::get('/persephone/inbox', [HadesPersephoneController::class, 'inbox'])
        ->middleware(['throttle:plugin-api-light', 'hades.agent']);

    Route::get('/persephone/events', [HadesPersephoneController::class, 'events'])
        ->middleware(['throttle:plugin-api-light', 'hades.agent']);

    Route::post('/persephone/messages', [HadesPersephoneController::class, 'store'])
        ->middleware(['throttle:plugin-api-light', 'hades.agent']);
});
