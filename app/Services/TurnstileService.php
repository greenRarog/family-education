<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TurnstileService
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function verify(string $token): bool
    {
        $response = Http::asForm()
            ->timeout(5)
            ->post(self::VERIFY_URL, [
                'secret' => config('services.turnstile.secret_key'),
                'response' => $token,
            ]);

        if (! $response->successful()) {
            return false;
        }

        return $response->json('success') === true;
    }
}
