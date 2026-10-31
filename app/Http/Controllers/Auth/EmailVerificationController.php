<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function verify(EmailVerificationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        // Compare-and-set also makes concurrent valid clicks emit one event.
        $changed = User::query()->whereKey($user->id)
            ->where('email', $user->email)
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
        if ($changed > 0) {
            event(new Verified($user->refresh()));
        }

        $current = $user->fresh();
        abort_unless($current !== null && $current->email === $user->email && $current->hasVerifiedEmail(), 403);

        return response()->json(['email_verified' => true]);
    }

    public function resend(Request $request, EmailVerificationService $verification): JsonResponse
    {
        $result = $verification->send($request->user());

        return match ($result['status']) {
            'already_verified' => response()->json(null, 204),
            'sent' => response()->json(['status' => 'verification_link_sent'], 202),
            'throttled' => response()->json([
                'message' => 'Слишком много попыток. Повторите запрос позже.',
                'retry_after' => $result['retry_after'],
            ], 429, ['Retry-After' => (string) $result['retry_after']]),
            default => response()->json([
                'message' => 'Не удалось отправить письмо. Попробуйте позже.',
                'code' => 'verification_delivery_failed',
            ], 503),
        };
    }
}
