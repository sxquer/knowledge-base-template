<?php

namespace App\Console\Commands;

use App\Services\CallLibrary;
use App\Services\Sipuni;
use Illuminate\Console\Command;

class SyncSipuni extends Command
{
    protected $signature = 'kb:sync-sipuni {--manual : Run even when automatic import is disabled}';

    protected $description = 'Import answered Sipuni calls using the configured talk-time threshold';

    public function handle(Sipuni $sipuni, CallLibrary $library): int
    {
        if (! $this->option('manual') && ! $library->config()['enabled']) {
            return self::SUCCESS;
        }
        try {
            $result = $sipuni->sync();
            $this->info('Загружено звонков: '.$result['imported']);

            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
