<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Dashboard\KanbanController;
use App\Http\Controllers\Dashboard\PluginTokenController;
use App\Http\Controllers\Dashboard\ProjectShowController;
use App\Http\Controllers\Dashboard\RunShowController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/kanban');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::get('/kanban', KanbanController::class);
    Route::get('/projects/{project}', ProjectShowController::class);
    Route::get('/runs/{run}', RunShowController::class);
    Route::get('/admin/plugin-tokens', [PluginTokenController::class, 'index']);
    Route::post('/admin/plugin-tokens', [PluginTokenController::class, 'store']);
    Route::post('/admin/plugin-tokens/{token}/rotate', [PluginTokenController::class, 'rotate']);
    Route::delete('/admin/plugin-tokens/{token}', [PluginTokenController::class, 'destroy']);
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy']);
});
