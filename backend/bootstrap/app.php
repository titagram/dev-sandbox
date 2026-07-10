<?php

use App\Console\Commands\BootstrapAdminCommand;
use App\Console\Commands\BackfillAuditChainCommand;
use App\Console\Commands\Hades\ReindexSearchDocumentsCommand;
use App\Console\Commands\Quality\CheckGatesCommand;
use App\Console\Commands\Quality\RouteInventoryCommand;
use App\Console\Commands\Quality\RouteSmokeCommand;
use App\Console\Commands\VerifyAuditChainCommand;
use App\Http\Middleware\AuthenticateHadesAgentToken;
use App\Http\Middleware\AuthenticatePluginToken;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        BootstrapAdminCommand::class,
        BackfillAuditChainCommand::class,
        CheckGatesCommand::class,
        ReindexSearchDocumentsCommand::class,
        RouteInventoryCommand::class,
        RouteSmokeCommand::class,
        VerifyAuditChainCommand::class,
    ])
    ->withSchedule(function (Schedule $schedule): void {
        $schedule
            ->command('devboard:artifacts-retain', ['--days' => (int) config('services.devboard.artifact_retention_days', 90)])
            ->dailyAt('03:15')
            ->withoutOverlapping();
        $schedule
            ->command('hades:search-documents-reindex', ['--limit' => 100000])
            ->dailyAt('03:45')
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->alias([
            'plugin.token' => AuthenticatePluginToken::class,
            'hades.agent' => AuthenticateHadesAgentToken::class,
            'active' => EnsureActiveUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
