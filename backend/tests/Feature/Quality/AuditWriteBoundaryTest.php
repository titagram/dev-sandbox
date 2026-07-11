<?php

use Illuminate\Support\Str;

it('keeps production audit log writes behind AuditLogger', function () {
    $appPath = base_path('app');
    $violations = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appPath)))
        ->filter(fn (SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'php')
        ->reject(fn (SplFileInfo $file): bool => $file->getRealPath() === base_path('app/Services/AuditLogger.php'))
        ->flatMap(function (SplFileInfo $file): array {
            $contents = file_get_contents($file->getRealPath());

            if ($contents === false || ! Str::contains($contents, "DB::table('audit_logs')->insert")) {
                return [];
            }

            return [str_replace(base_path().'/', '', $file->getRealPath())];
        })
        ->values()
        ->all();

    expect($violations)->toBe([], 'Direct production audit inserts found: '.implode(', ', $violations));
});
