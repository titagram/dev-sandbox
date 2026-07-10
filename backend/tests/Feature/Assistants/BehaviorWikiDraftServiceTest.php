<?php

use App\Assistants\BehaviorWikiDraftService;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DevBoardSeeder::class);
});

it('generates drafts with symbol_id and summary', function () {
    $service = app(BehaviorWikiDraftService::class);
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');

    $graphContext = graphContextForSymbol('App\\Services\\InvoiceService', 'Class');

    $draft = $service->draftFromGraphContext('App\\Services\\InvoiceService', $graphContext);

    expect($draft)->toHaveKey('symbol_id');
    expect($draft['symbol_id'])->toBe('App\\Services\\InvoiceService');
    expect($draft)->toHaveKey('summary');
    expect($draft['summary'])->toBeString();
    expect(strlen($draft['summary']))->toBeGreaterThan(10);
    expect($draft)->toHaveKey('source_status');
});

it('generates drafts with preconditions and side_effects', function () {
    $service = app(BehaviorWikiDraftService::class);

    $graphContext = graphContextForSymbol('App\\Services\\PaymentGateway', 'Class', [
        'callees' => ['App\\Contracts\\GatewayInterface', 'App\\Events\\PaymentProcessed'],
        'callers' => ['App\\Http\\Controllers\\CheckoutController'],
        'properties' => ['method' => 'charge', 'visibility' => 'public'],
    ]);

    $draft = $service->draftFromGraphContext('App\\Services\\PaymentGateway', $graphContext);

    expect($draft)->toHaveKey('preconditions');
    expect($draft['preconditions'])->toBeArray();
    expect($draft['preconditions'])->not->toBeEmpty();
    expect($draft)->toHaveKey('side_effects');
    expect($draft['side_effects'])->toBeArray();
    expect($draft['side_effects'])->not->toBeEmpty();
});

it('generates drafts with evidence_refs', function () {
    $service = app(BehaviorWikiDraftService::class);
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');
    $repositoryId = DB::table('repositories')->where('project_id', $projectId)->value('id');

    $graphContext = graphContextForSymbol('App\\Models\\Order', 'Class', [
        'relationships' => [
            ['type' => 'IMPLEMENTS', 'to' => 'App\\Contracts\\OrderContract'],
        ],
    ]);

    $evidenceRefs = [
        ['type' => 'artifact', 'id' => 'artifact-graph-1', 'source_status' => 'verified_from_code'],
        ['type' => 'repository', 'id' => $repositoryId, 'source_status' => 'verified_from_code'],
    ];

    $draft = $service->draftFromGraphContext('App\\Models\\Order', $graphContext, $evidenceRefs);

    expect($draft)->toHaveKey('evidence_refs');
    expect($draft['evidence_refs'])->toBeArray();
    expect($draft['evidence_refs'][0]['type'])->toBe('artifact');
    expect($draft['evidence_refs'][0]['id'])->toBe('artifact-graph-1');
    expect($draft['evidence_refs'][1]['type'])->toBe('repository');
});

it('generates drafts with source_status equals needs_verification', function () {
    $service = app(BehaviorWikiDraftService::class);

    $graphContext = graphContextForSymbol('App\\Http\\Controllers\\Api\\UsersController', 'Controller');

    $draft = $service->draftFromGraphContext(
        'App\\Http\\Controllers\\Api\\UsersController',
        $graphContext,
        [['type' => 'run', 'id' => 'run-abc', 'source_status' => 'verified_from_code']]
    );

    expect($draft['source_status'])->toBe('needs_verification');
});

it('does not publish drafts as verified code facts', function () {
    $service = app(BehaviorWikiDraftService::class);
    $projectId = DB::table('projects')->where('slug', 'demo-project')->value('id');

    $beforeWikiPageCount = DB::table('wiki_pages')->count();
    $beforeWikiRevisionCount = DB::table('wiki_revisions')->count();
    $beforeAuditLogCount = DB::table('audit_logs')->count();

    $graphContext = graphContextForSymbol('App\\Services\\ShippingCalculator', 'Class');

    $draft = $service->draftFromGraphContext(
        'App\\Services\\ShippingCalculator',
        $graphContext,
        [['type' => 'graph_snapshot', 'id' => 'snap-1', 'source_status' => 'verified_from_code']]
    );

    expect($draft['source_status'])->toBe('needs_verification');
    expect(DB::table('wiki_pages')->count())->toBe($beforeWikiPageCount);
    expect(DB::table('wiki_revisions')->count())->toBe($beforeWikiRevisionCount);
    expect(DB::table('audit_logs')->count())->toBe($beforeAuditLogCount);

    expect(DB::table('wiki_pages')->where('title', 'like', '%ShippingCalculator%')->exists())->toBeFalse();
    expect(DB::table('wiki_pages')->where('slug', 'like', '%shipping%')->exists())->toBeFalse();
});

/**
 * @param  list<string>  $callees
 * @param  list<string>  $callers
 * @param  array<string, mixed>  $properties
 * @param  list<array<string, mixed>>  $relationships
 * @return array<string, mixed>
 */
function graphContextForSymbol(string $symbolId, string $label, array $overrides = []): array
{
    return [
        'symbol_id' => $symbolId,
        'labels' => $overrides['labels'] ?? [$label],
        'name' => $overrides['name'] ?? class_basename($symbolId),
        'path' => $overrides['path'] ?? str_replace('\\', '/', $symbolId).'.php',
        'properties' => $overrides['properties'] ?? [],
        'callees' => $overrides['callees'] ?? [],
        'callers' => $overrides['callers'] ?? [],
        'relationships' => $overrides['relationships'] ?? [],
    ];
}
