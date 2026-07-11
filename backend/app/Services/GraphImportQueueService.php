<?php

namespace App\Services;

use App\Jobs\ImportGraphToNeo4j;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;

final class GraphImportQueueService
{
    private const DISPATCH_ACTION = 'graph.import_dispatched';

    public function __construct(private readonly Dispatcher $bus) {}

    public function isTransactionalDatabaseQueue(): bool
    {
        $connectionName = (string) config('queue.default');
        $connection = config("queue.connections.{$connectionName}", []);

        if (($connection['driver'] ?? null) !== 'database') {
            return false;
        }

        $queueDatabase = $connection['connection'] ?? null;

        return $queueDatabase === null
            || $queueDatabase === ''
            || $queueDatabase === config('database.default');
    }

    public function needsDispatch(string $scope, string $transferId): bool
    {
        return ! DB::table('audit_logs')
            ->where('action', self::DISPATCH_ACTION)
            ->where('target_type', $this->targetType($scope))
            ->where('target_id', $transferId)
            ->exists();
    }

    public function dispatchIfNeeded(string $scope, string $transferId): void
    {
        if (! $this->needsDispatch($scope, $transferId)) {
            return;
        }

        $this->bus->dispatch(new ImportGraphToNeo4j($scope, $transferId));

        app(AuditLogger::class)->record(
            self::DISPATCH_ACTION,
            $this->targetType($scope),
            $transferId,
            ['scope' => $scope],
            ['type' => 'system'],
        );
    }

    private function targetType(string $scope): string
    {
        return match ($scope) {
            'genesis' => 'genesis_import',
            'delta' => 'delta_sync',
            default => throw new \InvalidArgumentException("Invalid graph import scope [{$scope}]."),
        };
    }
}
