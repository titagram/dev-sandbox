<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class PersephoneAgentMessage extends Model
{
    use HasUlids;

    protected $table = 'hades_persephone_agent_messages';

    protected $fillable = [
        'project_id',
        'sender_agent_id',
        'target_agent_id',
        'target_workspace_binding_id',
        'schema',
        'message_id',
        'correlation_id',
        'causation_id',
        'remote_task_id',
        'remote_task_version',
        'message_type',
        'effect',
        'capability',
        'expires_at',
        'payload',
        'envelope',
        'envelope_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'integer',
            'payload' => 'array',
            'envelope' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function targetWorkspaceBinding(): BelongsTo
    {
        return $this->belongsTo(HadesWorkspaceBinding::class, 'target_workspace_binding_id');
    }

    public function scopeForTarget(Builder $query, string $projectId, string $targetAgentId): Builder
    {
        return $query
            ->where('project_id', $projectId)
            ->where('target_agent_id', $targetAgentId);
    }

    public function scopeNotExpired(Builder $query, ?int $now = null): Builder
    {
        return $query->where('expires_at', '>', $now ?? Carbon::now()->timestamp);
    }

    /**
     * @return array<string, mixed>
     */
    public function eventEnvelope(): array
    {
        return array_merge($this->envelope ?? [], ['id' => (string) $this->getKey()]);
    }
}
