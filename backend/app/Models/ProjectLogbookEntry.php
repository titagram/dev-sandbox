<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ProjectLogbookEntry extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'actor_user_id' => 'integer',
            'references' => 'array',
            'payload' => 'array',
        ];
    }
}
