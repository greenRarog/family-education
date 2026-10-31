<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\Exceptions\HttpResponseException;

class EmailVerificationRequired extends HttpResponseException
{
    public function __construct(bool $profileOwner = false)
    {
        parent::__construct(response()->json([
            'message' => $profileOwner
                ? 'Владелец профиля должен подтвердить email.'
                : 'Подтвердите email, чтобы выполнить это действие.',
            'code' => $profileOwner ? 'profile_email_verification_required' : 'email_verification_required',
        ], 403));
    }
}
