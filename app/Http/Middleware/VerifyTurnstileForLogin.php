<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\TurnstileService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class VerifyTurnstileForLogin
{
    public function __construct(
        private readonly TurnstileService $turnstile,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->input('cf-turnstile-response');

        if (! is_string($token) || $token === '' || ! $this->turnstile->verify($token)) {
            $message = match (app()->getLocale()) {
                'en' => 'The security check failed. Please try again.',
                'es' => 'No se superó la verificación de seguridad. Inténtalo de nuevo.',
                default => 'Проверка безопасности не пройдена. Попробуйте ещё раз.',
            };

            throw ValidationException::withMessages([
                'cf-turnstile-response' => $message,
            ]);
        }

        return $next($request);
    }
}
