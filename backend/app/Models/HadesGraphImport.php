<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HadesGraphImport extends Model
{
    use HasUlids;

    public const string STATUS_STAGING = 'staging';

    public const string STATUS_VALIDATING = 'validating';

    public const string STATUS_VALIDATED = 'validated';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_STALE = 'stale';

    /**
     * @var list<string>
     */
    public const array LIVE_STATUSES = [
        self::STATUS_STAGING,
        self::STATUS_VALIDATING,
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'hades_graph_imports';

    protected $fillable = [
        'id',
        'project_id',
        'workspace_binding_id',
        'hades_agent_id',
        'attempt_generation',
        'schema',
        'artifact_graph_version',
        'manifest_semantic_sha256',
        'source_identity',
        'manifest',
        'status',
        'completeness_status',
        'expected_chunks',
        'received_chunks',
        'expected_uncompressed_bytes',
        'received_uncompressed_bytes',
        'expected_compressed_bytes',
        'received_compressed_bytes',
        'failure_code',
        'failure_details',
        'completed_at',
        'validated_at',
        'validation_started_at',
        'validation_heartbeat_at',
        'validation_attempts',
        'validation_run_token_hash',
        'validation_lease_expires_at',
        'expires_at',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt_generation' => 'integer',
            'source_identity' => 'array',
            'manifest' => 'array',
            'expected_chunks' => 'integer',
            'received_chunks' => 'integer',
            'expected_uncompressed_bytes' => 'integer',
            'received_uncompressed_bytes' => 'integer',
            'expected_compressed_bytes' => 'integer',
            'received_compressed_bytes' => 'integer',
            'failure_details' => 'array',
            'completed_at' => 'immutable_datetime',
            'validated_at' => 'immutable_datetime',
            'validation_started_at' => 'immutable_datetime',
            'validation_heartbeat_at' => 'immutable_datetime',
            'validation_attempts' => 'integer',
            'validation_lease_expires_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workspaceBinding(): BelongsTo
    {
        return $this->belongsTo(HadesWorkspaceBinding::class, 'workspace_binding_id');
    }

    public function hadesAgent(): BelongsTo
    {
        return $this->belongsTo(HadesAgent::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(HadesGraphImportChunk::class, 'graph_import_id');
    }
}
