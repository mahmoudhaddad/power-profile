<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeneratorLine extends Model
{
    protected $fillable = ['name', 'power', 'phases'];

    public function generable()
    {
        return $this->morphTo();
    }
}
