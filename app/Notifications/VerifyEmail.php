<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Support\Facades\URL;

class VerifyEmail extends BaseVerifyEmail
{
    protected function verificationUrl($notifiable): string
    {
        // Sign the internal API path. The frontend page sends it through /auth/;
        // nginx removes that prefix before signed:relative checks the request.
        $signedPath = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
        ], absolute: false);

        return rtrim(config('app.url'), '/').'/verify-email'
            .substr($signedPath, strlen('/email/verify'));
    }
}
