<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UtilityLine extends Model
{
    protected $fillable = ['name', 'power', 'phases'];

    public function lineable()
    {
        return $this->morphTo();
    }
}
