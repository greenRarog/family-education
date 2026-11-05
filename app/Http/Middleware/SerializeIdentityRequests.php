<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Keep Fortify's complete login/reset/token issuance on one side of deletion. */
class SerializeIdentityRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->is('login', 'forgot-password', 'reset-password') || ! is_string($request->input('email'))) {
            return $next($request);
        }

        return DB::transaction(function () use ($request, $next): Response {
            User::query()->where('email', mb_strtolower($request->input('email')))->lockForUpdate()->first();

            return $next($request);
        });
    }
}
