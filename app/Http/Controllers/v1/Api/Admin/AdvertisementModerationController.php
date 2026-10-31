<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api\Admin;

use App\Enums\AdvertisementStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PublicAdvertisementResource;
use App\Models\AdminAction;
use App\Models\Advertisement;
use App\Services\AdvertisementModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdvertisementModerationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'state' => ['sometimes', Rule::in(['all', 'hidden', 'visible'])],
            'status' => ['sometimes', Rule::in(['all', ...array_column(AdvertisementStatus::cases(), 'value')])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = Advertisement::query()->with(['city', 'district', 'metroStation', 'subjects', 'hidingAction'])->orderByDesc('id');
        if (($data['state'] ?? 'all') === 'hidden') {
            $query->whereNotNull('hide_action_id');
        } elseif (($data['state'] ?? 'all') === 'visible') {
            $query->whereNull('hide_action_id');
        }
        if (($data['status'] ?? 'all') !== 'all') {
            $query->where('status', $data['status']);
        }

        return response()->json($query->paginate(20)->through(fn (Advertisement $ad) => $this->card($request, $ad)));
    }

    public function show(Request $request, Advertisement $advertisement): JsonResponse
    {
        return response()->json(['advertisement' => $this->card($request, $advertisement)]);
    }

    public function history(Request $request, Advertisement $advertisement): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json(AdminAction::query()->where('target_type', 'advertisement')->where('target_id', $advertisement->id)->orderByDesc('id')->paginate(20));
    }

    public function rejectAppeal(Request $request, Advertisement $advertisement, AdvertisementModerationService $service): JsonResponse
    {
        return response()->json($service->rejectAppeal($request->user(), $advertisement, $request->only(['decision_id', 'reason'])));
    }

    /** @return array<string, mixed> */
    private function card(Request $request, Advertisement $advertisement): array
    {
        return array_merge((new PublicAdvertisementResource($advertisement))->resolve($request), [
            'owner_id' => $advertisement->user_id,
            'moderation' => $advertisement->moderationDetails(),
        ]);
    }

    public function hide(Request $request, Advertisement $advertisement, AdvertisementModerationService $service): JsonResponse
    {
        return $this->result($service->change($request->user(), $advertisement, true, $request->only(['reason_code', 'reason', 'decision_id'])));
    }

    public function restore(Request $request, Advertisement $advertisement, AdvertisementModerationService $service): JsonResponse
    {
        return $this->result($service->change($request->user(), $advertisement, false, $request->only(['reason_code', 'reason', 'decision_id'])));
    }

    /** @param array{advertisement: Advertisement, changed: bool, action_id: int|null} $result */
    private function result(array $result): JsonResponse
    {
        $ad = $result['advertisement'];

        return response()->json([
            'advertisement' => ['id' => $ad->id, 'status' => $ad->status->value, 'moderation' => $ad->moderationDetails()],
            'changed' => $result['changed'], 'action_id' => $result['action_id'],
        ]);
    }
}
