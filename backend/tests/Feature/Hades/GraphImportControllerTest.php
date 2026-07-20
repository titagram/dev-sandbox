<?php

use App\Models\HadesAgent;
use App\Models\HadesGraphImportChunk;
use App\Models\User;
use App\Services\Graph\V2\GraphV2Canonicalizer;
use App\Services\Graph\V2\GraphV2JsonSchemaValidator;
use App\Services\Hades\HadesTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('g', 32))]);
});

it('creates a graph v2 import and is idempotent for the same semantic manifest', function (): void {
    $fixture = graphImportFixture();
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id']);
    $first = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']));
    $first
        ->assertCreated()
        ->assertJson([
            'attempt_generation' => 1, 'validation_status' => 'staging',
            'publication_status' => 'not_requested', 'missing_chunk_indexes' => [],
        ])
        ->assertJsonStructure(['import_id', 'attempt_generation', 'validation_status', 'publication_status', 'missing_chunk_indexes', 'expires_at']);
    expect($first->json())->toHaveKeys(['import_id', 'attempt_generation', 'validation_status', 'publication_status', 'missing_chunk_indexes', 'expires_at']);

    $replay = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))
        ->assertOk();

    expect($replay->json())->toBe($first->json());
    expect(DB::table('hades_graph_imports')->count())->toBe(1);
});

it('rejects an unauthenticated graph import request', function (): void {
    $fixture = graphImportFixture();

    $this->postJson('/api/hades/v1/graph-imports', graphImportManifest($fixture['project_id'], $fixture['binding_id']))
        ->assertUnauthorized();
});

it('rejects v1 and unknown-field manifests with graph_manifest_invalid', function (): void {
    $fixture = graphImportFixture();
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id']);
    $manifest['schema'] = 'hades.code_graph.v1';

    $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'graph_manifest_invalid');

    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id']);
    $manifest['unexpected'] = true;

    $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'graph_manifest_invalid');
});

it('applies the local schema control-escape adapter without accepting control characters', function (): void {
    $fixture = graphImportFixture();
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id']);
    $manifest['source']['branch'] = "bad\x01branch";

    $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'graph_manifest_invalid');
});

it('requires project scope, owned linked binding, and the graph capability', function (): void {
    $fixture = graphImportFixture();
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id']);

    $wrongProject = $manifest;
    $wrongProject['project']['project_id'] = (string) Str::ulid();
    $this->postJson('/api/hades/v1/graph-imports', $wrongProject, graphImportHeaders($fixture['token']))
        ->assertNotFound();

    $wrongBinding = $manifest;
    $wrongBinding['project']['workspace_binding_id'] = (string) Str::ulid();
    $this->postJson('/api/hades/v1/graph-imports', $wrongBinding, graphImportHeaders($fixture['token']))
        ->assertNotFound();

    $fixture = graphImportFixture(['capabilities' => ['read_files']]);
    $this->postJson('/api/hades/v1/graph-imports', graphImportManifest($fixture['project_id'], $fixture['binding_id']), graphImportHeaders($fixture['token']))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'capability_missing');
});

it('accepts a deterministic chunk once and replays the same bytes', function (): void {
    $fixture = graphImportFixture();
    $chunk = graphImportChunk($fixture['binding_id'], 0);
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id'], [$chunk['descriptor']]);
    $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');

    $headers = graphImportChunkHeaders($chunk, $fixture['token']);
    $this->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/0", [], [], [], graphImportServer($headers), $chunk['gzip'])
        ->assertCreated()
        ->assertExactJson(['index' => 0, 'status' => 'accepted']);
    $this->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/0", [], [], [], graphImportServer($headers), $chunk['gzip'])
        ->assertOk()
        ->assertExactJson(['index' => 0, 'status' => 'accepted']);
});

it('rejects chunk digest conflicts, descriptor mismatches, invalid gzip, and content encoding', function (): void {
    $fixture = graphImportFixture();
    $chunk = graphImportChunk($fixture['binding_id'], 0);
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id'], [$chunk['descriptor']]);
    $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');
    $headers = graphImportChunkHeaders($chunk, $fixture['token']);

    $badHeaders = $headers;
    $badHeaders['X-Hades-Chunk-Sha256'] = str_repeat('a', 64);
    $this->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/0", [], [], [], graphImportServer($badHeaders), $chunk['gzip'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'graph_chunk_invalid');

    $this->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/0", [], [], [], graphImportServer($headers), $chunk['gzip'])
        ->assertCreated();

    $different = graphImportChunk($fixture['binding_id'], 0, ['record_id' => 'hades:node:v2:'.str_repeat('b', 64)]);
    $this->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/0", [], [], [], graphImportServer(graphImportChunkHeaders($different, $fixture['token'])), $different['gzip'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'chunk_digest_conflict');

    $encoded = $headers;
    $encoded['Content-Encoding'] = 'gzip';
    $this->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/0", [], [], [], graphImportServer($encoded), $chunk['gzip'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'graph_chunk_invalid');
});

it('requires all chunks before complete and then honestly reports validating', function (): void {
    $fixture = graphImportFixture();
    $chunk = graphImportChunk($fixture['binding_id'], 0);
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id'], [$chunk['descriptor']]);
    $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');

    $this->postJson("/api/hades/v1/graph-imports/{$import}/complete", ['artifact_graph_version' => $manifest['artifact_graph_version']], graphImportHeaders($fixture['token']))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'graph_import_incomplete');

    $this->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/0", [], [], [], graphImportServer(graphImportChunkHeaders($chunk, $fixture['token'])), $chunk['gzip']);
    $this->postJson("/api/hades/v1/graph-imports/{$import}/complete", ['artifact_graph_version' => $manifest['artifact_graph_version']], graphImportHeaders($fixture['token']))
        ->assertAccepted()
        ->assertExactJson([
            'import_id' => $import, 'validation_status' => 'validating',
            'publication_status' => 'not_requested', 'projection_version' => null,
        ]);
});

it('returns the exact resumable import status and marks expired staging attempts stale', function (): void {
    $fixture = graphImportFixture();
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id']);
    $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');

    DB::table('hades_graph_imports')->where('id', $import)->update(['expires_at' => now()->subSecond()]);

    $this->getJson("/api/hades/v1/graph-imports/{$import}", graphImportHeaders($fixture['token']))
        ->assertOk()
        ->assertJson([
            'import_id' => $import, 'validation_status' => 'stale',
            'publication_status' => 'not_requested', 'received_chunks' => 0,
            'expected_chunks' => 0, 'missing_chunk_indexes' => [],
            'failure' => null, 'projection_version' => null,
        ])
        ->assertJsonStructure(['import_id', 'validation_status', 'publication_status', 'received_chunks', 'expected_chunks', 'missing_chunk_indexes', 'failure', 'projection_version', 'expires_at']);
});

it('reports expired staging as stale without mutating the import on GET', function (): void {
    $fixture = graphImportFixture();
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id']);
    $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');
    DB::table('hades_graph_imports')->where('id', $import)->update(['expires_at' => now()->subSecond()]);

    $this->getJson("/api/hades/v1/graph-imports/{$import}", graphImportHeaders($fixture['token']))
        ->assertOk()
        ->assertJsonPath('validation_status', 'stale');

    expect(DB::table('hades_graph_imports')->where('id', $import)->value('status'))->toBe('staging');
});

it('expires a staging attempt before create selection and allocates the next generation', function (): void {
    $fixture = graphImportFixture();
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id']);
    $first = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->assertCreated();
    DB::table('hades_graph_imports')->where('id', $first->json('import_id'))->update(['expires_at' => now()->subSecond()]);

    $nextManifest = $manifest;
    $nextManifest['source']['tree_sha256'] = str_repeat('e', 64);
    $second = $this->postJson('/api/hades/v1/graph-imports', $nextManifest, graphImportHeaders($fixture['token']))
        ->assertCreated()
        ->assertJsonPath('attempt_generation', 2);

    expect(DB::table('hades_graph_imports')->where('id', $first->json('import_id'))->value('status'))->toBe('stale')
        ->and($second->json('import_id'))->not->toBe($first->json('import_id'));
});

it('commits stale state before rejecting expired chunk and completion mutations', function (): void {
    $fixture = graphImportFixture();
    $chunk = graphImportChunk($fixture['binding_id']);
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id'], [$chunk['descriptor']]);
    $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');
    DB::table('hades_graph_imports')->where('id', $import)->update(['expires_at' => now()->subSecond()]);

    $this->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/0", [], [], [], graphImportServer(graphImportChunkHeaders($chunk, $fixture['token'])), $chunk['gzip'])
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'graph_import_stale');
    expect(DB::table('hades_graph_imports')->where('id', $import)->value('status'))->toBe('stale');

    $secondManifest = graphImportManifest($fixture['project_id'], $fixture['binding_id'], []);
    $second = $this->postJson('/api/hades/v1/graph-imports', $secondManifest, graphImportHeaders($fixture['token']))->json('import_id');
    DB::table('hades_graph_imports')->where('id', $second)->update(['expires_at' => now()->subSecond()]);
    $this->postJson("/api/hades/v1/graph-imports/{$second}/complete", ['artifact_graph_version' => $secondManifest['artifact_graph_version']], graphImportHeaders($fixture['token']))
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'graph_import_stale');
    expect(DB::table('hades_graph_imports')->where('id', $second)->value('status'))->toBe('stale');
});

it('rechecks expiry at the final chunk mutation lock after validation begins', function (): void {
    $fixture = graphImportFixture();
    $chunk = graphImportChunk($fixture['binding_id']);
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id'], [$chunk['descriptor']]);
    $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');
    $expiresAt = Carbon::parse(DB::table('hades_graph_imports')->where('id', $import)->value('expires_at'))->toImmutable();
    $advanced = false;
    DB::listen(function ($event) use (&$advanced, $expiresAt): void {
        if (! $advanced && str_contains($event->sql, 'hades_graph_import_chunks')) {
            Carbon::setTestNow($expiresAt->addSecond());
            $advanced = true;
        }
    });

    try {
        $response = $this->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/0", [], [], [], graphImportServer(graphImportChunkHeaders($chunk, $fixture['token'])), $chunk['gzip']);
        expect($advanced)->toBeTrue()
            ->and($response->status())->toBe(410)
            ->and($response->json('error.code'))->toBe('graph_import_stale');
        expect(DB::table('hades_graph_imports')->where('id', $import)->value('status'))->toBe('stale')
            ->and(DB::table('hades_graph_import_chunks')->where('graph_import_id', $import)->count())->toBe(0);
    } finally {
        Carbon::setTestNow();
    }
});

it('uses semantic manifest identity without generated_at and rejects live conflicts', function (): void {
    $fixture = graphImportFixture();
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id']);
    $first = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->assertCreated();

    $replay = $manifest;
    $replay['generated_at'] = '2026-07-17T12:00:00Z';
    $this->postJson('/api/hades/v1/graph-imports', $replay, graphImportHeaders($fixture['token']))
        ->assertOk()
        ->assertJsonPath('import_id', $first->json('import_id'));

    $conflict = $manifest;
    $conflict['source']['tree_sha256'] = str_repeat('e', 64);
    $this->postJson('/api/hades/v1/graph-imports', $conflict, graphImportHeaders($fixture['token']))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'graph_import_manifest_conflict');
});

it('allocates the next attempt generation after failed and stale attempts and owns storage paths', function (): void {
    $fixture = graphImportFixture();
    $chunk = graphImportChunk($fixture['binding_id']);
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id'], [$chunk['descriptor']]);
    $first = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->assertCreated();
    $firstId = $first->json('import_id');

    DB::table('hades_graph_imports')->where('id', $firstId)->update(['status' => 'failed', 'failure_code' => 'graph_validation_failed']);
    $second = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->assertCreated();
    expect($second->json('attempt_generation'))->toBe(2);

    DB::table('hades_graph_imports')->where('id', $second->json('import_id'))->update(['status' => 'stale']);
    $third = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->assertCreated();
    expect($third->json('attempt_generation'))->toBe(3);

    $this->call('PUT', '/api/hades/v1/graph-imports/'.$third->json('import_id').'/chunks/0', [], [], [], graphImportServer(graphImportChunkHeaders($chunk, $fixture['token'])), $chunk['gzip'])
        ->assertCreated();
    $storedPath = DB::table('hades_graph_import_chunks')->value('storage_path');
    expect($storedPath)->toBe('graph-v2/'.$third->json('import_id').'/chunks/0')
        ->and($storedPath)->not->toContain('client');
});

it('rejects nondeterministic gzip metadata, trailing members, and expired chunk writes', function (): void {
    $fixture = graphImportFixture();
    $chunk = graphImportChunk($fixture['binding_id']);
    $nondeterministic = $chunk['gzip'];
    $nondeterministic[9] = chr(3);
    assertChunkRejected($fixture, $chunk, $nondeterministic);

    $trailing = $chunk['gzip'].gzencode('trailing', 6, FORCE_GZIP);
    $trailing[9 + strlen($chunk['gzip'])] = chr(255);
    $trailingChunk = $chunk;
    $trailingChunk['gzip'] = $trailing;
    $trailingChunk['descriptor']['compressed_bytes'] = strlen($trailing);
    $trailingChunk['descriptor']['compressed_sha256'] = hash('sha256', $trailing);
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id'], [$trailingChunk['descriptor']]);
    $manifest['artifact_graph_version'] = str_repeat('e', 64);
    $manifest['graph_contract']['artifact_graph_version'] = str_repeat('e', 64);
    $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');
    $this->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/0", [], [], [], graphImportServer(graphImportChunkHeaders($trailingChunk, $fixture['token'])), $trailing)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'graph_chunk_invalid');

    $expired = graphImportManifest($fixture['project_id'], $fixture['binding_id']);
    $expired['chunks'] = [$chunk['descriptor']];
    $expired['counts']['nodes'] = 1;
    $expired['artifact_graph_version'] = str_repeat('f', 64);
    $expired['graph_contract']['artifact_graph_version'] = str_repeat('f', 64);
    $expiredId = $this->postJson('/api/hades/v1/graph-imports', $expired, graphImportHeaders($fixture['token']))->json('import_id');
    DB::table('hades_graph_imports')->where('id', $expiredId)->update(['expires_at' => now()->subSecond()]);
    $this->call('PUT', "/api/hades/v1/graph-imports/{$expiredId}/chunks/0", [], [], [], graphImportServer(graphImportChunkHeaders($chunk, $fixture['token'])), $chunk['gzip'])
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'graph_import_stale');
});

it('hides imports from a different authenticated project and keeps validating completion idempotent', function (): void {
    Bus::fake();
    $owner = graphImportFixture();
    $foreign = graphImportFixture();
    $manifest = graphImportManifest($owner['project_id'], $owner['binding_id']);
    $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($owner['token']))->json('import_id');

    $this->getJson("/api/hades/v1/graph-imports/{$import}", graphImportHeaders($foreign['token']))->assertNotFound();

    $chunk = graphImportChunk($owner['binding_id']);
    $manifestWithChunk = graphImportManifest($owner['project_id'], $owner['binding_id'], [$chunk['descriptor']]);
    $manifestWithChunk['artifact_graph_version'] = str_repeat('f', 64);
    $manifestWithChunk['graph_contract']['artifact_graph_version'] = str_repeat('f', 64);
    $import = $this->postJson('/api/hades/v1/graph-imports', $manifestWithChunk, graphImportHeaders($owner['token']))->json('import_id');
    $this->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/0", [], [], [], graphImportServer(graphImportChunkHeaders($chunk, $owner['token'])), $chunk['gzip'])
        ->assertCreated();
    $complete = ['artifact_graph_version' => $manifestWithChunk['artifact_graph_version']];
    $this->postJson("/api/hades/v1/graph-imports/{$import}/complete", $complete, graphImportHeaders($owner['token']))->assertAccepted();
    $this->postJson("/api/hades/v1/graph-imports/{$import}/complete", $complete, graphImportHeaders($owner['token']))
        ->assertAccepted()
        ->assertJsonPath('validation_status', 'validating');
});

it('rejects nested bundle schema violations and artifact-schema-invalid records', function (): void {
    $fixture = graphImportFixture();
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id']);
    $manifest['graph_contract']['completeness']['status'] = 'invalid';
    $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'graph_manifest_invalid');

    $chunk = graphImportChunk($fixture['binding_id'], 0, ['analysis_status' => 'invalid']);
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id'], [$chunk['descriptor']]);
    $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');
    $this->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/0", [], [], [], graphImportServer(graphImportChunkHeaders($chunk, $fixture['token'])), $chunk['gzip'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'graph_chunk_invalid');
});

it('requires exact JCS chunk bytes and descriptor compressed digests', function (): void {
    $fixture = graphImportFixture();
    $chunk = graphImportChunk($fixture['binding_id']);
    $decoded = json_decode($chunk['uncompressed'], true, 512, JSON_THROW_ON_ERROR);
    $nonCanonical = json_encode([
        'schema' => $decoded['schema'],
        'index' => $decoded['index'],
        'kind' => $decoded['kind'],
        'records' => $decoded['records'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $nonCanonicalChunk = graphImportChunkForBody($chunk, $nonCanonical);
    assertChunkRejected($fixture, $nonCanonicalChunk, $nonCanonicalChunk['gzip']);

    $digestMismatch = graphImportChunk($fixture['binding_id']);
    $digestMismatch['descriptor']['compressed_sha256'] = str_repeat('e', 64);
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id'], [$digestMismatch['descriptor']]);
    $manifest['artifact_graph_version'] = str_repeat('e', 64);
    $manifest['graph_contract']['artifact_graph_version'] = str_repeat('e', 64);
    $createDigest = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']));
    $import = $createDigest->json('import_id');
    $headers = graphImportChunkHeaders($digestMismatch, $fixture['token']);
    $headers['X-Hades-Chunk-Compressed-Sha256'] = hash('sha256', $digestMismatch['gzip']);
    $this->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/0", [], [], [], graphImportServer($headers), $digestMismatch['gzip'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'graph_chunk_invalid');
});

it('maps malformed create and complete JSON bodies to graph_manifest_invalid', function (): void {
    $fixture = graphImportFixture();
    $server = graphImportServer([
        'Authorization' => 'Bearer '.$fixture['token'],
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ]);
    $this->call('POST', '/api/hades/v1/graph-imports', [], [], [], $server, '{')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'graph_manifest_invalid');

    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id']);
    $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');
    $this->call('POST', "/api/hades/v1/graph-imports/{$import}/complete", [], [], [], $server, '{')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'graph_manifest_invalid');
});

it('returns graph_import_not_found for unknown and foreign imports', function (): void {
    $fixture = graphImportFixture();
    $this->getJson('/api/hades/v1/graph-imports/'.Str::ulid(), graphImportHeaders($fixture['token']))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'graph_import_not_found');

    $owner = graphImportFixture();
    $foreign = graphImportFixture();
    $manifest = graphImportManifest($owner['project_id'], $owner['binding_id']);
    $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($owner['token']))->json('import_id');
    $this->getJson("/api/hades/v1/graph-imports/{$import}", graphImportHeaders($foreign['token']))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'graph_import_not_found');
});

it('enforces adjacent same-kind ID order regardless of upload order', function (): void {
    $fixture = graphImportFixture();
    $first = graphImportChunk($fixture['binding_id'], 0, ['record_id' => 'hades:node:v2:'.str_repeat('1', 64)]);
    $second = graphImportChunk($fixture['binding_id'], 1, ['record_id' => 'hades:node:v2:'.str_repeat('2', 64)]);
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id'], [$first['descriptor'], $second['descriptor']]);
    $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');
    $this->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/1", [], [], [], graphImportServer(graphImportChunkHeaders($second, $fixture['token'])), $second['gzip'])
        ->assertCreated();
    $this->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/0", [], [], [], graphImportServer(graphImportChunkHeaders($first, $fixture['token'])), $first['gzip'])
        ->assertCreated();

    $badFixture = graphImportFixture();
    $badFirst = graphImportChunk($badFixture['binding_id'], 0, ['record_id' => 'hades:node:v2:'.str_repeat('2', 64)]);
    $badSecond = graphImportChunk($badFixture['binding_id'], 1, ['record_id' => 'hades:node:v2:'.str_repeat('1', 64)]);
    $badManifest = graphImportManifest($badFixture['project_id'], $badFixture['binding_id'], [$badFirst['descriptor'], $badSecond['descriptor']]);
    $badImport = $this->postJson('/api/hades/v1/graph-imports', $badManifest, graphImportHeaders($badFixture['token']))->json('import_id');
    $this->call('PUT', "/api/hades/v1/graph-imports/{$badImport}/chunks/0", [], [], [], graphImportServer(graphImportChunkHeaders($badFirst, $badFixture['token'])), $badFirst['gzip'])
        ->assertCreated();
    $this->call('PUT', "/api/hades/v1/graph-imports/{$badImport}/chunks/1", [], [], [], graphImportServer(graphImportChunkHeaders($badSecond, $badFixture['token'])), $badSecond['gzip'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'graph_chunk_invalid');
});

it('compensates a graph blob when chunk-row persistence fails', function (): void {
    $root = storage_path('framework/testing/graph-import-'.Str::ulid());
    $originalDisk = config('filesystems.disks.local');
    try {
        config(['filesystems.disks.local' => ['driver' => 'local', 'root' => $root, 'throw' => true]]);
        Storage::forgetDisk('local');
        $fixture = graphImportFixture();
        $chunk = graphImportChunk($fixture['binding_id']);
        $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id'], [$chunk['descriptor']]);
        $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');
        HadesGraphImportChunk::creating(static function (): never {
            throw new RuntimeException('simulated chunk row failure');
        });

        try {
            $this->withoutExceptionHandling()->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/0", [], [], [], graphImportServer(graphImportChunkHeaders($chunk, $fixture['token'])), $chunk['gzip']);
            throw new RuntimeException('expected simulated chunk row failure was not raised');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('simulated chunk row failure');
        }
        Storage::disk('local')->assertMissing('graph-v2/'.$import.'/chunks/0');
    } finally {
        HadesGraphImportChunk::flushEventListeners();
        Storage::forgetDisk('local');
        config(['filesystems.disks.local' => $originalDisk]);
        File::deleteDirectory($root);
    }
});

it('uses string ULID identity configuration for Hades agents', function (): void {
    $agent = new HadesAgent;
    expect($agent->getIncrementing())->toBeFalse()
        ->and($agent->getKeyType())->toBe('string');
});

it('uses the normative graph chunk kind order', function (): void {
    expect(HadesGraphImportChunk::KINDS)->toBe(['entrypoints', 'nodes', 'structures', 'edges', 'flows', 'flow_steps', 'uncertainties']);
});

it('recomputes stored manifest semantic identity before completion', function (): void {
    $fixture = graphImportFixture();
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id']);
    $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');
    DB::table('hades_graph_imports')->where('id', $import)->update(['manifest_semantic_sha256' => str_repeat('f', 64)]);
    $this->postJson("/api/hades/v1/graph-imports/{$import}/complete", ['artifact_graph_version' => $manifest['artifact_graph_version']], graphImportHeaders($fixture['token']))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'graph_manifest_invalid');
});

it('keeps success response objects closed and free of internal digest or path fields', function (): void {
    $fixture = graphImportFixture();
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id']);
    $create = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->assertCreated();
    expect(array_keys($create->json()))->toBe([
        'import_id', 'attempt_generation', 'validation_status', 'publication_status', 'missing_chunk_indexes', 'expires_at',
    ]);
    $show = $this->getJson('/api/hades/v1/graph-imports/'.$create->json('import_id'), graphImportHeaders($fixture['token']))->assertOk();
    expect(array_keys($show->json()))->toBe([
        'import_id', 'validation_status', 'publication_status', 'received_chunks', 'expected_chunks',
        'missing_chunk_indexes', 'failure', 'projection_version', 'expires_at',
    ])->and($show->json())->not->toHaveKeys(['storage_path', 'manifest_semantic_sha256', 'compressed_sha256']);
});

it('preserves native JSON objects while validating a chunk', function (): void {
    $fixture = graphImportFixture();
    $chunk = graphImportChunk($fixture['binding_id'], 0, ['record_kind' => 'module']);
    $native = json_decode($chunk['uncompressed'], false, 512, JSON_THROW_ON_ERROR);

    app(GraphV2JsonSchemaValidator::class)->assertValid($native, 'chunk.schema.json', 'graph_chunk_invalid');

    expect($native)->toBeInstanceOf(stdClass::class)
        ->and($native->records[0])->toBeInstanceOf(stdClass::class)
        ->and($native->records[0]->identity)->toBeInstanceOf(stdClass::class)
        ->and($native->records[0]->properties)->toBeInstanceOf(stdClass::class);
});

it('rejects empty and oversized JSON object bodies as graph manifests', function (): void {
    $fixture = graphImportFixture();
    $server = graphImportServer([
        'Authorization' => 'Bearer '.$fixture['token'],
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ]);

    $this->call('POST', '/api/hades/v1/graph-imports', [], [], [], $server, '{}')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'graph_manifest_invalid');

    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id']);
    $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');
    $this->call('POST', "/api/hades/v1/graph-imports/{$import}/complete", [], [], [], $server, '{}')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'graph_manifest_invalid');

    $oversized = '{"padding":"'.str_repeat('a', (4 * 1024 * 1024) + 1).'"}';
    $this->call('POST', '/api/hades/v1/graph-imports', [], [], [], $server, $oversized)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'graph_manifest_invalid');
});

it('enforces framework and language manifest counts', function (): void {
    $fixture = graphImportFixture();
    foreach (['frameworks', 'languages'] as $field) {
        $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id']);
        $manifest['counts'][$field] = 1;

        $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'graph_manifest_invalid');
    }
});

it('bounds new chunk reads by the advertised compressed descriptor size', function (): void {
    $fixture = graphImportFixture();
    $chunk = graphImportChunk($fixture['binding_id']);
    $chunk['descriptor']['compressed_bytes']--;
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id'], [$chunk['descriptor']]);
    $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');

    $this->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/0", [], [], [], graphImportServer(graphImportChunkHeaders($chunk, $fixture['token'])), $chunk['gzip'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'graph_chunk_too_large');
});

it('allows identical replay when the received total reaches the artifact limit', function (): void {
    $originalLimit = config('devboard.artifacts.max_artifact_bytes');
    try {
        $fixture = graphImportFixture();
        $chunk = graphImportChunk($fixture['binding_id']);
        $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id'], [$chunk['descriptor']]);
        $manifestBytes = strlen(app(GraphV2Canonicalizer::class)->canonicalJson($manifest));
        config(['devboard.artifacts.max_artifact_bytes' => $manifestBytes + $chunk['descriptor']['uncompressed_bytes']]);
        $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');
        $path = "/api/hades/v1/graph-imports/{$import}/chunks/0";
        $server = graphImportServer(graphImportChunkHeaders($chunk, $fixture['token']));

        $this->call('PUT', $path, [], [], [], $server, $chunk['gzip'])->assertCreated();
        $this->call('PUT', $path, [], [], [], $server, $chunk['gzip'])->assertOk();
    } finally {
        config(['devboard.artifacts.max_artifact_bytes' => $originalLimit]);
    }
});

it('validates an adjacent stored chunk when the artifact total reaches its limit exactly', function (): void {
    $originalLimit = config('devboard.artifacts.max_artifact_bytes');
    try {
        $fixture = graphImportFixture();
        $first = graphImportChunk($fixture['binding_id'], 0, ['record_id' => 'hades:node:v2:'.str_repeat('1', 64)]);
        $second = graphImportChunk($fixture['binding_id'], 1, ['record_id' => 'hades:node:v2:'.str_repeat('2', 64)]);
        $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id'], [$first['descriptor'], $second['descriptor']]);
        $manifestBytes = strlen(app(GraphV2Canonicalizer::class)->canonicalJson($manifest));
        config(['devboard.artifacts.max_artifact_bytes' => $manifestBytes + $first['descriptor']['uncompressed_bytes'] + $second['descriptor']['uncompressed_bytes']]);
        $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');

        $this->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/0", [], [], [], graphImportServer(graphImportChunkHeaders($first, $fixture['token'])), $first['gzip'])
            ->assertCreated();
        $this->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/1", [], [], [], graphImportServer(graphImportChunkHeaders($second, $fixture['token'])), $second['gzip'])
            ->assertCreated();

        $this->getJson("/api/hades/v1/graph-imports/{$import}", graphImportHeaders($fixture['token']))
            ->assertOk()
            ->assertJson([
                'import_id' => $import,
                'validation_status' => 'staging',
                'received_chunks' => 2,
                'expected_chunks' => 2,
                'missing_chunk_indexes' => [],
            ]);
        $this->postJson("/api/hades/v1/graph-imports/{$import}/complete", ['artifact_graph_version' => $manifest['artifact_graph_version']], graphImportHeaders($fixture['token']))
            ->assertAccepted()
            ->assertJsonPath('validation_status', 'validating');

        expect(DB::table('hades_graph_imports')->where('id', $import)->value('received_uncompressed_bytes'))
            ->toBe($manifest['chunks'][0]['uncompressed_bytes'] + $manifest['chunks'][1]['uncompressed_bytes']);
    } finally {
        config(['devboard.artifacts.max_artifact_bytes' => $originalLimit]);
    }
});

it('rejects a logical artifact budget one byte below manifest plus chunks', function (): void {
    $originalLimit = config('devboard.artifacts.max_artifact_bytes');
    try {
        $fixture = graphImportFixture();
        $chunk = graphImportChunk($fixture['binding_id']);
        $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id'], [$chunk['descriptor']]);
        $manifestBytes = strlen(app(GraphV2Canonicalizer::class)->canonicalJson($manifest));
        config(['devboard.artifacts.max_artifact_bytes' => $manifestBytes + $chunk['descriptor']['uncompressed_bytes'] - 1]);

        $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'graph_manifest_invalid');
        expect(DB::table('hades_graph_imports')->where('project_id', $fixture['project_id'])->count())->toBe(0);
    } finally {
        config(['devboard.artifacts.max_artifact_bytes' => $originalLimit]);
    }
});

it('preserves exact projection candidate states and isolates artifact versions', function (): void {
    foreach (['queued', 'projecting', 'ready', 'failed', 'stale'] as $state) {
        $fixture = graphImportFixture();
        $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id']);
        $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');
        $projectionVersion = str_repeat(substr(hash('sha256', $state), 0, 1), 64);
        insertGraphImportProjectionState($import, $fixture['project_id'], $fixture['binding_id'], $manifest['artifact_graph_version'], $projectionVersion, $state, $state === 'failed');

        $this->getJson("/api/hades/v1/graph-imports/{$import}", graphImportHeaders($fixture['token']))
            ->assertOk()
            ->assertJson([
                'publication_status' => $state,
                'projection_version' => $projectionVersion,
            ]);
    }

    $fixture = graphImportFixture();
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id']);
    $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');
    insertGraphImportProjectionState($import, $fixture['project_id'], $fixture['binding_id'], str_repeat('b', 64), str_repeat('c', 64), null, false);

    $this->getJson("/api/hades/v1/graph-imports/{$import}", graphImportHeaders($fixture['token']))
        ->assertOk()
        ->assertJson([
            'publication_status' => 'not_requested',
            'projection_version' => null,
        ]);
});

it('does not report a ready orphan projection when the head active pointer names another projection', function (): void {
    $fixture = graphImportFixture();
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id']);
    $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');
    $projectionVersion = str_repeat('a', 64);
    $orphanId = (string) Str::ulid();
    DB::table('canonical_graph_projections')->insert([
        'id' => $orphanId, 'project_id' => $fixture['project_id'], 'graph_import_id' => $import,
        'source_scope_type' => 'workspace_binding', 'source_scope_id' => $fixture['binding_id'], 'artifact_type' => 'graph',
        'artifact_id' => (string) Str::ulid(), 'graph_version' => 'orphan', 'checksum' => str_repeat('b', 64), 'head_commit' => null,
        'quality' => 'complete', 'status' => 'ready', 'node_count' => 0, 'relationship_count' => 0, 'error_code' => null,
        'projected_at' => now(), 'graph_contract_version' => 'hades.graph_artifact.v2', 'artifact_graph_version' => $manifest['artifact_graph_version'],
        'verification_set_hash' => str_repeat('c', 64), 'projection_version' => $projectionVersion, 'source_identity' => json_encode([], JSON_THROW_ON_ERROR),
        'completeness' => json_encode([], JSON_THROW_ON_ERROR), 'base_node_count' => 0, 'base_relationship_count' => 0, 'base_flow_count' => 0,
        'effective_node_count' => 0, 'effective_relationship_count' => 0, 'effective_flow_count' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('canonical_graph_projection_heads')->insert([
        'id' => (string) Str::ulid(), 'project_id' => $fixture['project_id'], 'source_scope_type' => 'workspace_binding', 'source_scope_id' => $fixture['binding_id'],
        'desired_generation' => 1, 'desired_graph_import_id' => $import, 'desired_source_generation' => 1, 'desired_artifact_graph_version' => $manifest['artifact_graph_version'], 'desired_verification_set_hash' => str_repeat('c', 64),
        'desired_projection_version' => $projectionVersion, 'active_projection_id' => null, 'previous_projection_id' => null,
        'failed_generation' => null, 'failed_projection_version' => null, 'failed_at' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->getJson("/api/hades/v1/graph-imports/{$import}", graphImportHeaders($fixture['token']))
        ->assertOk()
        ->assertJsonPath('publication_status', 'queued');
});

it('complete on one validated import requests only that import projection', function (): void {
    $fixture = graphImportFixture();
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id']);
    $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');
    DB::table('hades_graph_imports')->where('id', $import)->update(['status' => 'validated', 'validated_at' => now(), 'expires_at' => null]);
    $siblingId = (string) Str::ulid();
    DB::table('hades_graph_imports')->insert([
        'id' => $siblingId, 'project_id' => $fixture['project_id'], 'workspace_binding_id' => $fixture['binding_id'], 'hades_agent_id' => $fixture['agent_id'],
        'attempt_generation' => 2, 'scope_generation' => 2, 'schema' => 'hades.code_graph.v2', 'artifact_graph_version' => str_repeat('d', 64), 'manifest_semantic_sha256' => str_repeat('e', 64),
        'source_identity' => json_encode($manifest['source'], JSON_THROW_ON_ERROR), 'manifest' => json_encode([], JSON_THROW_ON_ERROR), 'status' => 'validated', 'completeness_status' => 'full',
        'expected_chunks' => 0, 'received_chunks' => 0, 'expected_uncompressed_bytes' => 0, 'received_uncompressed_bytes' => 0, 'expected_compressed_bytes' => 0, 'received_compressed_bytes' => 0,
        'failure_code' => null, 'failure_details' => null, 'completed_at' => null, 'validated_at' => now(), 'validation_started_at' => null, 'validation_heartbeat_at' => null,
        'validation_attempts' => 0, 'validation_run_token_hash' => null, 'validation_lease_expires_at' => null, 'expires_at' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->postJson("/api/hades/v1/graph-imports/{$import}/complete", ['artifact_graph_version' => $manifest['artifact_graph_version']], graphImportHeaders($fixture['token']))
        ->assertAccepted();
    expect(DB::table('canonical_graph_projection_heads')->where('desired_artifact_graph_version', $manifest['artifact_graph_version'])->count())->toBe(1)
        ->and(DB::table('canonical_graph_projection_heads')->where('desired_artifact_graph_version', str_repeat('d', 64))->count())->toBe(0);
});

it('rejects completion when counters claim receipts that rows do not prove', function (): void {
    $fixture = graphImportFixture();
    $chunk = graphImportChunk($fixture['binding_id']);
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id'], [$chunk['descriptor']]);
    $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');
    DB::table('hades_graph_imports')->where('id', $import)->update([
        'received_chunks' => 1,
        'received_uncompressed_bytes' => $manifest['chunks'][0]['uncompressed_bytes'],
        'received_compressed_bytes' => $manifest['chunks'][0]['compressed_bytes'],
    ]);

    $this->postJson("/api/hades/v1/graph-imports/{$import}/complete", ['artifact_graph_version' => $manifest['artifact_graph_version']], graphImportHeaders($fixture['token']))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'graph_import_incomplete');
});

it('rejects completion when a received byte counter is corrupted', function (): void {
    $fixture = graphImportFixture();
    $chunk = graphImportChunk($fixture['binding_id']);
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id'], [$chunk['descriptor']]);
    $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');
    $this->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/0", [], [], [], graphImportServer(graphImportChunkHeaders($chunk, $fixture['token'])), $chunk['gzip'])->assertCreated();
    DB::table('hades_graph_imports')->where('id', $import)->decrement('received_uncompressed_bytes');

    $this->postJson("/api/hades/v1/graph-imports/{$import}/complete", ['artifact_graph_version' => $manifest['artifact_graph_version']], graphImportHeaders($fixture['token']))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'graph_import_incomplete');
});

it('rejects noncanonical and overflowing chunk route indexes', function (): void {
    $fixture = graphImportFixture();
    $chunk = graphImportChunk($fixture['binding_id']);
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id'], [$chunk['descriptor']]);
    $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');

    foreach (['abc', '01', '+1', '-1', '9223372036854775808'] as $index) {
        $this->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/{$index}", [], [], [], graphImportServer(graphImportChunkHeaders($chunk, $fixture['token'])), $chunk['gzip'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'graph_chunk_invalid');
    }
});

it('reads stored neighbors from their recorded disk after the configured disk changes', function (): void {
    $rootA = storage_path('framework/testing/graph-import-a-'.Str::ulid());
    $rootB = storage_path('framework/testing/graph-import-b-'.Str::ulid());
    $originalDisk = config('devboard.artifacts.disk');
    $originalA = config('filesystems.disks.graph-test-a');
    $originalB = config('filesystems.disks.graph-test-b');
    try {
        config([
            'filesystems.disks.graph-test-a' => ['driver' => 'local', 'root' => $rootA, 'throw' => true],
            'filesystems.disks.graph-test-b' => ['driver' => 'local', 'root' => $rootB, 'throw' => true],
            'devboard.artifacts.disk' => 'graph-test-a',
        ]);
        Storage::forgetDisk('graph-test-a');
        Storage::forgetDisk('graph-test-b');
        $fixture = graphImportFixture();
        $first = graphImportChunk($fixture['binding_id'], 0, ['record_id' => 'hades:node:v2:'.str_repeat('1', 64)]);
        $second = graphImportChunk($fixture['binding_id'], 1, ['record_id' => 'hades:node:v2:'.str_repeat('2', 64)]);
        $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id'], [$first['descriptor'], $second['descriptor']]);
        $import = $this->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']))->json('import_id');
        $this->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/0", [], [], [], graphImportServer(graphImportChunkHeaders($first, $fixture['token'])), $first['gzip'])->assertCreated();

        config(['devboard.artifacts.disk' => 'graph-test-b']);
        Storage::forgetDisk('graph-test-b');
        $this->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/1", [], [], [], graphImportServer(graphImportChunkHeaders($second, $fixture['token'])), $second['gzip'])->assertCreated();
    } finally {
        Storage::forgetDisk('graph-test-a');
        Storage::forgetDisk('graph-test-b');
        config(['devboard.artifacts.disk' => $originalDisk, 'filesystems.disks.graph-test-a' => $originalA, 'filesystems.disks.graph-test-b' => $originalB]);
        File::deleteDirectory($rootA);
        File::deleteDirectory($rootB);
    }
});

it('returns capability_missing for corrupt legacy capability data', function (): void {
    $fixture = graphImportFixture();
    DB::table('hades_agents')->where('id', $fixture['agent_id'])->update(['effective_capabilities' => 'not-json']);

    $this->postJson('/api/hades/v1/graph-imports', graphImportManifest($fixture['project_id'], $fixture['binding_id']), graphImportHeaders($fixture['token']))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'capability_missing');
});

/** @param array{project_id:string,binding_id:string,token:string} $fixture */
function assertChunkRejected(array $fixture, array $chunk, string $body): void
{
    $manifest = graphImportManifest($fixture['project_id'], $fixture['binding_id'], [$chunk['descriptor']]);
    $create = test()->postJson('/api/hades/v1/graph-imports', $manifest, graphImportHeaders($fixture['token']));
    $import = $create->json('import_id');
    test()->call('PUT', "/api/hades/v1/graph-imports/{$import}/chunks/0", [], [], [], graphImportServer(graphImportChunkHeaders($chunk, $fixture['token'])), $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'graph_chunk_invalid');
}

/** @return array{project_id:string,binding_id:string,token:string,agent_id:string} */
function graphImportFixture(array $overrides = []): array
{
    $user = User::factory()->create(['status' => 'active']);
    $projectId = (string) Str::ulid();
    $agentId = (string) Str::ulid();
    $bindingId = (string) Str::ulid();
    $now = now();
    $capabilities = $overrides['capabilities'] ?? ['populate_backend_ast'];

    DB::table('projects')->insert([
        'id' => $projectId, 'name' => 'Graph v2 project', 'slug' => 'graph-v2-'.Str::lower(Str::random(8)),
        'description' => null, 'status' => 'active', 'default_code_exposure_policy' => 'full_code_artifacts',
        'created_by_user_id' => $user->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hades_agents')->insert([
        'id' => $agentId, 'project_id' => $projectId, 'external_agent_id' => 'graph-agent', 'label' => 'Graph agent',
        'platform' => 'test', 'version' => '1', 'declared_capabilities' => json_encode($capabilities, JSON_THROW_ON_ERROR),
        'effective_capabilities' => json_encode($capabilities, JSON_THROW_ON_ERROR), 'status' => 'active',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hades_workspace_bindings')->insert([
        'id' => $bindingId, 'project_id' => $projectId, 'hades_agent_id' => $agentId, 'external_agent_id' => 'graph-agent',
        'workspace_fingerprint' => 'graph-workspace', 'display_path' => '/workspace', 'status' => 'linked',
        'linked_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $token = app(HadesTokenService::class)->createAgentToken((object) [
        'id' => $agentId, 'project_id' => $projectId, 'external_agent_id' => 'graph-agent',
    ])['plain_token'];

    return ['project_id' => $projectId, 'binding_id' => $bindingId, 'token' => $token, 'agent_id' => $agentId];
}

function graphImportHeaders(string $token): array
{
    return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
}

/** @param list<array<string,mixed>> $chunks */
function graphImportManifest(string $projectId, string $bindingId, array $chunks = []): array
{
    $manifest = [
        'schema' => 'hades.graph_bundle.v2', 'artifact_schema' => 'hades.code_graph.v2',
        'artifact_graph_version' => str_repeat('a', 64), 'generated_at' => '2026-07-16T12:00:00Z',
        'source' => ['head_commit' => null, 'tree_sha256' => str_repeat('b', 64), 'dirty' => false, 'branch' => null],
        'project' => ['project_id' => $projectId, 'workspace_binding_id' => $bindingId],
        'graph_contract' => ['version' => 'hades.graph_artifact.v2', 'artifact_graph_version' => str_repeat('a', 64), 'projection_state' => 'queued', 'completeness' => graphImportCompleteness(), 'coverage' => graphImportCoverage()],
        'frameworks' => [], 'languages' => [],
        'counts' => array_merge(['frameworks' => 0, 'languages' => 0, 'entrypoints' => 0, 'nodes' => 0, 'structures' => 0, 'edges' => 0, 'flows' => 0, 'flow_steps' => 0, 'uncertainties' => 0], array_reduce($chunks, function (array $carry, array $chunk): array {
            $carry[$chunk['kind']] += $chunk['record_count'];

            return $carry;
        }, array_fill_keys(['entrypoints', 'nodes', 'structures', 'edges', 'flows', 'flow_steps', 'uncertainties'], 0))),
        'chunks' => $chunks,
    ];

    return $manifest;
}

/** @return array{descriptor:array<string,mixed>,gzip:string,uncompressed:string,compressed_sha256:string} */
function graphImportChunk(string $bindingId, int $index = 0, array $overrides = []): array
{
    $recordId = $overrides['record_id'] ?? 'hades:node:v2:'.str_repeat('1', 64);
    $record = [
        'id' => $recordId, 'identity' => ['variant' => 'file', 'workspace_binding_id' => $bindingId, 'language' => 'php', 'kind' => 'file', 'path' => 'src/App.php'],
        'kind' => 'file', 'language' => 'php', 'framework' => null, 'name' => 'App.php', 'qualified_name' => null, 'namespace' => null,
        'uncertainty_id' => null, 'location' => null, 'properties' => ['file_sha256' => str_repeat('c', 64), 'byte_size' => 1, 'analysis_status' => $overrides['analysis_status'] ?? 'analyzed', 'omission_reason' => null, 'is_test' => false, 'is_generated' => false],
        'evidence' => ['primary' => ['origin' => 'verified_from_code', 'extractor' => 'test', 'source_locator' => ['kind' => 'file', 'path' => 'src/App.php'], 'source_fingerprint' => str_repeat('c', 64), 'inference_rule' => null], 'supporting' => [], 'supporting_omitted_count' => 0],
    ];
    if (($overrides['record_kind'] ?? 'file') === 'module') {
        $record = [
            'id' => $recordId,
            'identity' => ['variant' => 'source_declaration', 'workspace_binding_id' => $bindingId, 'language' => 'php', 'kind' => 'module', 'namespace' => null, 'qualified_name' => 'App', 'path' => 'src/App.php'],
            'kind' => 'module', 'language' => 'php', 'framework' => null, 'name' => 'App', 'qualified_name' => 'App', 'namespace' => null,
            'uncertainty_id' => null, 'location' => null, 'properties' => (object) [],
            'evidence' => ['primary' => ['origin' => 'verified_from_code', 'extractor' => 'test', 'source_locator' => ['kind' => 'ast', 'path' => 'src/App.php', 'structural_path' => '$'], 'source_fingerprint' => str_repeat('c', 64), 'inference_rule' => null], 'supporting' => [], 'supporting_omitted_count' => 0],
        ];
    }
    $chunk = ['schema' => 'hades.graph_chunk.v2', 'index' => $index, 'kind' => 'nodes', 'records' => [$record]];
    $canonical = app(GraphV2Canonicalizer::class)->canonicalJson($chunk);
    $gzip = gzencode($canonical, 6, FORCE_GZIP);
    $gzip[9] = chr(255);
    $descriptor = ['index' => $index, 'kind' => 'nodes', 'record_count' => 1, 'sha256' => hash('sha256', $canonical), 'uncompressed_bytes' => strlen($canonical), 'compression' => 'gzip', 'compressed_sha256' => hash('sha256', $gzip), 'compressed_bytes' => strlen($gzip)];

    return ['descriptor' => $descriptor, 'gzip' => $gzip, 'uncompressed' => $canonical, 'compressed_sha256' => $descriptor['compressed_sha256']];
}

/** @param array{descriptor:array<string,mixed>} $chunk @return array{descriptor:array<string,mixed>,gzip:string,uncompressed:string,compressed_sha256:string} */
function graphImportChunkForBody(array $chunk, string $body): array
{
    $gzip = gzencode($body, 6, FORCE_GZIP);
    $gzip[9] = chr(255);
    $descriptor = $chunk['descriptor'];
    $descriptor['sha256'] = hash('sha256', $body);
    $descriptor['uncompressed_bytes'] = strlen($body);
    $descriptor['compressed_sha256'] = hash('sha256', $gzip);
    $descriptor['compressed_bytes'] = strlen($gzip);

    return ['descriptor' => $descriptor, 'gzip' => $gzip, 'uncompressed' => $body, 'compressed_sha256' => $descriptor['compressed_sha256']];
}

/** @return array<string, mixed> */
function graphImportCompleteness(): array
{
    $capabilities = [];
    foreach (['inventory', 'entrypoint_discovery', 'symbol_resolution', 'call_graph', 'control_flow', 'framework_lifecycle', 'exceptions', 'async', 'data_access'] as $name) {
        $capabilities[$name] = ['status' => 'not_applicable', 'reasons' => []];
    }

    return ['status' => 'full', 'capabilities' => $capabilities, 'languages' => []];
}

/** @return array<string, mixed> */
function graphImportCoverage(): array
{
    return [
        'scope' => ['included_roots' => ['.'], 'excluded_config_sha256' => str_repeat('d', 64), 'excluded_path_count' => 0],
        'files' => ['discovered' => 0, 'hashed' => 0, 'parser_candidates' => 0, 'analyzed' => 0, 'unsupported' => 0, 'failed' => 0, 'too_large' => 0, 'budget_omitted' => 0],
        'entrypoints' => ['detected' => 0, 'analyzed' => 0, 'partial' => 0, 'by_kind' => (object) []],
        'records' => ['nodes' => 0, 'structures' => 0, 'edges' => 0, 'flows' => 0, 'flow_steps' => 0, 'uncertainties' => 0, 'omitted_by_bundle_budget' => 0],
    ];
}

/** @param array{descriptor:array<string,mixed>,gzip:string} $chunk */
function graphImportChunkHeaders(array $chunk, ?string $token = null): array
{
    return [
        'Authorization' => $token === null ? '' : 'Bearer '.$token,
        'Accept' => 'application/json', 'Content-Type' => 'application/vnd.hades.graph-chunk+gzip',
        'X-Hades-Chunk-Sha256' => $chunk['descriptor']['sha256'], 'X-Hades-Chunk-Uncompressed-Bytes' => (string) $chunk['descriptor']['uncompressed_bytes'],
        'X-Hades-Chunk-Compressed-Sha256' => $chunk['descriptor']['compressed_sha256'], 'X-Hades-Chunk-Compressed-Bytes' => (string) $chunk['descriptor']['compressed_bytes'],
    ];
}

/** @param array<string, string> $headers @return array<string, string> */
function graphImportServer(array $headers): array
{
    $server = [];
    foreach ($headers as $key => $value) {
        $server[strtoupper($key) === 'CONTENT-TYPE' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $key))] = $value;
    }

    return $server;
}

function insertGraphImportProjectionState(
    string $importId,
    string $projectId,
    string $bindingId,
    string $artifactVersion,
    string $projectionVersion,
    ?string $state,
    bool $failed,
): void {
    $projectionId = $state === null ? null : (string) Str::ulid();
    if ($projectionId !== null) {
        DB::table('canonical_graph_projections')->insert([
            'id' => $projectionId,
            'project_id' => $projectId,
            'graph_import_id' => $importId,
            'source_scope_type' => 'workspace_binding',
            'source_scope_id' => $bindingId,
            'artifact_type' => 'graph',
            'artifact_id' => (string) Str::ulid(),
            'graph_version' => 'graph-v2-'.Str::lower(Str::random(20)),
            'checksum' => str_repeat('d', 64),
            'head_commit' => null,
            'quality' => 'complete',
            'status' => $state,
            'node_count' => 0,
            'relationship_count' => 0,
            'error_code' => null,
            'projected_at' => null,
            'graph_contract_version' => 'hades.graph_artifact.v2',
            'artifact_graph_version' => $artifactVersion,
            'verification_set_hash' => str_repeat('e', 64),
            'projection_version' => $projectionVersion,
            'source_identity' => json_encode([], JSON_THROW_ON_ERROR),
            'completeness' => json_encode([], JSON_THROW_ON_ERROR),
            'base_node_count' => 0,
            'base_relationship_count' => 0,
            'base_flow_count' => 0,
            'effective_node_count' => 0,
            'effective_relationship_count' => 0,
            'effective_flow_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::table('canonical_graph_projection_heads')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $projectId,
        'source_scope_type' => 'workspace_binding',
        'source_scope_id' => $bindingId,
        'desired_generation' => 1,
        'desired_graph_import_id' => $importId,
        'desired_source_generation' => 1,
        'desired_artifact_graph_version' => $artifactVersion,
        'desired_verification_set_hash' => str_repeat('e', 64),
        'desired_projection_version' => $projectionVersion,
        'active_projection_id' => $projectionId,
        'previous_projection_id' => null,
        'failed_generation' => $failed ? 1 : null,
        'failed_projection_version' => $failed ? $projectionVersion : null,
        'failed_at' => $failed ? now() : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
