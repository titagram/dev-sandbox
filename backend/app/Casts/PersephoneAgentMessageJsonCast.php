<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use stdClass;

class PersephoneAgentMessageJsonCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        $decoded = json_decode((string) $value, false, 512, JSON_THROW_ON_ERROR);

        return $this->decode($decoded, $key === 'envelope');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if ($key === 'payload' && is_array($value) && $value === []) {
            $value = new stdClass;
        }

        if ($key === 'envelope'
            && is_array($value)
            && array_key_exists('payload', $value)
            && is_array($value['payload'])
            && $value['payload'] === []) {
            $value['payload'] = new stdClass;
        }

        return [
            $key => json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        ];
    }

    private function decode(mixed $value, bool $topLevel = false): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);

            if ($properties === [] && ! $topLevel) {
                return new stdClass;
            }

            $decoded = [];

            foreach ($properties as $key => $item) {
                $decoded[$key] = $this->decode($item);
            }

            return $decoded;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->decode($item);
            }
        }

        return $value;
    }
}
