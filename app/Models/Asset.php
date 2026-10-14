<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use HasUuids;

    protected $table = 'assets';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }
}
