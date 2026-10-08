<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create {email : Admin email address} {name? : Admin display name}';

    protected $description = 'Create an admin login using an interactively entered password';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $name = trim((string) ($this->argument('name') ?: $this->ask('Admin name')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Enter a valid email address.');
            return self::FAILURE;
        }
        if ($name === '') {
            $this->error('Admin name cannot be empty.');
            return self::FAILURE;
        }
        if (User::where('email', $email)->exists()) {
            $this->error('An account with that email already exists.');
            return self::FAILURE;
        }

        $password = $this->secret('Choose a password (at least 12 characters)');
        if (! is_string($password) || strlen($password) < 12) {
            $this->error('Password must be at least 12 characters.');
            return self::FAILURE;
        }
        if ($password !== $this->secret('Confirm password')) {
            $this->error('Passwords do not match.');
            return self::FAILURE;
        }

        User::create(['name' => $name, 'email' => $email, 'password' => $password]);
        $this->info('Admin account created for '.$email.'.');
        return self::SUCCESS;
    }
}
