<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountDeletion;
use App\Services\AccountDeletionRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccountDeletionController extends Controller
{
    public function destroy(Request $request, AccountDeletionRequestService $service): JsonResponse
    {
        // Optional expectation from D05, never a target selector: a session may
        // switch accounts in another tab after the form's last auth check.
        if ($request->hasHeader('X-Expected-Account-Id')
            && $request->header('X-Expected-Account-Id') !== (string) $request->user()->id) {
            return response()->json(['code' => 'account_changed', 'message' => 'Аккаунт изменился. Запрос удаления не отправлен.'], 409);
        }
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'request_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'user_id' => ['prohibited'], 'id' => ['prohibited'], 'email' => ['prohibited'],
        ]);
        $deletion = $service->delete($request->user(), $data['current_password'], $data['request_token']);
        // Do not use logout(): its remember-token rotation could write a stale user.
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->result($service->confirm($deletion));
    }

    public function status(Request $request, AccountDeletionRequestService $service): JsonResponse
    {
        $data = $request->validate(['request_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']]);
        $deletion = AccountDeletion::query()->where('request_token_hash', hash('sha256', $data['request_token']))->first();
        abort_if($deletion === null, 404, 'Результат запроса не найден. Это не подтверждает отмену удаления.');

        return $this->result($service->confirm($deletion));
    }

    private function result(AccountDeletion $deletion): JsonResponse
    {
        $confirmed = $deletion->registry_confirmed_at !== null;

        return response()->json([
            'status' => $confirmed ? 'deleted' : 'pending',
            'access_revoked' => true,
            'deleted_at' => $deletion->deleted_at->toISOString(),
        ], $confirmed ? 200 : 202, ['Cache-Control' => 'no-store, private']);
    }
}
