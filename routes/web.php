<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Middleware\ThrottleAdvertisementRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/api/user', function () {
    if (! Auth::check()) {
        return response()->json([
            'appeal_email' => config('moderation.appeal_email'),
            'authenticated' => false,
            'user' => null,
        ]);
    }

    $user = Auth::user()->fresh();
    $action = $user->blockingAction;

    return response()->json([
        'appeal_email' => config('moderation.appeal_email'),
        'authenticated' => true,
        'user' => [
            'id' => $user->id,
            'is_blocked' => $user->isBlocked(),
            'block_action_id' => $action?->id,
            'blocked_at' => $action?->occurred_at,
            'block_reason_code' => $action?->reason_code,
            'block_reason' => $action?->reason,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at,
            'email_verified' => $user->hasVerifiedEmail(),
            'user_type' => $user->user_type,
            'locale' => $user->locale,
        ],
    ]);
});

// Application-owned verification routes; Fortify continues to own login/registration.
Route::middleware('auth')->group(function () {
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed:relative', ThrottleAdvertisementRequests::using('email-verification-check')])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->name('verification.send');
});
