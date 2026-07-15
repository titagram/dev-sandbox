<?php

use App\Services\Graph\DashboardGraphSearchTerms;

it('normalizes route names uris and technical prefixes into the same safe tokens', function (string $query): void {
    $result = (new DashboardGraphSearchTerms)->forQuery($query);

    expect($result['tokens'])->toContain('soggetti')
        ->toContain('attivi')
        ->and($result['lucene'])->toContain('public_search_terms:soggetti*')
        ->toContain('public_search_terms:attivi*');
})->with([
    'slug' => 'soggetti-attivi',
    'uri' => '/generale/soggetti-attivi/',
    'route name' => 'route:contact_flock_roles_soggetti_attivi',
]);

it('derives bounded route and test aliases without exposing source paths', function (): void {
    $terms = new DashboardGraphSearchTerms;
    $route = $terms->forNode([
        'kind' => 'route',
        'name' => 'contact_flock_roles_worker',
        'uri' => '/generale/soggetti-attivi/',
        'handler' => 'WorkerController@index',
        'source_path' => 'src/Controller/WorkerController.php',
    ], 'route', true);
    $test = $terms->forNode([
        'kind' => 'test',
        'name' => 'AdminControllerBulkDeleteBehaviorTest',
        'path' => 'tests/AdminControllerBulkDeleteBehaviorTest.php',
    ], 'test', false);

    expect($route)->toMatchArray([
        'public_search_name' => 'contact_flock_roles_worker',
        'public_search_path' => '/generale/soggetti-attivi/',
    ])->and($route['public_search_terms'])
        ->toContain('soggetti')
        ->toContain('attivi')
        ->toContain('workercontroller')
        ->not->toContain('src/controller')
        ->and($test['public_search_name'])->toBe('AdminControllerBulkDeleteBehaviorTest')
        ->and($test['public_search_path'])->toBeNull()
        ->and($test['public_search_terms'])->toContain('admincontrollerbulkdeletebehaviortest')
        ->toContain('bulk');
});

it('builds lucene only from bounded alphanumeric tokens', function (): void {
    $result = (new DashboardGraphSearchTerms)->forQuery("Invoice' +(Service) && || ../secret");

    expect($result['tokens'])->toBe(['invoice', 'service', 'secret'])
        ->and($result['lucene'])->toBe(
            'public_search_terms:invoice* AND public_search_terms:service* AND public_search_terms:secret*',
        );
});

it('derives safe public source evidence without leaking absolute paths', function (): void {
    $terms = new DashboardGraphSearchTerms;

    $safe = $terms->forNode([
        'name' => 'AdminControllerBulkDeleteBehavior',
        'file_path' => 'app/Http/Controllers/AdminController.php',
        'line_start' => 42,
        'line_end' => 88,
        'namespace' => 'App\\Http\\Controllers',
    ], 'class', false);
    $unsafe = $terms->forNode([
        'name' => 'Secret',
        'file_path' => '/srv/private/app/Secret.php',
        'line_start' => 1,
        'namespace' => '../private',
    ], 'class', false);

    expect($safe)->toMatchArray([
        'public_source_file' => 'app/Http/Controllers/AdminController.php',
        'public_line_start' => 42,
        'public_line_end' => 88,
        'public_namespace' => 'App\\Http\\Controllers',
    ])->and($unsafe)
        ->toMatchArray([
            'public_source_file' => null,
            'public_line_start' => 1,
            'public_line_end' => null,
            'public_namespace' => null,
        ]);
});
