<?php

namespace App\Http\Requests\Plugin;

use App\Support\DevBoardUlid;
use Illuminate\Foundation\Http\FormRequest;

class StartDeltaSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'local_workspace_id' => ['required', 'string', 'exists:local_workspaces,id'],
            'base_snapshot_id' => ['required', 'string', 'exists:snapshots,id'],
            'branch' => ['required', 'string', 'max:255'],
            'base_sha' => ['required', 'string', 'max:255'],
            'head_sha' => ['nullable', 'string', 'max:255'],
            'dirty_status' => ['required', 'string', 'max:64'],
            'manifest' => ['required', 'array'],
            'manifest.changed_file_count' => ['nullable', 'integer', 'min:0'],
            'manifest.changed_files' => ['nullable', 'array'],
            'manifest.risk_report' => ['nullable', 'array'],
            'manifest.risk_report.risk_level' => ['nullable', 'string'],
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
