<?php

namespace App\Rules;

use App\Assistants\ProviderEndpointPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ProviderEndpointRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || ! is_string($value)) {
            return;
        }

        $resolution = app(ProviderEndpointPolicy::class)->resolve($value);

        if (! $resolution->allowed()) {
            $fail(ProviderEndpointPolicy::errorMessage());
        }
    }
}
