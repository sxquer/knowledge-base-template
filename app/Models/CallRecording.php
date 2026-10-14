<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CallRecording extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $hidden = ['source_url'];

    protected function casts(): array
    {
        return ['source_url' => 'encrypted', 'words' => 'array', 'segments' => 'array', 'duration' => 'float', 'completion_revision' => 'integer', 'talk_seconds' => 'integer', 'called_at' => 'datetime', 'score' => 'integer', 'evaluation' => 'array', 'rubric_snapshot' => 'array', 'evaluated_at' => 'datetime'];
    }

    public function evaluationRuns(): HasMany
    {
        return $this->hasMany(CallEvaluationRun::class);
    }

    public function snapshot(): array
    {
        return $this->only(['id', 'page_id', 'title', 'source_host', 'status', 'asset_id', 'transcript', 'words', 'segments', 'duration', 'error', 'completion_revision', 'updated_at', 'transcription_provider', 'transcription_model', 'talk_seconds', 'called_at', 'direction', 'operator_name', 'evaluation_status', 'evaluation_error', 'score', 'evaluation', 'rubric_snapshot', 'evaluated_at']);
    }
}
