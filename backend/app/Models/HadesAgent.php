<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HadesAgent extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'declared_capabilities' => 'array',
            'effective_capabilities' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }
}
