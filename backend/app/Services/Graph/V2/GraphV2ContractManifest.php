<?php

namespace App\Services\Graph\V2;

use InvalidArgumentException;
use RuntimeException;
use stdClass;

final class GraphV2ContractManifest
{
    private const MANIFEST_SCHEMA = 'hades.graph_v2_contract_manifest.v1';

    private const LOCK_SCHEMA = 'hades.graph_v2_contract_lock.v1';

    private readonly string $root;

    public function __construct(string $root)
    {
        $resolvedRoot = realpath($root);

        if ($resolvedRoot === false || ! is_dir($resolvedRoot)) {
            throw new RuntimeException('contract_root_not_directory');
        }

        $this->root = rtrim($resolvedRoot, DIRECTORY_SEPARATOR);
    }

    public function digest(string $filename): string
    {
        $path = $this->resolveRegularFile($filename);
        $digest = hash_file('sha256', $path);

        if ($digest === false) {
            throw new RuntimeException('digest_failed');
        }

        return strtolower($digest);
    }

    public function assertVendoredFilesMatchManifest(): void
    {
        $manifestPath = $this->resolveRegularFile('manifest.json');
        $lockPath = $this->resolveRegularFile('contract-lock.json');
        $manifestBytes = $this->readFile($manifestPath);
        $manifest = $this->decodeObject($manifestBytes, 'manifest');
        $lock = $this->decodeObject($this->readFile($lockPath), 'lock');

        $this->assertClosedObject($manifest, ['schema', 'files'], 'manifest');
        $this->assertClosedObject(
            $lock,
            ['schema', 'schema_source_commit', 'manifest_sha256', 'schema_digests'],
            'lock',
        );

        if ($manifest->schema !== self::MANIFEST_SCHEMA) {
            throw new InvalidArgumentException('manifest_schema_invalid');
        }

        if ($lock->schema !== self::LOCK_SCHEMA) {
            throw new InvalidArgumentException('lock_schema_invalid');
        }

        if (! is_array($manifest->files) || $manifest->files === []) {
            throw new InvalidArgumentException('manifest_files_empty');
        }

        $paths = [];

        foreach ($manifest->files as $record) {
            if (! $record instanceof stdClass) {
                throw new InvalidArgumentException('manifest_file_record_not_object');
            }

            $this->assertClosedObject($record, ['path', 'sha256'], 'manifest_file_record');

            if (! is_string($record->path) || $record->path === '') {
                throw new InvalidArgumentException('manifest_path_invalid');
            }

            if (! is_string($record->sha256)
                || preg_match('/\A[0-9a-f]{64}\z/D', $record->sha256) !== 1) {
                throw new InvalidArgumentException('manifest_sha256_invalid');
            }

            $path = $record->path;

            if (array_key_exists($path, $paths)) {
                throw new InvalidArgumentException('manifest_paths_not_unique');
            }

            $paths[$path] = $record->sha256;
        }

        $orderedPaths = array_keys($paths);
        $sortedPaths = $orderedPaths;
        sort($sortedPaths, SORT_STRING);

        if ($orderedPaths !== $sortedPaths) {
            throw new InvalidArgumentException('manifest_paths_not_sorted');
        }

        foreach ($paths as $path => $expectedDigest) {
            if ($this->digest($path) !== $expectedDigest) {
                throw new RuntimeException('manifest_digest_mismatch');
            }
        }

        if (! is_string($lock->manifest_sha256)
            || $lock->manifest_sha256 !== hash('sha256', $manifestBytes)) {
            throw new RuntimeException('manifest_lock_digest_mismatch');
        }

        if (! is_string($lock->schema_source_commit)
            || preg_match('/\A[0-9a-f]{40}\z/D', $lock->schema_source_commit) !== 1) {
            throw new InvalidArgumentException('schema_source_commit_invalid');
        }

        if (! $lock->schema_digests instanceof stdClass) {
            throw new InvalidArgumentException('schema_digests_invalid');
        }

        $schemaDigests = get_object_vars($lock->schema_digests);

        if (count($schemaDigests) !== count($paths)) {
            throw new InvalidArgumentException('schema_digests_mismatch');
        }

        foreach ($paths as $path => $expectedDigest) {
            if (! property_exists($lock->schema_digests, $path)
                || $lock->schema_digests->{$path} !== $expectedDigest) {
                throw new InvalidArgumentException('schema_digests_mismatch');
            }
        }
    }

    private function resolveRegularFile(string $filename): string
    {
        $this->validateFilename($filename);

        $candidate = $this->root.'/'.$filename;
        $resolved = realpath($candidate);
        $rootPrefix = $this->root.'/';

        if ($resolved === false
            || ! is_file($resolved)
            || ! str_starts_with($resolved, $rootPrefix)) {
            throw new RuntimeException('contract_file_not_regular_or_contained');
        }

        return $resolved;
    }

    private function validateFilename(string $filename): void
    {
        if ($filename === ''
            || str_contains($filename, '\\')
            || str_starts_with($filename, '/')
            || preg_match('/\A[A-Za-z]:/', $filename) === 1
            || preg_match('//u', $filename) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $filename) === 1) {
            throw new InvalidArgumentException('contract_filename_invalid');
        }

        foreach (explode('/', $filename) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('contract_filename_invalid');
            }
        }
    }

    private function readFile(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('contract_file_unreadable');
        }

        return $contents;
    }

    private function decodeObject(string $bytes, string $name): stdClass
    {
        $decoded = json_decode($bytes, flags: JSON_THROW_ON_ERROR);

        if (! $decoded instanceof stdClass) {
            throw new InvalidArgumentException($name.'_not_object');
        }

        return $decoded;
    }

    /**
     * @param list<string> $expectedKeys
     */
    private function assertClosedObject(stdClass $object, array $expectedKeys, string $context): void
    {
        $actualKeys = array_keys(get_object_vars($object));
        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);

        if ($actualKeys !== $expectedKeys) {
            throw new InvalidArgumentException($context.'_keys_invalid');
        }
    }
}
