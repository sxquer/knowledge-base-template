<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CallEvaluationRun extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['rubric_snapshot' => 'array', 'jev_answers' => 'array', 'result' => 'array', 'score' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
