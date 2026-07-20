<?php

namespace App\Services\Graph\V2;

use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\Validator;

final class GraphV2JsonSchemaValidator
{
    private const SCHEMA_BASE = 'https://home-sweet-home.cloud/contracts/hades/graph-v2';

    private Validator $validator;

    public function __construct()
    {
        $resolver = new SchemaResolver;
        $directory = dirname(__DIR__, 4).'/resources/contracts/hades/graph-v2';
        foreach (['artifact.schema.json', 'bundle.schema.json', 'chunk.schema.json'] as $name) {
            $schema = json_decode(file_get_contents($directory.'/'.$name), false, 512, JSON_THROW_ON_ERROR);
            $this->normalizePatterns($schema);
            $resolver->registerRaw($schema);
        }
        $this->validator = (new Validator)->setResolver($resolver);
    }

    public function assertValid(mixed $data, string $schema, string $errorCode = 'graph_manifest_invalid'): void
    {
        $result = $this->validator->validate($this->jsonValue($data), self::SCHEMA_BASE.'/'.$schema);
        if (! $result->isValid()) {
            throw new GraphV2ImportException($errorCode, 'Graph payload does not match its Graph v2 schema.');
        }
    }

    private function jsonValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->jsonValue($item), $value);
        }

        $object = new \stdClass;
        foreach ($value as $key => $item) {
            $object->{$key} = $this->jsonValue($item);
        }

        return $object;
    }

    private function normalizePatterns(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->normalizePatterns($item);
            }

            return;
        }
        if (! is_object($value)) {
            return;
        }
        foreach (get_object_vars($value) as $key => $item) {
            if ($key === 'pattern' && is_string($item)) {
                // Opis parses ECMA-262 patterns with PCRE. Adapt only literal JSON
                // control escapes; schema bytes remain unchanged on disk.
                $value->{$key} = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', static function (array $match): string {
                    $codePoint = hexdec($match[1]);

                    return ($codePoint <= 0x1F || $codePoint === 0x7F)
                        ? sprintf('\\x%02x', $codePoint)
                        : $match[0];
                }, $item) ?? $item;
            } else {
                $this->normalizePatterns($item);
            }
        }
    }
}
