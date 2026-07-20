<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CanonicalGraphProjectionHead extends Model
{
    use HasUlids;

    public const string SCOPE_WORKSPACE_BINDING = 'workspace_binding';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'canonical_graph_projection_heads';

    protected $fillable = [
        'id',
        'project_id',
        'source_scope_type',
        'source_scope_id',
        'desired_generation',
        'desired_graph_import_id',
        'desired_source_generation',
        'desired_artifact_graph_version',
        'desired_verification_set_hash',
        'desired_projection_version',
        'active_projection_id',
        'previous_projection_id',
        'failed_generation',
        'failed_projection_version',
        'failed_at',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'desired_generation' => 'integer',
            'desired_source_generation' => 'integer',
            'failed_generation' => 'integer',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
