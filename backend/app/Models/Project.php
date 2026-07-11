<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
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
            'archived_at' => 'datetime',
            'deleted_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public function repositories()
    {
        return $this->hasMany(Repository::class);
    }

    public function runs()
    {
        return $this->hasMany(Run::class);
    }

    public function artifacts()
    {
        return $this->hasMany(Artifact::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function archivedBy()
    {
        return $this->belongsTo(User::class, 'archived_by_user_id');
    }

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }

    public function restoredBy()
    {
        return $this->belongsTo(User::class, 'restored_by_user_id');
    }
}
