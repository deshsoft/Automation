<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('app:create-user {--name=} {--email=} {--password=}')]
#[Description('Create a user who can log in to the dashboard')]
class CreateUser extends Command
{
    public function handle(): int
    {
        $name = $this->option('name') ?: text('Name', required: true);
        $email = $this->option('email') ?: text('Email', required: true, validate: ['email' => 'email|unique:users,email']);
        $plainPassword = $this->option('password') ?: password('Password (min 8 characters)', required: true, validate: ['password' => 'min:8']);

        if (User::where('email', $email)->exists()) {
            $this->error("A user with email {$email} already exists.");

            return self::FAILURE;
        }

        User::create(['name' => $name, 'email' => $email, 'password' => $plainPassword]);

        $this->info("User {$email} created. You can log in now.");

        return self::SUCCESS;
    }
}
