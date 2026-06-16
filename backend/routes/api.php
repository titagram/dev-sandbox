<?php

use App\Http\Controllers\Plugin\AuthCheckController;
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
use Illuminate\Support\Facades\Route;

Route::prefix('plugin/v1')->group(function () {
    Route::middleware('plugin.token')->group(function () {
        Route::post('/auth/check', AuthCheckController::class);
        Route::post('/devices/register', RegisterDeviceController::class);
    });

    Route::get('/projects', ListProjectsController::class)
        ->middleware('plugin.token:projects.read');

    Route::get('/projects/{project}/repositories', ListRepositoriesController::class)
        ->middleware('plugin.token:projects.read,repositories.read');

    Route::post('/repositories/{repository}/local-workspaces', RegisterLocalWorkspaceController::class)
        ->middleware('plugin.token:repositories.read');

    Route::get('/repositories/{repository}/policy', RepositoryPolicyController::class)
        ->middleware('plugin.token:repositories.read,policies.read');

    Route::get('/repositories/{repository}/instructions', RepositoryInstructionsController::class)
        ->middleware('plugin.token:repositories.read,policies.read');

    Route::post('/runs', RunStartController::class)
        ->middleware('plugin.token:runs.write');

    Route::post('/runs/{run}/heartbeat', RunHeartbeatController::class)
        ->middleware('plugin.token:runs.write');

    Route::post('/runs/{run}/events', RunEventController::class)
        ->middleware('plugin.token:runs.write');

    Route::post('/runs/{run}/finish', RunFinishController::class)
        ->middleware('plugin.token:runs.write');
});
