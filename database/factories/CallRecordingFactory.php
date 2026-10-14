<?php

namespace Database\Factories;

use App\Models\CallRecording;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CallRecording> */
class CallRecordingFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'title' => 'Звонок', 'source_url' => 'https://recordings.example.org/call.mp3?token=private', 'source_host' => 'recordings.example.org', 'status' => 'queued'];
    }
}
