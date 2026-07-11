<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HadesWorkspaceBinding extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'hades_workspace_bindings';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
            'unlinked_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
