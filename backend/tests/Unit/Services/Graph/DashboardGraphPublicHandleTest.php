<?php

use App\Services\Graph\DashboardGraphPublicHandle;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    config(['app.key' => 'unit-test-app-key']);
});

it('creates the deterministic opaque handle from the ordered canonical payload', function (): void {
    $handle = (new DashboardGraphPublicHandle)->forNode(
        'project-1',
        'repository',
        'repo-1',
        'published-v2',
        'App\\Services\\InvoiceService::charge',
    );

    expect($handle)->toBe('gh1_44MuJGey5mnGFScKSFyZtPAstlxe2UsZDOYjf57f_s0')
        ->and($handle)->not->toContain('project-1')
        ->and($handle)->not->toContain('repo-1')
        ->and($handle)->not->toContain('InvoiceService');
});

it('accepts only well-formed gh1 handles', function (): void {
    $service = new DashboardGraphPublicHandle;
    $valid = $service->forNode('project-1', 'repository', 'repo-1', 'v1', 'node-1');

    expect($service->isWellFormed($valid))->toBeTrue()
        ->and($service->isWellFormed('gh1_'))->toBeFalse()
        ->and($service->isWellFormed('gh1_not-base64!'))->toBeFalse()
        ->and($service->isWellFormed(substr_replace($valid, '!', -1)))->toBeFalse()
        ->and($service->isWellFormed('node-1'))->toBeFalse();
});

it('rejects noncanonical trailing-bit aliases even when their decoded bytes match', function (): void {
    $service = new DashboardGraphPublicHandle;
    $valid = $service->forNode('project-1', 'repository', 'repo-1', 'v1', 'node-1');
    $encoded = substr($valid, strlen('gh1_'));
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    $lastIndex = strpos($alphabet, $encoded[-1]);
    $aliasIndex = $lastIndex ^ 2;
    $alias = 'gh1_'.substr($encoded, 0, -1).$alphabet[$aliasIndex];
    $decode = static function (string $value): string {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    };

    expect($decode(substr($alias, strlen('gh1_'))))->toBe($decode($encoded))
        ->and($service->isWellFormed($alias))->toBeFalse();
});

it('exposes only the bounded handle operations and rejects invalid input with one error', function (): void {
    $service = new DashboardGraphPublicHandle;

    expect(array_values(array_diff(get_class_methods($service), ['forNode', 'isWellFormed', 'keyVersion', 'keyFingerprint'])))
        ->toBe([]);

    expect(fn (): string => $service->forNode('', 'repository', 'repo-1', 'v1', 'node-1'))
        ->toThrow(InvalidArgumentException::class, 'invalid_handle');
});

it('changes the key fingerprint and generated handles when APP_KEY rotates', function (): void {
    $service = new DashboardGraphPublicHandle;
    $oldHandle = $service->forNode('project-1', 'repository', 'repo-1', 'v1', 'node-1');
    $oldFingerprint = $service->keyFingerprint();

    config(['app.key' => 'rotated-unit-test-app-key']);
    $rotated = new DashboardGraphPublicHandle;

    expect($rotated->keyVersion())->toBe('gh1')
        ->and($rotated->keyFingerprint())->not->toBe($oldFingerprint)
        ->and($rotated->forNode('project-1', 'repository', 'repo-1', 'v1', 'node-1'))->not->toBe($oldHandle);
});
