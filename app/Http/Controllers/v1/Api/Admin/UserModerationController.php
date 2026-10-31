<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\User;
use App\Services\UserBlockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserModerationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['state' => ['sometimes', Rule::in(['all', 'active', 'blocked'])], 'page' => ['sometimes', 'integer', 'min:1']]);
        $query = User::query()->with('blockingAction')->orderBy('id');
        if (($data['state'] ?? 'all') === 'blocked') {
            $query->whereNotNull('block_action_id');
        } elseif (($data['state'] ?? 'all') === 'active') {
            $query->whereNull('block_action_id');
        }

        return response()->json($query->paginate(20)->through(fn (User $user) => $this->card($user)));
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['user' => $this->card($user)]);
    }

    public function history(Request $request, User $user): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json(AdminAction::query()->where('target_type', 'user')->where('target_id', $user->id)->orderByDesc('id')->paginate(20));
    }

    public function rejectAppeal(Request $request, User $user, UserBlockService $blocks): JsonResponse
    {
        return response()->json($blocks->rejectAppeal($request->user(), $user, $request->only(['decision_id', 'reason'])));
    }

    /** @return array<string, mixed> */
    private function card(User $user): array
    {
        $decision = $user->blockingAction;

        return [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'user_type' => $user->user_type->value, 'is_blocked' => $user->isBlocked(),
            'block_action_id' => $decision?->id, 'blocked_at' => $decision?->occurred_at->toISOString(),
            'blocked_by' => $decision?->actor_id, 'block_reason_code' => $decision?->reason_code,
            'block_reason' => $decision?->reason,
        ];
    }
}
