<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Publication extends Model
{
    use HasUuids;

    protected $table = 'publications';

    protected $guarded = [];

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where(Str::isUuid($value) ? 'id' : 'slug', $value)->first();
    }

    protected function casts(): array
    {
        return ['indexed' => 'boolean'];
    }
}
