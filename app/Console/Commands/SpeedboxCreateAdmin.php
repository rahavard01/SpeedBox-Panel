<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SpeedboxCreateAdmin extends Command
{
    protected $signature = 'speedbox:admin {email?}';
    protected $description = 'Create or update the administrator of a Panel SpeedBox installation';

    public function handle(): int
    {
        $email = (string) ($this->argument('email') ?: $this->ask('Admin email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid email is required.');
            return self::FAILURE;
        }
        $password = (string) $this->secret('Admin password (at least 8 characters)');
        if (strlen($password) < 8) {
            $this->error('The password must contain at least 8 characters.');
            return self::FAILURE;
        }
        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill(['name' => $user->name ?: 'Admin', 'password' => Hash::make($password), 'role' => 1, 'banned' => 0, 'code' => $user->code ?: Str::random(8)])->save();
        $this->info('SpeedBox administrator is ready.');
        return self::SUCCESS;
    }
}
