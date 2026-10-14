<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Operation extends Model
{
    protected $table = 'operations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['result' => 'array'];
    }
}
