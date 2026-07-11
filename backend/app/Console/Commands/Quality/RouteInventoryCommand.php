<?php

namespace App\Console\Commands\Quality;

use App\Quality\Route\RouteInventoryBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;

final class RouteInventoryCommand extends Command
{
    protected $signature = 'quality:route-inventory
        {--format=json : Output format, json or md}
        {--output= : Optional output path}';

    protected $description = 'Write a read-only Laravel route inventory quality report';

    /**
     * @throws JsonException
     */
    public function handle(RouteInventoryBuilder $builder): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['json', 'md'], true)) {
            $this->error('Invalid --format value. Use json or md.');

            return self::FAILURE;
        }

        $inventory = $builder->build();
        $path = $this->option('output') ?: base_path("var/quality/reports/route-inventory.{$format}");

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $format === 'json' ? $this->json($inventory) : $this->markdown($inventory));

        $this->info("Wrote route inventory to {$path}.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $inventory
     *
     * @throws JsonException
     */
    private function json(array $inventory): string
    {
        return json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    }

    /**
     * @param  array<string, mixed>  $inventory
     */
    private function markdown(array $inventory): string
    {
        $lines = [
            '# Route Inventory',
            '',
            "- Status: {$inventory['status']}",
            "- Generated at: {$inventory['generated_at']}",
            "- Total routes: {$inventory['summary']['total']}",
            "- Warnings: {$inventory['summary']['warnings']}",
            '',
            '| Method | URI | Classification | Configured | Warnings |',
            '| --- | --- | --- | --- | --- |',
        ];

        foreach ($inventory['routes'] as $route) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s |',
                implode(',', $route['methods']),
                $route['uri'],
                $route['classification'],
                $route['configured'] ? 'yes' : 'no',
                implode(',', $route['warnings']),
            );
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
