<?php

namespace App\Services\Graph\V2;

use App\Models\HadesGraphImport;

final class GraphV2ChunkValidator
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly GraphV2JsonSchemaValidator $schema) {}

    /**
     * @param  resource  $source
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $descriptor
     * @return array{compressed:resource,uncompressed:string,first_id:string,last_id:string}
     */
    public function validate(HadesGraphImport $import, int $index, $source, array $headers, array $descriptor): array
    {
        $contentEncoding = $this->header($headers, 'Content-Encoding');
        $this->assert($contentEncoding === null || $contentEncoding === '', 'graph_chunk_invalid', 'Content-Encoding is forbidden for graph chunks.');
        $contentType = $this->header($headers, 'Content-Type') ?? '';
        $this->assert(strtolower((string) strtok($contentType, ';')) === 'application/vnd.hades.graph-chunk+gzip', 'graph_chunk_invalid', 'Graph chunk content type is invalid.');
        $compressedLimit = min((int) config('devboard.artifacts.max_chunk_bytes'), self::MAX_BYTES, (int) $descriptor['compressed_bytes']);
        $uncompressedLimit = min(
            (int) config('devboard.artifacts.max_chunk_bytes'),
            self::MAX_BYTES,
            (int) $descriptor['uncompressed_bytes'],
        );
        $compressed = $this->retainCompressed($source, $compressedLimit);
        $keepCompressed = false;
        try {
            $compressedBytes = (int) (fstat($compressed)['size'] ?? 0);
            $compressedSha = hash_final($this->compressedHash);
            $this->assert($compressedBytes === (int) $descriptor['compressed_bytes'], 'graph_chunk_invalid', "Compressed graph chunk byte count does not match its descriptor ({$compressedBytes}/{$descriptor['compressed_bytes']}).");
            $this->assert($compressedSha === $descriptor['compressed_sha256'], 'graph_chunk_invalid', 'Compressed graph chunk digest does not match its descriptor.');
            $this->assertHeader($headers, 'X-Hades-Chunk-Compressed-Bytes', (string) $compressedBytes);
            $this->assertHeader($headers, 'X-Hades-Chunk-Compressed-Sha256', $compressedSha);
            $this->assertHeader($headers, 'X-Hades-Chunk-Sha256', (string) $descriptor['sha256']);
            $this->assertHeader($headers, 'X-Hades-Chunk-Uncompressed-Bytes', (string) $descriptor['uncompressed_bytes']);

            $this->assertGzipHeader($compressed);
            rewind($compressed);
            $uncompressed = $this->inflate($compressed, $uncompressedLimit, $compressedBytes);
            $this->assert(strlen($uncompressed) === (int) $descriptor['uncompressed_bytes'], 'graph_chunk_invalid', 'Uncompressed graph chunk byte count does not match its descriptor.');
            $this->assert(hash('sha256', $uncompressed) === $descriptor['sha256'], 'graph_chunk_invalid', 'Uncompressed graph chunk digest does not match its descriptor.');

            try {
                $chunk = json_decode($uncompressed, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (\JsonException) {
                throw new GraphV2ImportException('graph_chunk_invalid', 'Graph chunk is not valid JSON.');
            }
            $this->assert($chunk instanceof \stdClass, 'graph_chunk_invalid', 'Graph chunk must be a JSON object.');
            try {
                $this->schema->assertValid($chunk, 'chunk.schema.json', 'graph_chunk_invalid');
                $this->assert(app(GraphV2Canonicalizer::class)->canonicalJson($chunk) === $uncompressed, 'graph_chunk_invalid', 'Graph chunk bytes must be the exact JCS representation.');
            } catch (GraphV2ImportException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                throw new GraphV2ImportException('graph_chunk_invalid', 'Graph chunk is not canonicalizable.');
            }
            $records = $chunk->records ?? null;
            $this->assert($chunk->index === $index && $chunk->kind === $descriptor['kind'], 'graph_chunk_invalid', 'Graph chunk descriptor does not match its body.');
            $this->assert(is_array($records) && array_is_list($records), 'graph_chunk_invalid', 'Graph chunk records must be an array.');
            $this->assert(count($records) === (int) $descriptor['record_count'], 'graph_chunk_invalid', 'Graph chunk record count does not match its descriptor.');

            $previous = null;
            foreach ($records as $record) {
                $this->assert($record instanceof \stdClass, 'graph_chunk_invalid', 'Graph chunk records must be JSON objects.');
                $id = $record->id ?? null;
                $this->assert(is_string($id), 'graph_chunk_invalid', 'Graph chunk record IDs are required.');
                $this->assert($previous === null || strcmp($previous, $id) < 0, 'graph_chunk_invalid', 'Graph chunk record IDs must be strictly increasing.');
                $previous = $id;
            }

            rewind($compressed);
            $keepCompressed = true;

            return [
                'compressed' => $compressed,
                'uncompressed' => $uncompressed,
                'first_id' => (string) $records[0]->id,
                'last_id' => (string) $records[count($records) - 1]->id,
            ];
        } finally {
            if (! $keepCompressed && is_resource($compressed)) {
                fclose($compressed);
            }
        }
    }

    /** @param resource $source @return array{stream:resource,bytes:int,sha256:string} */
    public function fingerprint($source, int $limit): array
    {
        $stream = $this->retainCompressed($source, min($limit, (int) config('devboard.artifacts.max_chunk_bytes'), self::MAX_BYTES));

        return [
            'stream' => $stream,
            'bytes' => (int) (fstat($stream)['size'] ?? 0),
            'sha256' => hash_final($this->compressedHash),
        ];
    }

    private \HashContext $compressedHash;

    /** @param resource $source @return resource */
    private function retainCompressed($source, int $limit)
    {
        $this->assert(is_resource($source), 'graph_chunk_invalid', 'Graph chunk body is not streamable.');
        $target = fopen('php://temp/maxmemory:2097152', 'w+b');
        $this->assert(is_resource($target), 'graph_chunk_invalid', 'Graph chunk temporary storage could not be created.');
        $this->compressedHash = hash_init('sha256');
        try {
            while (! feof($source)) {
                $remaining = $limit - (int) ftell($target);
                if ($remaining <= 0) {
                    $extra = fread($source, 1);
                    if ($extra === false) {
                        throw new GraphV2ImportException('graph_chunk_invalid', 'Graph chunk body could not be read.');
                    }
                    if ($extra !== '') {
                        throw new GraphV2ImportException('graph_chunk_too_large', 'Compressed graph chunk exceeds the byte limit.');
                    }

                    break;
                }
                $part = fread($source, min(65536, $remaining + 1));
                if ($part === false) {
                    throw new GraphV2ImportException('graph_chunk_invalid', 'Graph chunk body could not be read.');
                }
                if ($part === '') {
                    continue;
                }
                if (strlen($part) > $remaining) {
                    throw new GraphV2ImportException('graph_chunk_too_large', 'Compressed graph chunk exceeds the byte limit.');
                }
                if (fwrite($target, $part) !== strlen($part)) {
                    throw new GraphV2ImportException('graph_chunk_invalid', 'Graph chunk temporary storage could not be written.');
                }
                hash_update($this->compressedHash, $part);
            }
            rewind($target);

            return $target;
        } catch (\Throwable $exception) {
            fclose($target);
            throw $exception;
        }
    }

    /** @param resource $compressed */
    private function inflate($compressed, int $limit, int $compressedBytes): string
    {
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);
        $this->assert($inflate !== false, 'graph_chunk_invalid', 'Graph chunk gzip stream could not be initialized.');
        $output = fopen('php://temp/maxmemory:2097152', 'w+b');
        $this->assert(is_resource($output), 'graph_chunk_invalid', 'Graph chunk temporary storage could not be created.');
        $total = 0;
        try {
            while (! feof($compressed)) {
                $part = fread($compressed, 65536);
                if ($part === false) {
                    throw new GraphV2ImportException('graph_chunk_invalid', 'Graph chunk gzip stream is invalid.');
                }
                if ($part === '') {
                    $decoded = @inflate_add($inflate, '', ZLIB_FINISH);
                    $this->assert($decoded !== false, 'graph_chunk_invalid', 'Graph chunk gzip stream is invalid.');
                    $total += strlen($decoded);
                    $this->assert($total <= $limit && $total <= max(1, $compressedBytes) * 100, 'graph_chunk_too_large', 'Graph chunk expansion exceeds the configured limit.');
                    if ($decoded !== '') {
                        $this->assert(fwrite($output, $decoded) === strlen($decoded), 'graph_chunk_invalid', 'Graph chunk temporary storage could not be written.');
                    }

                    continue;
                }
                $decoded = @inflate_add($inflate, $part, feof($compressed) ? ZLIB_FINISH : ZLIB_NO_FLUSH);
                $this->assert($decoded !== false, 'graph_chunk_invalid', 'Graph chunk gzip stream is invalid.');
                $total += strlen($decoded);
                $this->assert($total <= $limit, 'graph_chunk_too_large', 'Uncompressed graph chunk exceeds the byte limit.');
                $this->assert($total <= max(1, $compressedBytes) * 100, 'graph_chunk_too_large', 'Graph chunk expansion ratio exceeds 100:1.');
                if ($decoded !== '') {
                    $this->assert(fwrite($output, $decoded) === strlen($decoded), 'graph_chunk_invalid', 'Graph chunk temporary storage could not be written.');
                }
            }
            $this->assert(inflate_get_status($inflate) === ZLIB_STREAM_END, 'graph_chunk_invalid', 'Graph chunk gzip stream did not terminate exactly.');
            $this->assert(inflate_get_read_len($inflate) === $compressedBytes, 'graph_chunk_invalid', 'Graph chunk contains a second gzip member or trailing bytes.');
            rewind($output);
            $result = stream_get_contents($output);
            $this->assert(is_string($result), 'graph_chunk_invalid', 'Graph chunk output could not be read.');

            return $result;
        } finally {
            fclose($output);
        }
    }

    /** @param resource $compressed */
    private function assertGzipHeader($compressed): void
    {
        rewind($compressed);
        $header = fread($compressed, 10);
        $this->assert(is_string($header) && strlen($header) === 10, 'graph_chunk_invalid', 'Graph chunk gzip member is truncated.');
        $values = unpack('C2id/Ccm/Cflg/Vmtime/Cxfl/Cos', $header);
        $this->assert(is_array($values), 'graph_chunk_invalid', 'Graph chunk gzip header is invalid.');
        $this->assert($values['id1'] === 31 && $values['id2'] === 139 && $values['cm'] === 8, 'graph_chunk_invalid', 'Graph chunk is not RFC 1952 gzip.');
        $this->assert($values['flg'] === 0 && $values['mtime'] === 0 && $values['xfl'] === 0 && $values['os'] === 255, 'graph_chunk_invalid', 'Graph chunk gzip metadata is not deterministic.');
    }

    /** @param array<string, string> $headers */
    private function assertHeader(array $headers, string $name, string $expected): void
    {
        $actual = null;
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                $actual = $value;
                break;
            }
        }
        if (str_contains(strtolower($name), 'sha256')) {
            $this->assert(is_string($actual) && preg_match('/\A[0-9a-f]{64}\z/', $actual) === 1, 'graph_chunk_invalid', 'Graph chunk digest header is invalid.');
        }
        $this->assert($actual !== null && (str_contains(strtolower($name), 'sha256') ? hash_equals(strtolower($expected), strtolower((string) $actual)) : (string) $actual === $expected), 'graph_chunk_invalid', 'Graph chunk header does not match the body or descriptor.');
    }

    /** @param array<string, string> $headers */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    private function assert(bool $condition, string $code, string $message): void
    {
        if (! $condition) {
            throw new GraphV2ImportException($code, $message);
        }
    }
}
