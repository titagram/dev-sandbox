<?php

it('keeps production project logbook writes behind ProjectLogbookService', function () {
    $service = base_path('app/Services/ProjectLogbookService.php');
    $violations = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'))))
        ->filter(fn (SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'php')
        ->reject(fn (SplFileInfo $file): bool => $file->getRealPath() === $service)
        ->flatMap(function (SplFileInfo $file): array {
            $contents = file_get_contents($file->getRealPath());
            if ($contents === false) {
                return [];
            }
            if (! str_contains($contents, "DB::table('project_logbook_entries')->insert")
                && ! str_contains($contents, 'ProjectLogbookEntry::query()->create')
                && ! str_contains($contents, 'ProjectLogbookEntry::create')) {
                return [];
            }

            return [str_replace(base_path().'/', '', $file->getRealPath())];
        })
        ->values()
        ->all();

    expect($violations)->toBe([], 'Direct production project logbook inserts found: '.implode(', ', $violations));
});
