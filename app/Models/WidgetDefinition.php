<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WidgetDefinition extends Model
{
    use HasUuids;

    protected $table = 'widget_definitions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['fields' => 'array'];
    }
}
