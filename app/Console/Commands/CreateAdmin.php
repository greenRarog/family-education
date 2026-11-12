<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create {email : Administrator email} {--name=Administrator} {--locale=ru}';

    protected $description = 'Create an administrator using a hidden password prompt; never modify an existing account';

    public function handle(): int
    {
        $data = ['email' => Str::lower(trim((string) $this->argument('email'))),
            'name' => $this->option('name'), 'locale' => $this->option('locale')];
        if (Validator::make($data, ['email' => 'required|email|max:255', 'name' => 'required|string|max:255',
            'locale' => 'required|in:ru,en,es'])->fails()) {
            $this->error('Invalid email, name or locale.');

            return self::FAILURE;
        }
        $existing = User::query()->whereRaw('LOWER(email) = ?', [$data['email']])->first();
        if ($existing !== null) {
            if ($existing->user_type !== UserType::ADMIN || $existing->isAccountDeleted()) {
                $this->error('Email already belongs to an account. No permissions were changed.');

                return self::FAILURE;
            }
            $this->info('Administrator already exists. Credentials and profile were not changed.');

            return self::SUCCESS;
        }
        if (! $this->input->isInteractive()) {
            $this->error('An interactive hidden password prompt is required.');

            return self::FAILURE;
        }
        $password = $this->secret('Password', fallback: false);
        $confirmation = $this->secret('Confirm password', fallback: false);
        if (! is_string($password) || mb_strlen($password) < 8 || $password !== $confirmation) {
            $this->error('Passwords must match and contain at least 8 characters.');

            return self::FAILURE;
        }
        try {
            // This trusted console command verifies the address without sending mail.
            $admin = new User([...$data, 'password' => $password, 'user_type' => UserType::ADMIN]);
            $admin->email_verified_at = now();
            $admin->save();
        } catch (QueryException) {
            // A racing invocation must not overwrite the winner or disclose credentials.
            $this->error('Could not create administrator. Check database availability and whether the account already exists.');

            return self::FAILURE;
        }
        $this->info('Administrator created with a verified email.');

        return self::SUCCESS;
    }
}
