<?php

namespace App\Services\Graph\V2;

use InvalidArgumentException;
use Normalizer;
use stdClass;

final class GraphV2Canonicalizer
{
    private const MAX_SAFE_INTEGER = 9007199254740991;

    public function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->normalizeValue($value),
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_LINE_TERMINATORS,
        );
    }

    public function sha256(mixed $value): string
    {
        return hash('sha256', $this->canonicalJson($value));
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            if ($value < -self::MAX_SAFE_INTEGER || $value > self::MAX_SAFE_INTEGER) {
                throw new InvalidArgumentException('unsafe_integer');
            }

            return $value;
        }

        if (is_float($value)) {
            throw new InvalidArgumentException('float_not_allowed');
        }

        if (is_string($value)) {
            return $this->normalizeString($value);
        }

        if ($value instanceof stdClass) {
            return $this->normalizeObject($value);
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                $normalized = [];

                foreach ($value as $item) {
                    $normalized[] = $this->normalizeValue($item);
                }

                return $normalized;
            }

            foreach ($value as $key => $_item) {
                if (! is_string($key)) {
                    throw new InvalidArgumentException('object_key_not_string');
                }
            }

            return $this->normalizeObject($value);
        }

        throw new InvalidArgumentException('unsupported_type');
    }

    /**
     * @param  iterable<array-key, mixed>|stdClass  $properties
     */
    private function normalizeObject(iterable|stdClass $properties): stdClass
    {
        $entries = [];
        $normalizedKeys = [];

        foreach ($properties as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('object_key_not_string');
            }

            $normalizedKey = $this->normalizeString($key);

            if (array_key_exists($normalizedKey, $normalizedKeys)) {
                throw new InvalidArgumentException('normalized_key_collision');
            }

            $normalizedKeys[$normalizedKey] = true;
            $entries[] = [
                'key' => $normalizedKey,
                'sort_key' => $this->utf16be($normalizedKey),
                'value' => $this->normalizeValue($value),
            ];
        }

        usort(
            $entries,
            static fn (array $left, array $right): int => strcmp($left['sort_key'], $right['sort_key']),
        );

        $normalized = new stdClass;

        foreach ($entries as $entry) {
            $normalized->{$entry['key']} = $entry['value'];
        }

        return $normalized;
    }

    private function normalizeString(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('invalid_utf8');
        }

        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);

        if (! is_string($normalized)) {
            throw new InvalidArgumentException('invalid_utf8');
        }

        return $normalized;
    }

    private function utf16be(string $value): string
    {
        $encoded = '';
        $length = strlen($value);

        for ($offset = 0; $offset < $length;) {
            $first = ord($value[$offset]);

            if ($first <= 0x7F) {
                $codePoint = $first;
                $offset += 1;
            } elseif ($first <= 0xDF) {
                $codePoint = (($first & 0x1F) << 6)
                    | (ord($value[$offset + 1]) & 0x3F);
                $offset += 2;
            } elseif ($first <= 0xEF) {
                $codePoint = (($first & 0x0F) << 12)
                    | ((ord($value[$offset + 1]) & 0x3F) << 6)
                    | (ord($value[$offset + 2]) & 0x3F);
                $offset += 3;
            } else {
                $codePoint = (($first & 0x07) << 18)
                    | ((ord($value[$offset + 1]) & 0x3F) << 12)
                    | ((ord($value[$offset + 2]) & 0x3F) << 6)
                    | (ord($value[$offset + 3]) & 0x3F);
                $offset += 4;
            }

            if ($codePoint <= 0xFFFF) {
                $encoded .= pack('n', $codePoint);

                continue;
            }

            $codePoint -= 0x10000;
            $encoded .= pack('n', 0xD800 | ($codePoint >> 10));
            $encoded .= pack('n', 0xDC00 | ($codePoint & 0x3FF));
        }

        return $encoded;
    }
}
