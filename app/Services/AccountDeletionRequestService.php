<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AccountDeletionRegistry;
use App\Enums\UserType;
use App\Models\AccountDeletion;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class AccountDeletionRequestService
{
    public function __construct(private readonly AccountDeletionService $deletion, private readonly AccountDeletionRegistry $registry) {}

    public function delete(User $actor, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $token): AccountDeletion
    {
        abort_unless(config('account_deletion.enabled') && $this->registry->available(), 503, 'Удаление аккаунта пока недоступно.');

        return $actor->getConnection()->transaction(function () use ($actor, $password, $token): AccountDeletion {
            $current = User::on($actor->getConnectionName())->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', $token);
            if ($current->isAccountDeleted()) {
                $previous = AccountDeletion::on($actor->getConnectionName())->find($actor->id);
                abort_unless($previous?->request_token_hash !== null && hash_equals($previous->request_token_hash, $hash), 401);

                return $previous;
            }
            abort_if($current->user_type === UserType::ADMIN, 403);
            if (! Hash::check($password, $current->password)) {
                throw ValidationException::withMessages(['current_password' => 'Неверный текущий пароль. Аккаунт не удалён.']);
            }
            abort_if(AccountDeletion::on($actor->getConnectionName())->where('request_token_hash', $hash)->exists(), 409);
            $result = $this->deletion->delete($current);
            $result->forceFill(['request_token_hash' => $hash])->save();

            return $result;
        });
    }

    /** Outside the deletion transaction: an external failure never rolls back T0. */
    public function confirm(AccountDeletion $deletion): AccountDeletion
    {
        if ($deletion->registry_confirmed_at !== null) {
            return $deletion;
        }
        try {
            if ($this->registry->available() && $this->registry->record($deletion)) {
                AccountDeletion::on($deletion->getConnectionName())->whereKey($deletion->user_id)
                    ->whereNull('registry_confirmed_at')->update(['registry_confirmed_at' => now()]);
            }
        } catch (Throwable $exception) {
            // Never log the status secret, password, payload or transport exception message.
            Log::warning('Account deletion registry acknowledgement pending.', ['user_id' => $deletion->user_id, 'exception_type' => $exception::class]);
        }

        return $deletion->fresh() ?? $deletion;
    }
}
