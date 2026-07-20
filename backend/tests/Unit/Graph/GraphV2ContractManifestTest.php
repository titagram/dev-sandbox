<?php

use App\Services\Graph\V2\GraphV2Canonicalizer;
use App\Services\Graph\V2\GraphV2ContractManifest;

it('keeps the vendored Graph v2 contract manifest complete and deterministic', function (): void {
    $root = dirname(__DIR__, 3).'/resources/contracts/hades/graph-v2';
    $manifestPath = $root.'/manifest.json';
    $lockPath = $root.'/contract-lock.json';

    expect($root)->toBe(dirname(__DIR__, 3).'/resources/contracts/hades/graph-v2')
        ->and(is_file($manifestPath))->toBeTrue()
        ->and(is_file($lockPath))->toBeTrue();

    if (! is_file($manifestPath) || ! is_file($lockPath)) {
        return;
    }

    $manifestBytes = (string) file_get_contents($manifestPath);
    $manifest = json_decode(
        $manifestBytes,
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $lock = json_decode(
        (string) file_get_contents($lockPath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $files = $manifest['files'] ?? null;

    expect($files)->toBeArray();

    expect($lock)->toBeArray();

    if (! is_array($files) || ! is_array($lock)) {
        return;
    }

    $paths = [];
    $schemaDigests = [];

    foreach ($files as $file) {
        expect($file)->toBeArray();

        if (! is_array($file)) {
            throw new RuntimeException('Graph v2 manifest file record must be an object.');
        }

        expect(array_key_exists('path', $file))->toBeTrue()
            ->and(array_key_exists('sha256', $file))->toBeTrue();

        if (! array_key_exists('path', $file) || ! array_key_exists('sha256', $file)) {
            throw new RuntimeException('Graph v2 manifest file record is missing path or sha256.');
        }

        $path = $file['path'];
        $sha256 = $file['sha256'];

        expect($path)->toBeString()
            ->and($sha256)->toBeString();

        if (! is_string($path) || ! is_string($sha256)) {
            throw new RuntimeException('Graph v2 manifest path and sha256 must be strings.');
        }

        expect($path)->not->toBe('')
            ->and($sha256)->toMatch('/\A[0-9a-f]{64}\z/i');

        if ($path === '' || preg_match('/\A[0-9a-f]{64}\z/i', $sha256) !== 1) {
            throw new RuntimeException('Graph v2 manifest file record has an invalid path or sha256.');
        }

        $paths[] = $path;
        $schemaDigests[$path] = $sha256;

        $vendoredPath = $root.'/'.$path;
        $vendoredDigest = is_file($vendoredPath) ? hash_file('sha256', $vendoredPath) : null;

        expect(is_file($vendoredPath))->toBeTrue()
            ->and($vendoredDigest)->toBe($sha256);
    }

    $sortedPaths = $paths;
    sort($sortedPaths, SORT_STRING);

    expect($paths)->toBe(array_values(array_unique($paths, SORT_STRING)))
        ->and($paths)->toBe($sortedPaths)
        ->and($lock['manifest_sha256'] ?? null)->toBe(hash('sha256', $manifestBytes))
        ->and($lock['schema_digests'] ?? null)->toBe($schemaDigests);

    $contractManifest = new GraphV2ContractManifest($root);

    expect($contractManifest->digest('manifest.json'))->toBe(hash('sha256', $manifestBytes));

    $contractManifest->assertVendoredFilesMatchManifest();
});

it('matches the Graph v2 canonicalization golden vectors', function (array $vector): void {
    $canonicalizer = new GraphV2Canonicalizer;
    $canonicalJson = $canonicalizer->canonicalJson($vector['input']);

    expect(bin2hex($canonicalJson))->toBe($vector['canonical_utf8_hex'])
        ->and($canonicalizer->sha256($vector['input']))->toBe($vector['sha256']);
})->with(function (): array {
    $goldenPath = dirname(__DIR__, 3).'/resources/contracts/hades/graph-v2/golden/canonicalization.json';

    if (! is_file($goldenPath)) {
        throw new RuntimeException('Graph v2 canonicalization golden file is missing.');
    }

    $golden = json_decode(
        (string) file_get_contents($goldenPath),
        flags: JSON_THROW_ON_ERROR,
    );

    return array_map(
        static fn (stdClass $vector): array => [(array) $vector],
        $golden->vectors,
    );
});

it('preserves numeric string keys from JSON stdClass objects', function (): void {
    $input = json_decode('{"1":"value"}', flags: JSON_THROW_ON_ERROR);

    expect($input)->toBeInstanceOf(stdClass::class)
        ->and((new GraphV2Canonicalizer)->canonicalJson($input))->toBe('{"1":"value"}');
});

it('rejects raw wire negative vectors before canonicalization', function (array $vector): void {
    $rawBytes = hex2bin($vector['raw_utf8_hex']);

    if ($rawBytes === false) {
        throw new RuntimeException('Graph v2 raw wire vector contains invalid hexadecimal bytes.');
    }

    $input = json_decode($rawBytes, flags: JSON_THROW_ON_ERROR);

    expect(fn (): string => (new GraphV2Canonicalizer)->canonicalJson($input))
        ->toThrow(InvalidArgumentException::class, $vector['error_code']);
})->with(function (): array {
    $goldenPath = dirname(__DIR__, 3).'/resources/contracts/hades/graph-v2/golden/canonicalization.json';

    $golden = json_decode(
        (string) file_get_contents($goldenPath),
        flags: JSON_THROW_ON_ERROR,
    );

    return array_map(
        static fn (stdClass $vector): array => [(array) $vector],
        $golden->raw_wire_negative_vectors,
    );
});

it('rejects unsafe integers, invalid UTF-8, non-string object keys, and NFC key collisions', function (): void {
    $collision = new stdClass;
    $collision->{'Cafe' . "\u{0301}"} = 'decomposed';
    $collision->{'Café'} = 'composed';

    $cases = [
        [
            'input' => 9007199254740992,
            'error_code' => 'unsafe_integer',
        ],
        [
            'input' => "\xC3\x28",
            'error_code' => 'invalid_utf8',
        ],
        [
            'input' => [1 => 'integer-key'],
            'error_code' => 'object_key_not_string',
        ],
        [
            'input' => $collision,
            'error_code' => 'normalized_key_collision',
        ],
    ];

    foreach ($cases as $case) {
        expect(fn (): string => (new GraphV2Canonicalizer)->canonicalJson($case['input']))
            ->toThrow(InvalidArgumentException::class, $case['error_code']);
    }
});
