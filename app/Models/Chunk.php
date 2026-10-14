<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Chunk extends Model
{
    protected $table = 'chunks';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['embedding' => 'array'];
    }
}
