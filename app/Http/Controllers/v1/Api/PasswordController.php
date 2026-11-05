<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api;

use App\Actions\Fortify\UpdateUserPassword;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

class PasswordController extends Controller
{
    public function update(Request $request, UpdateUserPassword $updater): JsonResponse
    {
        $user = $request->user();
        if ($request->hasHeader('X-Expected-Account-Id') && $request->header('X-Expected-Account-Id') !== (string) $user->id) {
            return response()->json(['code' => 'account_changed', 'message' => 'Аккаунт изменился. Пароль не изменён.'], 409);
        }
        $updater->update($user, $request->all());
        Auth::guard('web')->setUser($user);
        $request->session()->put('account_session_version', ['id' => $user->id, 'version' => $user->session_version]);
        $request->session()->regenerate(true);
        Cookie::queue(Cookie::forget(Auth::guard('web')->getRecallerName(), config('session.path'), config('session.domain')));

        return response()->json(['status' => 'password_changed', 'other_sessions_revoked' => true])
            ->header('Cache-Control', 'no-store, private');
    }
}
