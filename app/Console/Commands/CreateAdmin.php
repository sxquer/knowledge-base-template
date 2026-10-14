<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateAdmin extends Command
{
    protected $signature = 'kb:admin {email} {--name=Администратор}';

    protected $description = 'Create an administrator with an interactively supplied password';

    public function handle(): int
    {
        $password = $this->secret('Password (at least 12 characters)');
        if (strlen($password ?? '') < 12) {
            $this->error('Password too short');

            return self::FAILURE;
        } User::create(['email' => $this->argument('email'), 'name' => $this->option('name'), 'role' => 'admin', 'password' => $password]);
        $this->info('Administrator created');

        return self::SUCCESS;
    }
}
