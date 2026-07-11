<?php

namespace App\Http\Requests\Plugin;

use Illuminate\Foundation\Http\FormRequest;

class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'fingerprint_hash' => ['required', 'string', 'max:255'],
            'platform_os' => ['required', 'string', 'max:64'],
            'platform_arch' => ['required', 'string', 'max:64'],
            'plugin_version' => ['required', 'string', 'max:64'],
        ];
    }
}
