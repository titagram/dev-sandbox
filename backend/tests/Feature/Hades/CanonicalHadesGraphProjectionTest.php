<?php

use App\Jobs\GenerateSearchDocumentEmbedding;
use App\Jobs\ProjectCanonicalGraphToNeo4j;
use App\Models\User;
use App\Services\Graph\CanonicalGraphNormalizer;
use App\Services\Graph\CanonicalGraphProjectionService;
use App\Services\Graph\CanonicalGraphRepository;
use App\Services\Graph\Neo4jCanonicalGraphProjector;
use App\Services\Neo4j\FakeNeo4jClient;
use App\Services\Neo4j\Neo4jClient;
use App\Services\Neo4jClientFactory;
use App\Services\Neo4jRebuildService;
use Database\Seeders\DevBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laudis\Neo4j\Types\CypherMap;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DevBoardSeeder::class));

it('queues one projection for a new canonical graph and none for its duplicate', function () {
    Bus::fake();
    [$agent, $bindingId] = canonicalProjectionAgent();
    $payload = canonicalProjectionUpload($agent, $bindingId);

    $first = $this->postJson('/api/hades/v1/artifacts', $payload, ['Authorization' => 'Bearer '.$agent['token']])->assertCreated();
    $projection = DB::table('canonical_graph_projections')->first();

    expect($projection)->not->toBeNull()->and($projection->artifact_id)->toBe($first->json('artifact.id'));
    Bus::assertDispatchedTimes(ProjectCanonicalGraphToNeo4j::class, 1);
    Bus::assertDispatched(fn (ProjectCanonicalGraphToNeo4j $job) => $job->projectionId === $projection->id);

    $this->postJson('/api/hades/v1/artifacts', $payload, ['Authorization' => 'Bearer '.$agent['token']])
        ->assertOk()->assertJsonPath('deduplicated', true);
    Bus::assertDispatchedTimes(ProjectCanonicalGraphToNeo4j::class, 1);
});

it('projects batches with project scope and graph version', function () {
    Bus::fake();
    [$agent, $bindingId] = canonicalProjectionAgent();
    $response = $this->postJson('/api/hades/v1/artifacts', canonicalProjectionUpload($agent, $bindingId), ['Authorization' => 'Bearer '.$agent['token']])->assertCreated();
    $projection = DB::table('canonical_graph_projections')->first();
    $graph = app(CanonicalGraphRepository::class)->findByIdentity($agent['project_id'], 'workspace_binding', $bindingId, 'hades_agent_artifact', $response->json('artifact.id'));
    $client = new FakeNeo4jClient;

    $counts = app(Neo4jCanonicalGraphProjector::class)->project($graph, $projection, $client);

    expect($counts)->toBe(['nodes' => 2, 'relationships' => 1]);
    foreach (['UNWIND $nodes', 'UNWIND $relationships'] as $needle) {
        $command = collect($client->commands)->first(fn (array $item) => str_contains($item['cypher'], $needle));
        expect($command['params'])->toMatchArray(['project_id' => $agent['project_id'], 'source_scope_type' => 'workspace_binding', 'source_scope_id' => $bindingId, 'graph_version' => $projection->graph_version]);
    }
});

it('sends canonical property bags as Bolt maps without changing their scalar values', function () {
    $graph = [
        'nodes' => [
            ['id' => 'file:empty.php', 'labels' => ['File'], 'properties' => []],
            ['id' => 'class:Typed', 'labels' => ['Class'], 'properties' => [
                'name' => 'Typed',
                'line' => 17,
                'score' => 0.75,
                'indexed' => true,
                'nullable' => null,
                'tags' => ['domain', 'public'],
            ]],
        ],
        'relationships' => [
            ['id' => 'declares:empty', 'type' => 'DECLARES', 'source_id' => 'file:empty.php', 'target_id' => 'class:Typed', 'properties' => []],
            ['id' => 'declares:typed', 'type' => 'DECLARES', 'source_id' => 'file:empty.php', 'target_id' => 'class:Typed', 'properties' => [
                'line' => 17,
                'inferred' => false,
            ]],
        ],
    ];
    $projection = (object) [
        'graph_version' => 'graph-test',
        'project_id' => 'project-test',
        'source_scope_type' => 'workspace_binding',
        'source_scope_id' => 'scope-test',
        'snapshot_id' => null,
    ];
    $client = new class implements Neo4jClient
    {
        public array $commands = [];

        public function run(string $cypher, array $parameters = []): mixed
        {
            $this->commands[] = ['cypher' => $cypher, 'params' => $parameters];

            return str_contains($cypher, 'RETURN nodes')
                ? [['nodes' => 2, 'relationships' => 2]]
                : [];
        }
    };

    app(Neo4jCanonicalGraphProjector::class)->project($graph, $projection, $client);

    $nodes = collect($client->commands)->first(fn (array $command) => str_contains($command['cypher'], 'UNWIND $nodes'))['params']['nodes'];
    $relationships = collect($client->commands)->first(fn (array $command) => str_contains($command['cypher'], 'UNWIND $relationships'))['params']['relationships'];

    expect($nodes[0]['properties'])->toBeInstanceOf(CypherMap::class)
        ->and($relationships[0]['properties'])->toBeInstanceOf(CypherMap::class)
        ->and($nodes[1]['properties'])->toBe([
            'name' => 'Typed',
            'line' => 17,
            'score' => 0.75,
            'indexed' => true,
            'nullable' => null,
            'tags' => ['domain', 'public'],
        ])
        ->and($relationships[1]['properties'])->toBe(['line' => 17, 'inferred' => false]);
});

it('rejects list-shaped property bags before issuing projection commands', function (string $collection) {
    $graph = [
        'nodes' => [
            ['id' => 'file:a.php', 'labels' => ['File'], 'properties' => []],
            ['id' => 'class:A', 'labels' => ['Class'], 'properties' => []],
        ],
        'relationships' => [
            ['id' => 'declares:a', 'type' => 'DECLARES', 'source_id' => 'file:a.php', 'target_id' => 'class:A', 'properties' => []],
        ],
    ];
    $graph[$collection][0]['properties'] = ['not', 'a', 'map'];
    $projection = (object) [
        'graph_version' => 'graph-invalid',
        'project_id' => 'project-test',
        'source_scope_type' => 'workspace_binding',
        'source_scope_id' => 'scope-test',
        'snapshot_id' => null,
    ];
    $client = new FakeNeo4jClient;

    expect(fn () => app(Neo4jCanonicalGraphProjector::class)->project($graph, $projection, $client))
        ->toThrow(RuntimeException::class, 'property bag must be a map')
        ->and($client->commands)->toBe([]);
})->with([
    'node properties' => 'nodes',
    'relationship properties' => 'relationships',
]);

it('refuses to switch current when verification is empty or mismatched', function (array $verification) {
    Bus::fake();
    [$agent, $bindingId] = canonicalProjectionAgent();
    $response = $this->postJson('/api/hades/v1/artifacts', canonicalProjectionUpload($agent, $bindingId), ['Authorization' => 'Bearer '.$agent['token']])->assertCreated();
    $projection = DB::table('canonical_graph_projections')->first();
    $graph = app(CanonicalGraphRepository::class)->findByIdentity($agent['project_id'], 'workspace_binding', $bindingId, 'hades_agent_artifact', $response->json('artifact.id'));
    $client = new class($verification) implements Neo4jClient
    {
        public array $commands = [];

        public function __construct(private array $verification) {}

        public function run(string $cypher, array $parameters = []): mixed
        {
            $this->commands[] = ['cypher' => $cypher, 'params' => $parameters];

            return str_contains($cypher, 'RETURN nodes') ? $this->verification : [];
        }
    };

    expect(fn () => app(Neo4jCanonicalGraphProjector::class)->project($graph, $projection, $client))->toThrow(RuntimeException::class);
    expect(collect($client->commands)->contains(fn (array $command) => str_contains($command['cypher'], 'candidate.current = true')))->toBeFalse();
})->with(['empty' => [[]], 'mismatch' => [[['nodes' => 1, 'relationships' => 1]]], 'non numeric' => [[['nodes' => 'two', 'relationships' => 1]]]]);

it('reasserts reserved identity properties after payload properties', function () {
    Bus::fake();
    [$agent, $bindingId] = canonicalProjectionAgent();
    $payload = canonicalProjectionUpload($agent, $bindingId);
    $payload['artifact']['nodes'][0]['properties'] = ['graph_version' => 'evil', 'external_id' => 'evil'];
    $payload['artifact']['relationships'][0]['properties'] = ['graph_version' => 'evil', 'external_id' => 'evil'];
    $response = $this->postJson('/api/hades/v1/artifacts', $payload, ['Authorization' => 'Bearer '.$agent['token']])->assertCreated();
    $projection = DB::table('canonical_graph_projections')->first();
    $graph = app(CanonicalGraphRepository::class)->findByIdentity($agent['project_id'], 'workspace_binding', $bindingId, 'hades_agent_artifact', $response->json('artifact.id'));
    $client = new class implements Neo4jClient
    {
        public array $commands = [];

        public function run(string $cypher, array $parameters = []): mixed
        {
            $this->commands[] = ['cypher' => $cypher, 'params' => $parameters];

            return str_contains($cypher, 'RETURN nodes') ? [['nodes' => 2, 'relationships' => 1]] : [];
        }
    };
    app(Neo4jCanonicalGraphProjector::class)->project($graph, $projection, $client);
    $node = collect($client->commands)->first(fn ($command) => str_contains($command['cypher'], 'UNWIND $nodes'));
    $edge = collect($client->commands)->first(fn ($command) => str_contains($command['cypher'], 'UNWIND $relationships'));
    expect($node['cypher'])->toContain('n.graph_version = $graph_version')->toContain('n.external_id = node.id')
        ->and($edge['cypher'])->toContain('r.graph_version = $graph_version')->toContain('r.external_id = relationship.id');
});

it('does not clear an existing current candidate before verification on retry', function () {
    Bus::fake();
    [$agent, $bindingId] = canonicalProjectionAgent();
    $response = $this->postJson('/api/hades/v1/artifacts', canonicalProjectionUpload($agent, $bindingId), ['Authorization' => 'Bearer '.$agent['token']])->assertCreated();
    $projection = DB::table('canonical_graph_projections')->first();
    $graph = app(CanonicalGraphRepository::class)->findByIdentity($agent['project_id'], 'workspace_binding', $bindingId, 'hades_agent_artifact', $response->json('artifact.id'));
    $client = new class implements Neo4jClient
    {
        public array $commands = [];

        public bool $current = true;

        public function run(string $cypher, array $parameters = []): mixed
        {
            $this->commands[] = ['cypher' => $cypher, 'params' => $parameters];
            if (str_contains($cypher, 'v.current = false') && ! str_contains($cypher, 'ON CREATE')) {
                $this->current = false;
            } if (str_contains($cypher, 'UNWIND $nodes')) {
                throw new RuntimeException('write failed');
            }

            return [];
        }
    };
    expect(fn () => app(Neo4jCanonicalGraphProjector::class)->project($graph, $projection, $client))->toThrow(RuntimeException::class);
    $candidate = collect($client->commands)->first(fn ($command) => str_contains($command['cypher'], 'MERGE (v:CanonicalGraphVersion'));
    expect($candidate['cypher'])->toContain('ON CREATE SET v.current = false')->not->toContain('v.current = false,')
        ->and($client->current)->toBeTrue();
});

it('job projects the exact older artifact identity and marks it ready', function () {
    Bus::fake();
    [$agent, $bindingId] = canonicalProjectionAgent();
    $older = canonicalProjectionUpload($agent, $bindingId);
    $first = $this->postJson('/api/hades/v1/artifacts', $older, ['Authorization' => 'Bearer '.$agent['token']])->assertCreated();
    $olderProjection = DB::table('canonical_graph_projections')->where('artifact_id', $first->json('artifact.id'))->first();
    $newer = canonicalProjectionUpload($agent, $bindingId);
    $newer['artifact']['nodes'][0]['id'] = 'file:newer.php';
    $newer['artifact']['relationships'][0]['source_id'] = 'file:newer.php';
    $this->postJson('/api/hades/v1/artifacts', $newer, ['Authorization' => 'Bearer '.$agent['token']])->assertCreated();
    $client = new FakeNeo4jClient;

    runCanonicalProjectionJob($olderProjection->id, $client);

    $node = collect($client->commands)->first(fn ($command) => str_contains($command['cypher'], 'UNWIND $nodes'));
    expect(collect($node['params']['nodes'])->pluck('id')->all())->toContain('file:a.php')->not->toContain('file:newer.php');
    expect(DB::table('canonical_graph_projections')->where('id', $olderProjection->id)->value('status'))->toBe('ready');
});

it('job rejects checksum changes, returns to queued, and rethrows for retry', function () {
    Bus::fake();
    [$agent, $bindingId] = canonicalProjectionAgent();
    $response = $this->postJson('/api/hades/v1/artifacts', canonicalProjectionUpload($agent, $bindingId), ['Authorization' => 'Bearer '.$agent['token']])->assertCreated();
    $projection = DB::table('canonical_graph_projections')->where('artifact_id', $response->json('artifact.id'))->first();
    DB::table('canonical_graph_projections')->where('id', $projection->id)->update(['checksum' => str_repeat('0', 64)]);

    expect(fn () => runCanonicalProjectionJob($projection->id, new FakeNeo4jClient))->toThrow(RuntimeException::class, 'artifact_changed');
    expect(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe('queued')
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('error_code'))->toBe('artifact_changed');
});

it('job keeps an exact missing artifact queued with a bounded code and rethrows for retry', function () {
    Bus::fake();
    [$agent, $bindingId] = canonicalProjectionAgent();
    $response = $this->postJson('/api/hades/v1/artifacts', canonicalProjectionUpload($agent, $bindingId), ['Authorization' => 'Bearer '.$agent['token']])->assertCreated();
    $projection = DB::table('canonical_graph_projections')->where('artifact_id', $response->json('artifact.id'))->first();
    DB::table('hades_agent_artifacts')->where('id', $projection->artifact_id)->delete();

    expect(fn () => runCanonicalProjectionJob($projection->id, new FakeNeo4jClient))
        ->toThrow(RuntimeException::class, 'artifact_missing');
    expect(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe('queued')
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('error_code'))->toBe('artifact_missing');
});

it('rejects malformed explicit canonical artifacts without upload side effects', function () {
    Bus::fake();
    [$agent, $bindingId] = canonicalProjectionAgent();
    $payload = canonicalProjectionUpload($agent, $bindingId);
    unset($payload['artifact']['nodes'][0]['id']);
    $payload['artifact']['nodes'][0] += [
        'name' => 'App\\Http\\Controllers\\BookingController',
        'path' => 'app/Http/Controllers/BookingController.php',
    ];
    $this->postJson('/api/hades/v1/artifacts', $payload, ['Authorization' => 'Bearer '.$agent['token']])
        ->assertUnprocessable()
        ->assertExactJson(['error' => [
            'code' => 'invalid_graph_artifact',
            'message' => 'Graph artifact payload is invalid.',
        ]]);

    expect(DB::table('hades_agent_artifacts')->count())->toBe(0)
        ->and(DB::table('hades_search_documents')->count())->toBe(0)
        ->and(DB::table('hades_source_slice_candidates')->count())->toBe(0)
        ->and(DB::table('canonical_graph_projections')->count())->toBe(0);
    Bus::assertNotDispatched(ProjectCanonicalGraphToNeo4j::class);
    Bus::assertNotDispatched(GenerateSearchDocumentEmbedding::class);
});

it('rejects explicit canonical node property lists before every upload side effect on every retry', function () {
    Bus::fake();
    [$agent, $bindingId] = canonicalProjectionAgent();
    $payload = canonicalProjectionUpload($agent, $bindingId);
    $payload['artifact']['nodes'][0]['properties'] = ['not', 'a', 'map'];
    $payload['artifact']['source_slice_candidates'] = [[
        'candidate_key' => 'invalid-properties',
        'path' => 'app/Invalid.php',
        'start_line' => 1,
        'end_line' => 2,
    ]];

    foreach (range(1, 2) as $attempt) {
        $this->postJson('/api/hades/v1/artifacts', $payload, ['Authorization' => 'Bearer '.$agent['token']])
            ->assertUnprocessable()
            ->assertExactJson(['error' => [
                'code' => 'invalid_graph_artifact',
                'message' => 'Graph artifact payload is invalid.',
            ]]);
    }

    expect(DB::table('hades_agent_artifacts')->count())->toBe(0)
        ->and(DB::table('hades_search_documents')->count())->toBe(0)
        ->and(DB::table('hades_source_slice_candidates')->count())->toBe(0)
        ->and(DB::table('hades_agent_jobs')->count())->toBe(0)
        ->and(DB::table('canonical_graph_projections')->count())->toBe(0);
    Bus::assertNotDispatched(ProjectCanonicalGraphToNeo4j::class);
    Bus::assertNotDispatched(GenerateSearchDocumentEmbedding::class);
});

it('stops an integrated normalization and projection path before Neo4j client commands', function () {
    $payload = canonicalProjectionUpload(['project_id' => 'project-test'], 'scope-test')['artifact'];
    $payload['nodes'][0]['properties'] = [0 => 'list-entry', 'name' => 'mixed-map'];
    $client = new FakeNeo4jClient;
    $projection = (object) [
        'graph_version' => 'graph-invalid-normalizer',
        'project_id' => 'project-test',
        'source_scope_type' => 'workspace_binding',
        'source_scope_id' => 'scope-test',
        'snapshot_id' => null,
    ];

    expect(function () use ($payload, $projection, $client): void {
        $graph = app(CanonicalGraphNormalizer::class)->normalize($payload, [
            'project_id' => 'project-test',
            'source_scope_type' => 'workspace_binding',
            'source_scope_id' => 'scope-test',
        ]);
        app(Neo4jCanonicalGraphProjector::class)->project($graph, $projection, $client);
    })->toThrow(InvalidArgumentException::class, 'Canonical graph node properties must be a map.')
        ->and($client->commands)->toBe([]);
});

it('keeps artifact upload runtime responses aligned with the documented 422 contracts', function () {
    Bus::fake();
    [$agent, $bindingId] = canonicalProjectionAgent();
    $headers = ['Authorization' => 'Bearer '.$agent['token']];

    $missingProject = canonicalProjectionUpload($agent, $bindingId);
    unset($missingProject['project_id']);
    $this->postJson('/api/hades/v1/artifacts', $missingProject, $headers)
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'The project id field is required.',
            'errors' => ['project_id' => ['The project id field is required.']],
        ]);

    $malformed = canonicalProjectionUpload($agent, $bindingId);
    $malformed['artifact']['graph_contract']['extractor']['mode'] = 'full';
    $this->postJson('/api/hades/v1/artifacts', $malformed, $headers)
        ->assertUnprocessable()
        ->assertExactJson(['error' => [
            'code' => 'invalid_graph_contract',
            'message' => 'Graph artifact contract is invalid.',
        ]]);

    $legacy = [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $bindingId,
        'schema' => 'hades.php_graph.v1',
        'artifact' => [
            'schema' => 'hades.php_graph.v1',
            'head_commit' => str_repeat('a', 40),
            'routes' => [['name' => 'orders.index', 'handler' => 'OrderController@index']],
            'symbols' => [['name' => 'OrderController@index', 'path' => 'app/Http/Controllers/OrderController.php', 'line' => 12]],
            'edges' => [['kind' => 'handles', 'from' => 'route:orders.index', 'to' => 'OrderController@index']],
        ],
    ];
    $this->postJson('/api/hades/v1/artifacts', $legacy, $headers)->assertCreated();
    $this->postJson('/api/hades/v1/artifacts', canonicalProjectionUpload($agent, $bindingId), $headers)->assertCreated();

    $spec = json_decode(file_get_contents(base_path('docs/hades/openapi-hades-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    $upload422 = $spec['paths']['/api/hades/v1/artifacts']['post']['responses']['422']['content']['application/json'];

    expect($upload422['schema']['oneOf'])->toBe([
        ['$ref' => '#/components/schemas/ArtifactUploadErrorResponse'],
        ['$ref' => '#/components/schemas/LaravelValidationErrorResponse'],
    ])->and($upload422['examples']['missingRequiredField']['value'])->toBe([
        'message' => 'The project id field is required.',
        'errors' => ['project_id' => ['The project id field is required.']],
    ])->and($upload422['examples']['malformedGraphContract']['value'])->toBe([
        'error' => [
            'code' => 'invalid_graph_contract',
            'message' => 'Graph artifact contract is invalid.',
        ],
    ])->and($spec['components']['schemas']['LaravelValidationErrorResponse'])->toMatchArray([
        'type' => 'object',
        'required' => ['message', 'errors'],
        'additionalProperties' => false,
        'properties' => [
            'message' => ['type' => 'string'],
            'errors' => [
                'type' => 'object',
                'minProperties' => 1,
                'additionalProperties' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => ['type' => 'string'],
                ],
            ],
        ],
    ]);
});

it('returns the artifact error envelope for schema and compressed upload mismatches', function (string $failure, string $code, string $message) {
    Bus::fake();
    [$agent, $bindingId] = canonicalProjectionAgent();
    $headers = ['Authorization' => 'Bearer '.$agent['token']];

    if ($failure === 'schema') {
        $payload = canonicalProjectionUpload($agent, $bindingId);
        $payload['artifact']['schema'] = 'hades.php_graph.v1';
    } else {
        $json = json_encode(['schema' => 'hades.code_graph.v1'], JSON_THROW_ON_ERROR);
        $compressed = gzencode($json);
        $payload = [
            'project_id' => $agent['project_id'],
            'workspace_binding_id' => $bindingId,
            'schema' => 'hades.code_graph.v1',
            'artifact_compressed' => base64_encode($compressed),
            'artifact_encoding' => 'gzip+base64',
            'artifact_uncompressed_sha256' => hash('sha256', $json),
            'artifact_uncompressed_bytes' => strlen($json),
            'artifact_compressed_bytes' => strlen($compressed) + 1,
        ];
    }

    $this->postJson('/api/hades/v1/artifacts', $payload, $headers)
        ->assertUnprocessable()
        ->assertExactJson(['error' => ['code' => $code, 'message' => $message]]);
})->with([
    'schema mismatch' => ['schema', 'artifact_schema_mismatch', 'Artifact payload schema does not match the requested schema.'],
    'compressed size mismatch' => ['compressed', 'artifact_compressed_size_mismatch', 'Compressed artifact byte count does not match.'],
]);

it('documents every controller artifact 422 code while keeping 413 codes separate', function () {
    $spec = json_decode(file_get_contents(base_path('docs/hades/openapi-hades-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    $artifactError = $spec['components']['schemas']['ArtifactUploadErrorResponse'];
    $documented = $artifactError['properties']['error']['properties']['code']['enum'];
    sort($documented);
    expect($spec['components']['schemas'])->toHaveKey('ArtifactUploadTooLargeErrorResponse')
        ->and($spec['paths']['/api/hades/v1/artifacts']['post']['responses'])->toHaveKey('413');
    $tooLargeError = $spec['components']['schemas']['ArtifactUploadTooLargeErrorResponse'];
    $documentedTooLarge = $tooLargeError['properties']['error']['properties']['code']['enum'];
    sort($documentedTooLarge);

    $source = file_get_contents(app_path('Http/Controllers/Hades/ArtifactController.php'));
    preg_match_all(
        "/\\\$this->error\\(\\s*'([^']+)'\\s*,\\s*'(?:\\\\\\\\.|[^'])*'\\s*,\\s*Response::HTTP_(UNPROCESSABLE_ENTITY|REQUEST_ENTITY_TOO_LARGE)\\s*\\)/s",
        $source,
        $matches,
        PREG_SET_ORDER,
    );
    $codesByStatus = ['UNPROCESSABLE_ENTITY' => [], 'REQUEST_ENTITY_TOO_LARGE' => []];
    foreach ($matches as $match) {
        $codesByStatus[$match[2]][] = $match[1];
    }
    $unprocessable = array_values(array_unique($codesByStatus['UNPROCESSABLE_ENTITY']));
    $tooLarge = array_values(array_unique($codesByStatus['REQUEST_ENTITY_TOO_LARGE']));
    sort($unprocessable);
    sort($tooLarge);

    expect($documented)->toBe($unprocessable)
        ->and($documentedTooLarge)->toBe($tooLarge)
        ->and(array_intersect($documented, $documentedTooLarge))->toBe([])
        ->and($artifactError['additionalProperties'])->toBeFalse()
        ->and($artifactError['properties']['error']['additionalProperties'])->toBeFalse()
        ->and($tooLargeError['additionalProperties'])->toBeFalse()
        ->and($tooLargeError['properties']['error']['additionalProperties'])->toBeFalse()
        ->and($spec['paths']['/api/hades/v1/artifacts']['post']['responses']['413']['content']['application/json']['schema']['$ref'])
        ->toBe('#/components/schemas/ArtifactUploadTooLargeErrorResponse')
        ->and($spec['paths']['/api/hades/v1/artifacts']['post']['responses']['413']['content']['application/json']['example'])
        ->toBe(['error' => [
            'code' => 'artifact_uncompressed_too_large',
            'message' => 'Artifact exceeds the uncompressed byte limit.',
        ]]);
});

it('rejects malformed explicit graph contracts before dedupe and every upload side effect', function () {
    Bus::fake();
    [$agent, $bindingId] = canonicalProjectionAgent();
    $payload = canonicalProjectionUpload($agent, $bindingId);
    $payload['artifact']['graph_contract']['extractor']['mode'] = 'full';
    $payload['artifact']['source_slice_candidates'] = [[
        'candidate_key' => 'malformed-contract',
        'path' => 'app/Invalid.php',
        'start_line' => 1,
        'end_line' => 2,
    ]];
    $payload['sha256'] = str_repeat('b', 64);

    foreach (range(1, 2) as $attempt) {
        $this->postJson('/api/hades/v1/artifacts', $payload, ['Authorization' => 'Bearer '.$agent['token']])
            ->assertUnprocessable()
            ->assertExactJson(['error' => [
                'code' => 'invalid_graph_contract',
                'message' => 'Graph artifact contract is invalid.',
            ]]);
    }

    expect(DB::table('hades_agent_artifacts')->count())->toBe(0)
        ->and(DB::table('hades_search_documents')->count())->toBe(0)
        ->and(DB::table('hades_source_slice_candidates')->count())->toBe(0)
        ->and(DB::table('hades_agent_jobs')->where('capability', 'read_source_slice')->count())->toBe(0)
        ->and(DB::table('canonical_graph_projections')->count())->toBe(0);
    Bus::assertNotDispatched(ProjectCanonicalGraphToNeo4j::class);
    Bus::assertNotDispatched(GenerateSearchDocumentEmbedding::class);
});

it('job marks projecting before client work then queues a bounded retry and rethrows', function () {
    Bus::fake();
    [$agent, $bindingId] = canonicalProjectionAgent();
    $response = $this->postJson('/api/hades/v1/artifacts', canonicalProjectionUpload($agent, $bindingId), ['Authorization' => 'Bearer '.$agent['token']])->assertCreated();
    $projection = DB::table('canonical_graph_projections')->where('artifact_id', $response->json('artifact.id'))->first();
    $client = new class($projection->id) implements Neo4jClient
    {
        public function __construct(private string $projectionId) {}

        public function run(string $cypher, array $parameters = []): mixed
        {
            expect(DB::table('canonical_graph_projections')->where('id', $this->projectionId)->value('status'))->toBe('projecting');
            throw new RuntimeException('Neo.ClientError.Statement.SyntaxError');
        }
    };
    expect(fn () => runCanonicalProjectionJob($projection->id, $client))->toThrow(RuntimeException::class);
    expect(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe('queued')
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('error_code'))->toBe('neo4j_query_failed');
});

it('job classifies transport exception chains separately from query errors', function (Throwable $failure, string $expected) {
    Bus::fake();
    [$agent, $bindingId] = canonicalProjectionAgent();
    $response = $this->postJson('/api/hades/v1/artifacts', canonicalProjectionUpload($agent, $bindingId), ['Authorization' => 'Bearer '.$agent['token']])->assertCreated();
    $projection = DB::table('canonical_graph_projections')->where('artifact_id', $response->json('artifact.id'))->first();
    $client = new class($failure) implements Neo4jClient
    {
        public function __construct(private Throwable $failure) {}

        public function run(string $cypher, array $parameters = []): mixed
        {
            throw $this->failure;
        }
    };

    expect(fn () => runCanonicalProjectionJob($projection->id, $client))->toThrow($failure::class);
    expect(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe('queued')
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('error_code'))->toBe($expected);
})->with([
    'refused chain' => [new RuntimeException('projection wrapper', 0, new RuntimeException('Connection refused')), 'neo4j_unavailable'],
    'service unavailable' => [new RuntimeException('ServiceUnavailable'), 'neo4j_unavailable'],
    'timeout' => [new RuntimeException('Operation timed out'), 'neo4j_unavailable'],
    'query' => [new RuntimeException('Neo.ClientError.Statement.SyntaxError'), 'neo4j_query_failed'],
]);

it('keeps an intermediate failure queued for the original retry without reconcile dispatch', function () {
    Bus::fake();
    [$agent, $bindingId] = canonicalProjectionAgent();
    $response = $this->postJson('/api/hades/v1/artifacts', canonicalProjectionUpload($agent, $bindingId), ['Authorization' => 'Bearer '.$agent['token']])->assertCreated();
    $projection = DB::table('canonical_graph_projections')->where('artifact_id', $response->json('artifact.id'))->first();
    $failure = new RuntimeException('Connection refused to private-host.example');
    $client = new class($failure) implements Neo4jClient
    {
        public function __construct(private Throwable $failure) {}

        public function run(string $cypher, array $parameters = []): mixed
        {
            throw $this->failure;
        }
    };
    $job = new ProjectCanonicalGraphToNeo4j($projection->id);

    expect(fn () => runCanonicalProjectionJobAttempt($job, $client))->toThrow(RuntimeException::class);
    expect(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe('queued')
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('error_code'))->toBe('neo4j_unavailable');

    Bus::fake();
    $summary = app(Neo4jRebuildService::class)->reconcile(['project_id' => $agent['project_id']]);
    expect($summary['queued'])->toBe(0)
        ->and($summary['skipped'])->toBe(1);
    Bus::assertNotDispatched(ProjectCanonicalGraphToNeo4j::class);

    runCanonicalProjectionJobAttempt($job, new FakeNeo4jClient);
    expect(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe('ready');
});

it('marks only final exhaustion failed and reconcile dispatches exactly one replacement', function () {
    Bus::fake();
    [$agent, $bindingId] = canonicalProjectionAgent();
    $response = $this->postJson('/api/hades/v1/artifacts', canonicalProjectionUpload($agent, $bindingId), ['Authorization' => 'Bearer '.$agent['token']])->assertCreated();
    $projection = DB::table('canonical_graph_projections')->where('artifact_id', $response->json('artifact.id'))->first();
    $failure = new RuntimeException('projection wrapper', 0, new RuntimeException('Connection refused to private-host.example'));
    $client = new class($failure) implements Neo4jClient
    {
        public function __construct(private Throwable $failure) {}

        public function run(string $cypher, array $parameters = []): mixed
        {
            throw $this->failure;
        }
    };
    $job = new ProjectCanonicalGraphToNeo4j($projection->id);

    expect(fn () => runCanonicalProjectionJobAttempt($job, $client))->toThrow(RuntimeException::class);
    $job->failed($failure);
    expect(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe('failed')
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('error_code'))->toBe('neo4j_unavailable')
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('error_code'))->not->toContain('private-host');

    Bus::fake();
    $first = app(Neo4jRebuildService::class)->reconcile(['project_id' => $agent['project_id']]);
    $second = app(Neo4jRebuildService::class)->reconcile(['project_id' => $agent['project_id']]);
    expect($first['queued'])->toBe(1)
        ->and($second['queued'])->toBe(0)
        ->and($second['skipped'])->toBe(1);
    Bus::assertDispatchedTimes(ProjectCanonicalGraphToNeo4j::class, 1);
});

it('ignores an old final failure after a retry is claimed and lets the active delivery publish', function () {
    Bus::fake();
    [$agent, $bindingId] = canonicalProjectionAgent();
    $response = $this->postJson('/api/hades/v1/artifacts', canonicalProjectionUpload($agent, $bindingId), ['Authorization' => 'Bearer '.$agent['token']])->assertCreated();
    $projection = DB::table('canonical_graph_projections')->where('artifact_id', $response->json('artifact.id'))->first();
    $service = app(CanonicalGraphProjectionService::class);
    $oldDelivery = new ProjectCanonicalGraphToNeo4j($projection->id);
    $failure = new RuntimeException('projection wrapper', 0, new RuntimeException('Connection refused to private-host.example'));

    expect($service->claimForWorker($projection->id))->toBeTrue();
    $service->markRetryPending($projection->id, 'neo4j_unavailable');
    expect(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe('queued')
        ->and($service->claimForWorker($projection->id))->toBeTrue();

    $oldDelivery->failed($failure);
    expect(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe('projecting')
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('error_code'))->toBeNull()
        ->and($service->markReady($projection->id, 2, 1))->toBeTrue()
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe('ready');

    Bus::fake();
    $summary = app(Neo4jRebuildService::class)->reconcile(['project_id' => $agent['project_id']]);
    expect($summary['queued'])->toBe(0);
    Bus::assertNotDispatched(ProjectCanonicalGraphToNeo4j::class);
});

it('does not call Neo4j for duplicate jobs after a projection is nonclaimable', function (string $status) {
    Bus::fake();
    [$agent, $bindingId] = canonicalProjectionAgent();
    $response = $this->postJson('/api/hades/v1/artifacts', canonicalProjectionUpload($agent, $bindingId), ['Authorization' => 'Bearer '.$agent['token']])->assertCreated();
    $projection = DB::table('canonical_graph_projections')->where('artifact_id', $response->json('artifact.id'))->first();
    DB::table('canonical_graph_projections')->where('id', $projection->id)->update(['status' => $status]);
    $client = new class implements Neo4jClient
    {
        public int $calls = 0;

        public function run(string $cypher, array $parameters = []): mixed
        {
            $this->calls++;

            throw new RuntimeException('Neo4j must not be called.');
        }
    };

    runCanonicalProjectionJob($projection->id, $client);

    expect($client->calls)->toBe(0)
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe($status);
})->with(['ready', 'stale', 'projecting', 'failed']);

it('allows only one interleaved job to claim a queued projection', function () {
    Bus::fake();
    [$agent, $bindingId] = canonicalProjectionAgent();
    $response = $this->postJson('/api/hades/v1/artifacts', canonicalProjectionUpload($agent, $bindingId), ['Authorization' => 'Bearer '.$agent['token']])->assertCreated();
    $projection = DB::table('canonical_graph_projections')->where('artifact_id', $response->json('artifact.id'))->first();
    $service = app(CanonicalGraphProjectionService::class);

    $firstClaimed = $service->claimForWorker($projection->id);
    $client = new class implements Neo4jClient
    {
        public int $calls = 0;

        public function run(string $cypher, array $parameters = []): mixed
        {
            $this->calls++;

            return [];
        }
    };
    runCanonicalProjectionJob($projection->id, $client);

    expect($firstClaimed)->toBeTrue()
        ->and($client->calls)->toBe(0)
        ->and(DB::table('canonical_graph_projections')->where('id', $projection->id)->value('status'))->toBe('projecting');
});

function canonicalProjectionUpload(array $agent, string $bindingId): array
{
    return ['project_id' => $agent['project_id'], 'workspace_binding_id' => $bindingId, 'schema' => 'hades.code_graph.v1', 'artifact' => [
        'schema' => 'hades.code_graph.v1', 'language' => 'php',
        'graph_contract' => ['version' => 'hades.graph_artifact.v1', 'extractor' => ['name' => 'hades-native-php', 'version' => '1', 'mode' => 'native', 'quality' => 'full', 'fallback_reason' => null], 'coverage' => ['languages' => ['php'], 'files_total' => 1, 'files_analyzed' => 1, 'files_failed' => 0], 'source' => ['branch' => 'main', 'head_commit' => str_repeat('a', 40)]],
        'nodes' => [['id' => 'file:a.php', 'kind' => 'file'], ['id' => 'class:A', 'kind' => 'class']],
        'relationships' => [['id' => 'declares:a', 'type' => 'DECLARES', 'source_id' => 'file:a.php', 'target_id' => 'class:A']],
    ]];
}

function canonicalProjectionAgent(): array
{
    $owner = User::factory()->create(['status' => 'active']);
    $projectId = (string) Str::ulid();
    $now = now();
    DB::table('projects')->insert(['id' => $projectId, 'name' => 'Canonical graph', 'slug' => 'canonical-'.Str::lower(Str::random(8)), 'description' => null, 'status' => 'active', 'default_code_exposure_policy' => 'full_code_artifacts', 'created_by_user_id' => $owner->id, 'created_at' => $now, 'updated_at' => $now]);
    $bootstrapId = (string) Str::ulid();
    $secret = 'canonical-secret';
    $prefix = 'hades_bootstrap_'.$bootstrapId;
    DB::table('hades_bootstrap_tokens')->insert(['id' => $bootstrapId, 'project_id' => $projectId, 'token_prefix' => $prefix, 'token_hash' => hash('sha256', $secret), 'name' => 'Canonical', 'scopes' => json_encode(['hades.bootstrap']), 'allowed_capabilities' => json_encode(['populate_backend_ast']), 'expires_at' => now()->addDay(), 'revoked_at' => null, 'last_used_at' => null, 'created_at' => $now, 'updated_at' => $now]);
    $registered = test()->postJson('/api/hades/v1/agents/register', ['project_id' => $projectId, 'agent_id' => 'canonical-agent', 'label' => 'Canonical', 'platform' => 'linux-x64', 'version' => '1', 'capabilities' => ['populate_backend_ast']], ['Authorization' => 'Bearer '.$prefix.'|'.$secret])->assertOk();
    $agent = ['project_id' => $projectId, 'external_id' => 'canonical-agent', 'token' => $registered->json('agent_token')];
    $bound = test()->postJson('/api/hades/v1/workspaces/bind', ['project_id' => $projectId, 'agent_id' => $agent['external_id'], 'workspace_fingerprint' => 'wf_'.Str::random(8), 'display_path' => '/tmp/canonical', 'git_remote_display' => 'example/repo', 'git_remote_hash' => hash('sha256', 'example/repo'), 'head_commit' => str_repeat('a', 40)], ['Authorization' => 'Bearer '.$agent['token']])->assertOk();

    return [$agent, $bound->json('workspace_binding_id')];
}

function runCanonicalProjectionJob(string $projectionId, Neo4jClient $client): void
{
    runCanonicalProjectionJobAttempt(new ProjectCanonicalGraphToNeo4j($projectionId), $client);
}

function runCanonicalProjectionJobAttempt(ProjectCanonicalGraphToNeo4j $job, Neo4jClient $client): void
{
    $factory = Mockery::mock(Neo4jClientFactory::class);
    $factory->shouldReceive('client')->zeroOrMoreTimes()->andReturn($client);
    $job->handle(app(CanonicalGraphRepository::class), app(CanonicalGraphProjectionService::class), app(Neo4jCanonicalGraphProjector::class), $factory);
}
