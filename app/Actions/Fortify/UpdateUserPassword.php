<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and update the user's password.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, #[\SensitiveParameter] array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string'],
            'password' => $this->passwordRules(),
            'id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'email' => ['prohibited'],
        ])->validate();

        $updated = $user->getConnection()->transaction(function () use ($user, $input): User {
            $current = User::on($user->getConnectionName())->whereKey($user->id)->lockForUpdate()->first();
            abort_if($current === null || $current->isAccountDeleted(), 401);
            abort_if($current->session_version !== (int) $user->session_version, 401);
            if (! Hash::check($input['current_password'], $current->password)) {
                throw ValidationException::withMessages(['current_password' => 'Неверный текущий пароль.']);
            }
            $current->forceFill([
                'password' => Hash::make($input['password']),
                'remember_token' => Str::random(60),
                'session_version' => $current->session_version + 1,
            ])->save();
            Password::broker(config('fortify.passwords'))->deleteToken($current);
            if (config('session.driver') === 'database') {
                // Logical revocation also rejects sessions written late by another request.
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                    ->where('user_id', $current->id)->where('id', '!=', request()->session()->getId())->delete();
            }

            return $current;
        });
        // Do not adopt a newer version from a second change after this commit.
        $user->setRawAttributes($updated->getAttributes(), true);
    }
}
