<?php

use App\Models\User;
use App\Services\Hades\HadesTokenService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('lists only filtered current wiki pages for the authenticated project with a stable cursor', function () {
    $agent = wikiWorkflowAgent();
    $binding = wikiWorkflowBinding($agent);
    $otherAgent = wikiWorkflowAgent();
    $updatedAt = now()->subMinute();

    $matchingPages = [
        wikiWorkflowPage($agent['project_id'], 'pending-one', 'needs_verification', updatedAt: $updatedAt),
        wikiWorkflowPage($agent['project_id'], 'pending-two', 'needs_verification', updatedAt: $updatedAt),
        wikiWorkflowPage($agent['project_id'], 'pending-three', 'needs_verification', updatedAt: $updatedAt),
    ];
    wikiWorkflowPage($agent['project_id'], 'verified', 'verified_from_code', updatedAt: $updatedAt);
    $foreignPage = wikiWorkflowPage($otherAgent['project_id'], 'foreign', 'needs_verification', updatedAt: $updatedAt);

    $query = [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding->id,
        'source_status' => 'needs_verification',
        'limit' => 2,
    ];

    $first = $this->withToken($agent['agent_token'])
        ->getJson('/api/hades/v1/wiki/pages?'.http_build_query($query))
        ->assertOk()
        ->assertJsonCount(2, 'items')
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('project_id', $agent['project_id'])
        ->assertJsonPath('workspace_binding_id', $binding->id)
        ->assertJsonPath('items.0.source_status', 'needs_verification')
        ->assertJsonMissing(['project_id' => $otherAgent['project_id']])
        ->assertJsonMissing(['id' => $foreignPage['page_id']]);

    $nextCursor = $first->json('next_cursor');
    expect($nextCursor)->toBeString()->not->toBeEmpty();

    $second = $this->withToken($agent['agent_token'])
        ->getJson('/api/hades/v1/wiki/pages?'.http_build_query(array_merge($query, ['cursor' => $nextCursor])))
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('next_cursor', null);

    $returnedIds = array_merge(
        array_column($first->json('items'), 'id'),
        array_column($second->json('items'), 'id'),
    );
    $expectedIds = array_column($matchingPages, 'page_id');
    rsort($expectedIds, SORT_STRING);

    expect($returnedIds)->toBe($expectedIds)
        ->and(array_unique($returnedIds))->toHaveCount(3);
});

it('does not follow a current revision pointer owned by another page', function () {
    $agent = wikiWorkflowAgent();
    $binding = wikiWorkflowBinding($agent);
    $revisionOwner = wikiWorkflowPage($agent['project_id'], 'revision-owner', 'needs_verification');
    $invalidPage = wikiWorkflowPage($agent['project_id'], 'invalid-cross-page-pointer', 'needs_verification');

    DB::table('wiki_pages')->where('id', $invalidPage['page_id'])->update([
        'current_revision_id' => $revisionOwner['revision_id'],
    ]);

    $query = http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding->id,
    ]);

    $this->withToken($agent['agent_token'])
        ->getJson('/api/hades/v1/wiki/pages?'.$query)
        ->assertOk()
        ->assertJsonMissing(['id' => $invalidPage['page_id']]);

    $this->withToken($agent['agent_token'])
        ->getJson('/api/hades/v1/wiki/pages/'.$invalidPage['page_id'].'?'.$query)
        ->assertNotFound()
        ->assertJsonPath('error.code', 'wiki_page_not_found');
});

it('does not follow a current revision pointer owned by another project', function () {
    $agent = wikiWorkflowAgent();
    $binding = wikiWorkflowBinding($agent);
    $otherAgent = wikiWorkflowAgent();
    $foreignRevision = wikiWorkflowPage($otherAgent['project_id'], 'foreign-revision-owner', 'needs_verification');
    $invalidPage = wikiWorkflowPage($agent['project_id'], 'invalid-cross-project-pointer', 'needs_verification');

    DB::table('wiki_pages')->where('id', $invalidPage['page_id'])->update([
        'current_revision_id' => $foreignRevision['revision_id'],
    ]);

    $query = http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding->id,
    ]);

    $this->withToken($agent['agent_token'])
        ->getJson('/api/hades/v1/wiki/pages?'.$query)
        ->assertOk()
        ->assertJsonMissing(['id' => $invalidPage['page_id']])
        ->assertJsonMissing(['revision_id' => $foreignRevision['revision_id']]);

    $this->withToken($agent['agent_token'])
        ->getJson('/api/hades/v1/wiki/pages/'.$invalidPage['page_id'].'?'.$query)
        ->assertNotFound()
        ->assertJsonPath('error.code', 'wiki_page_not_found');
});

it('filters by the current revision source status when page and revision columns diverge', function () {
    $agent = wikiWorkflowAgent();
    $binding = wikiWorkflowBinding($agent);
    $page = wikiWorkflowPage($agent['project_id'], 'divergent-source-status', 'needs_verification');

    DB::table('wiki_revisions')->where('id', $page['revision_id'])->update([
        'source_status' => 'verified_from_code',
    ]);

    $query = [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding->id,
        'limit' => 50,
    ];

    $this->withToken($agent['agent_token'])
        ->getJson('/api/hades/v1/wiki/pages?'.http_build_query(array_merge($query, [
            'source_status' => 'needs_verification',
        ])))
        ->assertOk()
        ->assertJsonMissing(['id' => $page['page_id']]);

    $this->withToken($agent['agent_token'])
        ->getJson('/api/hades/v1/wiki/pages?'.http_build_query(array_merge($query, [
            'source_status' => 'verified_from_code',
        ])))
        ->assertOk()
        ->assertJsonPath('items.0.id', $page['page_id'])
        ->assertJsonPath('items.0.source_status', 'verified_from_code');
});

it('uses a lightweight list projection and reports the complete evidence count', function () {
    $agent = wikiWorkflowAgent();
    $binding = wikiWorkflowBinding($agent);
    $evidenceRefs = array_map(
        fn (int $index): array => ['type' => 'file_ref', 'path' => 'src/File'.$index.'.php'],
        range(1, 82),
    );
    $page = wikiWorkflowPage(
        $agent['project_id'],
        'lightweight-list-projection',
        'needs_verification',
        str_repeat('large-markdown-marker ', 2000),
        $evidenceRefs,
    );
    $wikiQueries = [];

    DB::listen(function (QueryExecuted $query) use (&$wikiQueries): void {
        if (str_contains($query->sql, 'wiki_pages') && str_contains($query->sql, 'wiki_revisions')) {
            $wikiQueries[] = $query->sql;
        }
    });

    $this->withToken($agent['agent_token'])
        ->getJson('/api/hades/v1/wiki/pages?'.http_build_query([
            'project_id' => $agent['project_id'],
            'workspace_binding_id' => $binding->id,
        ]))
        ->assertOk()
        ->assertJsonPath('items.0.id', $page['page_id'])
        ->assertJsonPath('items.0.evidence_count', 82)
        ->assertJsonMissingPath('items.0.content_markdown')
        ->assertJsonMissingPath('items.0.evidence_refs');

    expect($wikiQueries)->toHaveCount(1)
        ->and($wikiQueries[0])->not->toContain('content_markdown');
});

it('counts sparse-key evidence objects without loading raw evidence in the list', function () {
    $agent = wikiWorkflowAgent();
    $binding = wikiWorkflowBinding($agent);
    $page = wikiWorkflowPage(
        $agent['project_id'],
        'sparse-evidence-count',
        'needs_verification',
        '# Sparse evidence',
        [
            2 => ['type' => 'artifact_ref', 'sha256' => str_repeat('a', 64)],
            7 => ['type' => 'file_ref', 'path' => 'app/Foo.php', 'sha256' => str_repeat('b', 64)],
            19 => ['type' => 'file_ref', 'path' => 'app/Bar.php', 'sha256' => str_repeat('c', 64)],
        ],
    );

    $this->withToken($agent['agent_token'])
        ->getJson('/api/hades/v1/wiki/pages?'.http_build_query([
            'project_id' => $agent['project_id'],
            'workspace_binding_id' => $binding->id,
        ]))
        ->assertOk()
        ->assertJsonPath('items.0.id', $page['page_id'])
        ->assertJsonPath('items.0.evidence_count', 3)
        ->assertJsonMissingPath('items.0.content_markdown')
        ->assertJsonMissingPath('items.0.evidence_refs');
});

it('validates list bounds and rejects bindings outside the authenticated linked scope', function () {
    $agent = wikiWorkflowAgent();
    $binding = wikiWorkflowBinding($agent);
    $otherAgent = wikiWorkflowAgent($agent['project_id']);
    $otherBinding = wikiWorkflowBinding($otherAgent);

    $this->withToken($agent['agent_token'])->getJson('/api/hades/v1/wiki/pages?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding->id,
        'limit' => 51,
    ]))->assertUnprocessable()->assertJsonValidationErrors('limit');

    $this->withToken($agent['agent_token'])->getJson('/api/hades/v1/wiki/pages?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $otherBinding->id,
    ]))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'workspace_binding_not_found');

    DB::table('hades_workspace_bindings')->where('id', $binding->id)->update([
        'status' => 'unlinked',
        'unlinked_at' => now(),
        'updated_at' => now(),
    ]);

    $this->withToken($agent['agent_token'])->getJson('/api/hades/v1/wiki/pages?'.http_build_query([
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding->id,
    ]))
        ->assertConflict()
        ->assertJsonPath('error.code', 'workspace_binding_unlinked');
});

it('returns a bounded current wiki revision detail with normalized evidence and an explicit shape', function () {
    $agent = wikiWorkflowAgent();
    $binding = wikiWorkflowBinding($agent);
    $markdown = str_repeat('à', 24010);
    $evidenceRefs = [];
    for ($index = 0; $index < 82; $index++) {
        $evidenceRefs[($index * 2) + 1] = [
            'type' => $index === 0 ? 'artifact_ref' : 'file_ref',
            'path' => 'app/File'.$index.'.php',
            'sha256' => str_repeat(dechex($index % 16), 64),
        ];
    }
    $page = wikiWorkflowPage(
        $agent['project_id'],
        'bounded-detail',
        'needs_verification',
        $markdown,
        $evidenceRefs,
        producer: 'hades',
        sourceType: 'hades_agent_draft',
    );

    $response = $this->withToken($agent['agent_token'])
        ->getJson('/api/hades/v1/wiki/pages/'.$page['page_id'].'?'.http_build_query([
            'project_id' => $agent['project_id'],
            'workspace_binding_id' => $binding->id,
        ]))
        ->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('project_id', $agent['project_id'])
        ->assertJsonPath('workspace_binding_id', $binding->id)
        ->assertJsonPath('wiki_page.current_revision_id', $page['revision_id'])
        ->assertJsonPath('wiki_page.revision_id', $page['revision_id'])
        ->assertJsonPath('wiki_page.producer', 'hades')
        ->assertJsonPath('wiki_page.source_type', 'hades_agent_draft')
        ->assertJsonPath('wiki_page.content_truncated', true)
        ->assertJsonCount(80, 'wiki_page.evidence_refs');

    $detail = $response->json('wiki_page');

    expect(mb_strlen($detail['content_markdown']))->toBe(24000)
        ->and($detail['content_markdown'])->toBe(mb_substr($markdown, 0, 24000))
        ->and(array_is_list($detail['evidence_refs']))->toBeTrue()
        ->and(array_keys($detail))->toBe([
            'id',
            'project_id',
            'repository_id',
            'slug',
            'title',
            'page_type',
            'current_revision_id',
            'revision_id',
            'producer',
            'source_type',
            'source_status',
            'content_markdown',
            'content_truncated',
            'evidence_refs',
            'updated_at',
            'revision_created_at',
        ]);
});

it('advertises the wiki list detail draft and verification routes', function () {
    $agent = wikiWorkflowAgent();

    $this->withToken($agent['agent_token'])->getJson('/api/hades/v1/capabilities')
        ->assertOk()
        ->assertJsonPath('routes.wiki_pages', '/api/hades/v1/wiki/pages')
        ->assertJsonPath('routes.wiki_page', '/api/hades/v1/wiki/pages/{page}')
        ->assertJsonPath('routes.wiki_page_draft', '/api/hades/v1/wiki/pages')
        ->assertJsonPath('routes.wiki_page_verify', '/api/hades/v1/wiki/pages/{page}/verify');
});

it('rejects wiki drafts from an agent without the populate project wiki capability', function () {
    $agent = wikiWorkflowAgent(effectiveCapabilities: ['read_files']);
    $binding = wikiWorkflowBinding($agent);

    $this->withToken($agent['agent_token'])
        ->postJson('/api/hades/v1/wiki/pages', wikiWorkflowDraftPayload($agent, $binding))
        ->assertForbidden();

    expect(DB::table('wiki_pages')->where('project_id', $agent['project_id'])->count())->toBe(0)
        ->and(DB::table('wiki_revisions')->count())->toBe(0);
});

it('validates bounded agent-authored wiki draft input', function () {
    $agent = wikiWorkflowAgent();
    $binding = wikiWorkflowBinding($agent);

    foreach ([
        'empty markdown' => [['content_markdown' => ''], 'content_markdown'],
        'overlong markdown' => [['content_markdown' => str_repeat('à', 24001)], 'content_markdown'],
        'too many evidence refs' => [[
            'evidence_refs' => array_map(
                fn (int $index): array => ['type' => 'file_ref', 'path' => "app/File{$index}.php"],
                range(1, 81),
            ),
        ], 'evidence_refs'],
        'caller-selected verified status' => [['source_status' => 'verified_from_code'], 'source_status'],
    ] as [$overrides, $invalidField]) {
        $this->withToken($agent['agent_token'])
            ->postJson('/api/hades/v1/wiki/pages', array_merge(
                wikiWorkflowDraftPayload($agent, $binding),
                $overrides,
            ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($invalidField);
    }

    expect(DB::table('wiki_pages')->where('project_id', $agent['project_id'])->count())->toBe(0)
        ->and(DB::table('wiki_revisions')->count())->toBe(0);
});

it('creates a safe wiki draft then appends an immutable revision for the same project and slug', function () {
    $agent = wikiWorkflowAgent();
    $binding = wikiWorkflowBinding($agent);
    $payload = wikiWorkflowDraftPayload($agent, $binding);

    $first = $this->withToken($agent['agent_token'])
        ->postJson('/api/hades/v1/wiki/pages', $payload)
        ->assertCreated()
        ->assertJsonPath('source_status', 'needs_verification')
        ->assertJsonStructure(['wiki_page_id', 'wiki_revision_id']);

    $second = $this->withToken($agent['agent_token'])
        ->postJson('/api/hades/v1/wiki/pages', array_merge($payload, [
            'title' => 'Architecture draft v2',
            'content_markdown' => '# Architecture v2',
        ]))
        ->assertOk()
        ->assertJsonPath('wiki_page_id', $first->json('wiki_page_id'))
        ->assertJsonPath('source_status', 'needs_verification');

    $revisions = DB::table('wiki_revisions')
        ->where('wiki_page_id', $first->json('wiki_page_id'))
        ->orderBy('created_at')
        ->get();

    expect($second->json('wiki_revision_id'))->not->toBe($first->json('wiki_revision_id'))
        ->and($revisions)->toHaveCount(2)
        ->and($revisions->pluck('id')->all())->toContain($first->json('wiki_revision_id'))
        ->and($revisions->pluck('producer')->unique()->all())->toBe(['hades'])
        ->and($revisions->pluck('source_type')->unique()->all())->toBe(['hades_agent_draft'])
        ->and($revisions->pluck('source_status')->unique()->all())->toBe(['needs_verification'])
        ->and(DB::table('wiki_pages')->where('id', $first->json('wiki_page_id'))->value('current_revision_id'))
        ->toBe($second->json('wiki_revision_id'));
});

/**
 * @return array{project_id: string, agent_id: string, agent_token: string}
 */
function wikiWorkflowAgent(?string $projectId = null, array $effectiveCapabilities = ['populate_project_wiki']): array
{
    if ($projectId === null) {
        $user = User::factory()->create(['status' => 'active']);
        $projectId = (string) Str::ulid();
        $now = now();

        DB::table('projects')->insert([
            'id' => $projectId,
            'name' => 'Wiki workflow project',
            'slug' => 'wiki-workflow-'.Str::lower(Str::random(10)),
            'description' => null,
            'status' => 'active',
            'default_code_exposure_policy' => 'full_code_artifacts',
            'created_by_user_id' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $agentId = (string) Str::ulid();
    $now = now();

    DB::table('hades_agents')->insert([
        'id' => $agentId,
        'project_id' => $projectId,
        'external_agent_id' => 'wiki-agent-'.Str::lower(Str::random(8)),
        'label' => 'Wiki workflow agent',
        'platform' => 'linux-x64',
        'version' => '0.1.0',
        'declared_capabilities' => json_encode($effectiveCapabilities, JSON_THROW_ON_ERROR),
        'effective_capabilities' => json_encode($effectiveCapabilities, JSON_THROW_ON_ERROR),
        'last_seen_at' => $now,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $agent = DB::table('hades_agents')->where('id', $agentId)->first();
    $token = app(HadesTokenService::class)->createAgentToken($agent);

    return [
        'project_id' => $projectId,
        'agent_id' => $agentId,
        'agent_token' => $token['plain_token'],
    ];
}

/**
 * @param  array{project_id: string, agent_id: string, agent_token: string}  $agent
 * @return array<string, mixed>
 */
function wikiWorkflowDraftPayload(array $agent, object $binding): array
{
    return [
        'project_id' => $agent['project_id'],
        'workspace_binding_id' => $binding->id,
        'slug' => 'technical/architecture',
        'title' => 'Architecture draft',
        'page_type' => 'technical',
        'content_markdown' => '# Architecture v1',
        'evidence_refs' => [
            ['type' => 'file_ref', 'path' => 'app/Architecture.php'],
        ],
    ];
}

/**
 * @param  array{project_id: string, agent_id: string, agent_token: string}  $agent
 */
function wikiWorkflowBinding(array $agent): object
{
    $id = (string) Str::ulid();
    $now = now();

    DB::table('hades_workspace_bindings')->insert([
        'id' => $id,
        'project_id' => $agent['project_id'],
        'hades_agent_id' => $agent['agent_id'],
        'external_agent_id' => 'wiki-agent',
        'local_project_id' => 'wiki-project',
        'workspace_fingerprint' => 'wiki-'.Str::lower(Str::random(12)),
        'display_path' => '~/Code/wiki-project',
        'git_remote_display' => null,
        'git_remote_hash' => null,
        'head_commit' => str_repeat('c', 40),
        'platform' => 'linux-x64',
        'status' => 'linked',
        'linked_at' => $now,
        'unlinked_at' => null,
        'last_seen_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return DB::table('hades_workspace_bindings')->where('id', $id)->first();
}

/**
 * @param  array<mixed>  $evidenceRefs
 * @return array{page_id: string, revision_id: string}
 */
function wikiWorkflowPage(
    string $projectId,
    string $slug,
    string $sourceStatus,
    string $markdown = '# Wiki page',
    array $evidenceRefs = [],
    ?DateTimeInterface $updatedAt = null,
    string $producer = 'plugin',
    string $sourceType = 'local_analyzer',
): array {
    $pageId = (string) Str::ulid();
    $revisionId = (string) Str::ulid();
    $updatedAt ??= now();

    DB::table('wiki_pages')->insert([
        'id' => $pageId,
        'project_id' => $projectId,
        'repository_id' => null,
        'slug' => $slug,
        'title' => Str::headline($slug),
        'page_type' => 'technical',
        'current_revision_id' => null,
        'source_status' => $sourceStatus,
        'created_at' => $updatedAt,
        'updated_at' => $updatedAt,
    ]);

    DB::table('wiki_revisions')->insert([
        'id' => $revisionId,
        'wiki_page_id' => $pageId,
        'author_user_id' => null,
        'author_device_id' => null,
        'producer' => $producer,
        'source_type' => $sourceType,
        'source_status' => $sourceStatus,
        'content_markdown' => $markdown,
        'evidence_refs' => json_encode($evidenceRefs, JSON_THROW_ON_ERROR),
        'created_at' => $updatedAt,
    ]);

    DB::table('wiki_pages')->where('id', $pageId)->update(['current_revision_id' => $revisionId]);

    return ['page_id' => $pageId, 'revision_id' => $revisionId];
}
