<?php

namespace App\Jobs;

use App\Services\Sipuni;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportSipuni implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onConnection('calls')->onQueue('calls');
    }

    public function handle(Sipuni $sipuni): void
    {
        $sipuni->sync();
    }
}
