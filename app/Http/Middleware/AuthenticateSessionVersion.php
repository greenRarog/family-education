<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSessionVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && $request->hasSession()) {
            // Fresh even if remember-me or an earlier middleware already loaded the user.
            $version = User::query()->whereKey($user->id)->value('session_version');
            $stored = $request->session()->get('account_session_version');
            // Pre-D06 sessions remain valid until the account's first password change.
            $valid = $stored === null ? (int) $version === 0
                : is_array($stored) && ($stored['id'] ?? null) === $user->id
                    && ($stored['version'] ?? null) === (int) $version;
            if ($version !== null && ! $valid) {
                Auth::guard('web')->logoutCurrentDevice();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        return $next($request);
    }
}
