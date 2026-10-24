<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\SupportedLanguage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetRequestLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $userLocale = $request->user()?->locale;
        $requestedLocale = $request->input('language')
            ?? $request->query('language')
            ?? $request->getPreferredLanguage(array_column(SupportedLanguage::cases(), 'value'));
        $locale = $userLocale ?: $requestedLocale;
        if (! in_array($locale, array_column(SupportedLanguage::cases(), 'value'), true)) {
            $locale = SupportedLanguage::RUSSIAN->value;
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
