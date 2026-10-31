<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceModeration;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\SetRequestLocale;
use App\Http\Middleware\ThrottleAdvertisementRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Verification is a JSON API even when opened without an Accept header.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('email/*') ? null : route('login'));
        $middleware->web(append: [SetRequestLocale::class]);
        $middleware->api(append: [SetRequestLocale::class]);
        // Keep the route-level limiter after web CSRF/auth checks rather than
        // inheriting ThrottleRequests' earlier middleware priority.
        $middleware->appendToPriorityList(SubstituteBindings::class, ThrottleAdvertisementRequests::class);
        $middleware->appendToPriorityList(ThrottleAdvertisementRequests::class, EnforceModeration::class);
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'email/*') || $request->expectsJson(),
        );
        $exceptions->render(function (InvalidSignatureException $exception, Request $request) {
            if ($request->routeIs('verification.verify')) {
                return response()->json([
                    'message' => 'Ссылка подтверждения недействительна или истекла. Запросите новое письмо.',
                    'code' => 'verification_link_invalid',
                ], 403);
            }
        });
    })->create();
