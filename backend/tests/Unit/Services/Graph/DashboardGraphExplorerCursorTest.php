<?php

use App\Services\Graph\DashboardGraphExplorerCursor;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config(['app.key' => 'unit-test-app-key']);
});

it('round trips the canonical cursor payload in deterministic field order', function (): void {
    $cursor = new DashboardGraphExplorerCursor;
    $encoded = $cursor->encode(
        'project-1',
        'repository',
        'repo-1',
        'published-v2',
        'search',
        'invoice service',
        '0.875|gh1_node',
    );

    expect($cursor->decode($encoded))->toBe([
        'project_id' => 'project-1',
        'source_scope_type' => 'repository',
        'source_scope_id' => 'repo-1',
        'active_graph_version' => 'published-v2',
        'query_type' => 'search',
        'query' => 'invoice service',
        'sort_key' => '0.875|gh1_node',
    ]);
});

it('uses independently computed canonical JSON and HMAC base64url segments stably', function (): void {
    $cursor = new DashboardGraphExplorerCursor;
    $payload = [
        'project_id' => 'project-1',
        'source_scope_type' => 'repository',
        'source_scope_id' => 'repo-1',
        'active_graph_version' => 'published-v2',
        'query_type' => 'search',
        'query' => 'invoice service',
        'sort_key' => '0.875|gh1_node',
    ];
    $canonicalJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $expected = 'gc1_'.$encode($canonicalJson).'.'.$encode(hash_hmac('sha256', $canonicalJson, (string) config('app.key'), true));
    $encoded = $cursor->encode(...array_values($payload));

    expect($encoded)->toBe($expected)
        ->and($cursor->encode(...array_values($payload)))->toBe($encoded)
        ->and($cursor->decode($expected))->toBe($payload)
        ->and($encoded)->toMatch('/\Agc1_[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/')
        ->and($encoded)->not->toMatch('/[+\/=]/');
});

it('rejects noncanonical trailing-bit aliases in either signed cursor segment', function (): void {
    $cursor = new DashboardGraphExplorerCursor;
    $encoded = $cursor->encode('project-1', 'repository', 'repo-1', 'v1', 'search', 'invoice', 'gh1_node');
    $parts = explode('.', substr($encoded, strlen('gc1_')));
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    $decode = static function (string $value): string {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    };

    foreach ([0, 1] as $segment) {
        $lastIndex = strpos($alphabet, $parts[$segment][-1]);
        $aliasIndex = $lastIndex ^ 2;
        $aliasParts = $parts;
        $aliasParts[$segment] = substr($aliasParts[$segment], 0, -1).$alphabet[$aliasIndex];

        expect($decode($aliasParts[$segment]))->toBe($decode($parts[$segment]))
            ->and(fn (): array => $cursor->decode('gc1_'.implode('.', $aliasParts)))
            ->toThrow(InvalidArgumentException::class, 'invalid_cursor');
    }
});

it('normalizes query text before signing and preserves nullable scopes', function (): void {
    $cursor = new DashboardGraphExplorerCursor;
    $encoded = $cursor->encode('project-1', null, null, 'v1', 'scopes', "  Invoice\nService  ", 'repository|repo-1');

    expect($cursor->decode($encoded))->toMatchArray([
        'project_id' => 'project-1',
        'source_scope_type' => null,
        'source_scope_id' => null,
        'active_graph_version' => 'v1',
        'query_type' => 'scopes',
        'query' => 'Invoice Service',
        'sort_key' => 'repository|repo-1',
    ]);
});

it('round trips a fully scoped route-search cursor with production-sized identities', function (): void {
    $cursor = new DashboardGraphExplorerCursor;
    $encoded = $cursor->encode(
        '01KXJD0SV73EBGWKNE2EK3M4KD',
        'workspace_binding',
        '01KXJD1BDMQ2TFABMVJV6EFE8Q',
        str_repeat('a', 64),
        'search',
        '/generale/soggetti-attivi/',
        '100.12345678901234|gh1_'.str_repeat('b', 86),
    );

    expect($cursor->decode($encoded))->toMatchArray([
        'project_id' => '01KXJD0SV73EBGWKNE2EK3M4KD',
        'source_scope_type' => 'workspace_binding',
        'source_scope_id' => '01KXJD1BDMQ2TFABMVJV6EFE8Q',
        'active_graph_version' => str_repeat('a', 64),
        'query' => '/generale/soggetti-attivi/',
    ]);
});

it('rejects tampered, malformed, and oversized cursor values as invalid_cursor', function (): void {
    $cursor = new DashboardGraphExplorerCursor;
    $encoded = $cursor->encode('project-1', 'repository', 'repo-1', 'v1', 'search', 'invoice', 'gh1_node');

    foreach ([
        substr_replace($encoded, $encoded[-1] === 'a' ? 'b' : 'a', -1),
        'gc1_not-a-cursor',
        str_repeat('x', 4097),
    ] as $invalid) {
        expect(fn (): array => $cursor->decode($invalid))
            ->toThrow(InvalidArgumentException::class, 'invalid_cursor');
    }
});

it('rejects invalid UTF-8 and changes signatures when APP_KEY rotates', function (): void {
    $cursor = new DashboardGraphExplorerCursor;

    expect(fn (): string => $cursor->encode('project-1', null, null, 'v1', 'search', "\xC3\x28", 'sort'))
        ->toThrow(InvalidArgumentException::class, 'invalid_cursor');

    $before = $cursor->encode('project-1', null, null, 'v1', 'search', 'invoice', 'sort');
    config(['app.key' => 'rotated-unit-test-app-key']);
    $after = (new DashboardGraphExplorerCursor)->encode('project-1', null, null, 'v1', 'search', 'invoice', 'sort');

    expect($after)->not->toBe($before)
        ->and(fn (): array => $cursor->decode($before))
        ->toThrow(InvalidArgumentException::class, 'invalid_cursor');
});
