<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerBackup extends Model
{
    protected $fillable = [
        'project_id', 'entity_type', 'entity_id',
        'entity_name', 'created_by', 'data',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
