<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['document' => 'array', 'sources' => 'array', 'archived_at' => 'datetime', 'revision' => 'integer'];
    }

    public function publication()
    {
        return $this->hasOne(Publication::class);
    }

    public function revisions()
    {
        return $this->hasMany(Revision::class);
    }

    public function grants()
    {
        return $this->belongsToMany(User::class);
    }
}
