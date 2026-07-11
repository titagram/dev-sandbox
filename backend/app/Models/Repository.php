<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Repository extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'local_only' => 'boolean',
            'graph_enabled' => 'boolean',
            'protected_paths' => 'array',
            'excluded_paths' => 'array',
            'stack_hints' => 'array',
        ];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function runs()
    {
        return $this->hasMany(Run::class);
    }

    public function artifacts()
    {
        return $this->hasMany(Artifact::class);
    }
}
