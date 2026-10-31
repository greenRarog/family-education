<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserBlockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserBlockController extends Controller
{
    public function __construct(private readonly UserBlockService $blocks) {}

    public function block(Request $request, User $user): JsonResponse
    {
        return $this->result($this->blocks->block($request->user(), $user, $request->only(['reason_code', 'reason', 'decision_id'])));
    }

    public function unblock(Request $request, User $user): JsonResponse
    {
        return $this->result($this->blocks->unblock($request->user(), $user, $request->only(['reason_code', 'reason', 'decision_id'])));
    }

    /** @param array{user: User, changed: bool, action_id: int|null, expired_advertisements_count: int} $result */
    private function result(array $result): JsonResponse
    {
        $user = $result['user'];
        $decision = $user->blockingAction;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'is_blocked' => $user->isBlocked(),
                'block_action_id' => $decision?->id,
                'blocked_at' => $decision?->occurred_at->toISOString(),
                'blocked_by' => $decision?->actor_id,
                'block_reason_code' => $decision?->reason_code,
                'block_reason' => $decision?->reason,
            ],
            'changed' => $result['changed'],
            'action_id' => $result['action_id'],
            'expired_advertisements_count' => $result['expired_advertisements_count'],
        ]);
    }
}
