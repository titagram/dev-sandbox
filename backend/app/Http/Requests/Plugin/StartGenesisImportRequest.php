<?php

namespace App\Http\Requests\Plugin;

use Illuminate\Foundation\Http\FormRequest;
use App\Support\DevBoardUlid;

class StartGenesisImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'run_id' => ['required', 'string', 'exists:runs,id'],
            'local_workspace_id' => ['required', 'string', 'exists:local_workspaces,id'],
            'manifest' => ['required', 'array'],
            'manifest.artifacts' => ['required', 'array'],
            'manifest.artifacts.*.artifact_id' => ['required', 'string', 'regex:'.DevBoardUlid::REGEX],
            'manifest.artifacts.*.artifact_type' => ['required', 'string'],
            'manifest.artifacts.*.sha256' => ['required', 'string'],
            'manifest.artifacts.*.size_bytes' => ['required', 'integer', 'min:0', 'max:'.config('devboard.artifacts.max_artifact_bytes')],
            'manifest.artifacts.*.mime_type' => ['nullable', 'string'],
            'manifest.artifacts.*.schema_version' => ['nullable', 'string'],
            'manifest.artifacts.*.producer' => ['nullable', 'string'],
            'manifest.artifacts.*.chunk_count' => ['required', 'integer', 'min:1', 'max:'.config('devboard.artifacts.max_chunks')],
        ];
    }
}
