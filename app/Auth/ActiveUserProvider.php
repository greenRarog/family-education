<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

class ActiveUserProvider extends EloquentUserProvider
{
    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token)
    {
        // Logout/remember-me may hold a model retrieved before deletion committed.
        $this->newModelQuery()->whereKey($user->getAuthIdentifier())->whereNull('account_deleted_at')
            ->update([$user->getRememberTokenName() => $token]);
        $user->setRememberToken($token);
    }
}
