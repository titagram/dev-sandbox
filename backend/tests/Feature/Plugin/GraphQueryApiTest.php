<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\DevBoardSeeder::class);
});

function graphQueryProjectId(): string
{
    return DB::table('projects')->where('slug', 'demo-project')->value('id');
}

function graphQueryCreateToken(string $scopes = 'projects.read'): array
{
    $user = DB::table('users')->where('email', 'admin@example.com')->first();
    $id = (string) Str::ulid();
    $secret = 'test-gq-secret';
    $prefix = 'devb_live_'.$id;
    $now = now();

    DB::table('api_tokens')->insert([
        'id' => $id,
        'token_prefix' => $prefix,
        'token_hash' => hash('sha256', $secret),
        'user_id' => $user->id,
        'device_id' => null,
        'name' => 'Graph Query Token',
        'scopes' => json_encode(explode(',', $scopes), JSON_THROW_ON_ERROR),
        'expires_at' => now()->addMonth(),
        'revoked_at' => null,
        'last_used_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'id' => $id,
        'plain_token' => $prefix.'|'.$secret,
    ];
}

function graphQueryHeaders(string $token): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
        'X-DevBoard-Protocol' => 'v1',
        'X-DevBoard-Plugin-Version' => '0.1.0',
    ];
}

function graphQueryBody(array $overrides = []): array
{
    return array_merge(['protocol_version' => 'v1'], $overrides);
}

it('returns 200 with structured query results for callers', function () {
    $token = graphQueryCreateToken('projects.read');
    $projectId = graphQueryProjectId();

    $this->postJson(
        '/api/plugin/v1/projects/'.$projectId.'/graph/query',
        graphQueryBody(['type' => 'callers', 'symbol_id' => 'App\\Services\\InvoiceService', 'limit' => 10]),
        graphQueryHeaders($token['plain_token']),
    )->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('query_type', 'callers');
});

it('returns 200 with structured query results for callees', function () {
    $token = graphQueryCreateToken('projects.read');
    $projectId = graphQueryProjectId();

    $this->postJson(
        '/api/plugin/v1/projects/'.$projectId.'/graph/query',
        graphQueryBody(['type' => 'callees', 'symbol_id' => 'App\\Services\\InvoiceService', 'limit' => 10]),
        graphQueryHeaders($token['plain_token']),
    )->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('query_type', 'callees');
});

it('returns 200 with structured query results for path queries', function () {
    $token = graphQueryCreateToken('projects.read');
    $projectId = graphQueryProjectId();

    $this->postJson(
        '/api/plugin/v1/projects/'.$projectId.'/graph/query',
        graphQueryBody(['type' => 'path', 'from_symbol_id' => 'App\\Controllers\\Foo', 'to_symbol_id' => 'App\\Services\\Bar', 'max_depth' => 5]),
        graphQueryHeaders($token['plain_token']),
    )->assertOk()
        ->assertJsonPath('protocol_version', 'v1')
        ->assertJsonPath('query_type', 'path');
});

it('returns 403 for missing projects.read scope', function () {
    $token = graphQueryCreateToken('runs.write');
    $projectId = graphQueryProjectId();

    $this->postJson(
        '/api/plugin/v1/projects/'.$projectId.'/graph/query',
        graphQueryBody(['type' => 'callers', 'symbol_id' => 'App\\Services\\InvoiceService', 'limit' => 10]),
        graphQueryHeaders($token['plain_token']),
    )->assertForbidden();
});

it('returns 422 for unsupported query type', function () {
    $token = graphQueryCreateToken('projects.read');
    $projectId = graphQueryProjectId();

    $this->postJson(
        '/api/plugin/v1/projects/'.$projectId.'/graph/query',
        graphQueryBody(['type' => 'invalid', 'symbol_id' => 'App\\Services\\InvoiceService']),
        graphQueryHeaders($token['plain_token']),
    )->assertStatus(422);
});

it('returns 401 without a valid token', function () {
    $projectId = graphQueryProjectId();

    $this->postJson(
        '/api/plugin/v1/projects/'.$projectId.'/graph/query',
        graphQueryBody(['type' => 'callers', 'symbol_id' => 'test']),
    )->assertUnauthorized();
});
