<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Socket extends Model
{
    protected $fillable = ['phase_type', 'power', 'quantity'];

    public function socketable()
    {
        return $this->morphTo();
    }
}
