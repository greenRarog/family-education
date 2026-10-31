<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api;

use App\Enums\AdvertisementStatus;
use App\Enums\AdvertisementType;
use App\Enums\ReportContextType;
use App\Enums\ReportReason;
use App\Enums\ReportTargetType;
use App\Enums\TeacherProfileVisibilityStatus;
use App\Enums\UserType;
use App\Exceptions\EmailVerificationRequired;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PublicAdvertisementResource;
use App\Models\Advertisement;
use App\Models\Conversation;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // Authentication and the independent attempt budget run before these checks.
        $actor = $request->user();
        if ($actor->isBlocked()) {
            return response()->json(['code' => 'user_blocked'], 403);
        }
        abort_unless(in_array($actor->user_type, [UserType::FAMILY, UserType::TEACHER], true), 403);
        if (! $actor->hasVerifiedEmail()) {
            throw new EmailVerificationRequired;
        }
        $data = $request->validate([
            'target_type' => ['required', Rule::enum(ReportTargetType::class)],
            'context_type' => ['required', Rule::enum(ReportContextType::class)],
            'context_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', Rule::enum(ReportReason::class), Rule::notIn($request->input('target_type') === 'user' ? ['off_topic'] : [])],
            'comment' => ['nullable', 'string', 'max:2000', Rule::requiredIf($request->input('reason') === 'other'), $request->input('reason') === 'other' ? 'min:10' : 'min:0'],
        ]);
        $conversation = null;
        $advertisement = null;
        $targetUser = null;
        if ($data['context_type'] === 'conversation') {
            $conversation = Conversation::query()->findOrFail($data['context_id']);
            abort_unless($conversation->hasParticipant($actor), 404);
            $response = $conversation->advertisementResponse;
            $advertisement = $response->advertisement;
            $targetUser = User::query()->findOrFail($actor->id === $response->user_id ? $advertisement->user_id : $response->user_id);
        } elseif ($data['context_type'] === 'advertisement') {
            abort_unless($data['target_type'] === 'advertisement', 422);
            $advertisement = Advertisement::query()->findOrFail($data['context_id']);
        } else {
            abort_unless($data['target_type'] === 'user', 422);
            $targetUser = User::query()->findOrFail($data['context_id']);
        }

        return DB::transaction(function () use ($request, $actor, $data, $conversation, $advertisement, $targetUser): JsonResponse {
            // All writes affecting visibility use the same ordered user locks.
            $ids = array_filter([$actor->id, $targetUser?->id, $advertisement?->user_id]);
            $users = User::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $users->get($actor->id);
            abort_if($actor === null, 401);
            if ($actor->isBlocked()) {
                return response()->json(['code' => 'user_blocked'], 403);
            }
            abort_unless(in_array($actor->user_type, [UserType::FAMILY, UserType::TEACHER], true), 403);
            if (! $actor->hasVerifiedEmail()) {
                throw new EmailVerificationRequired;
            }
            $snapshot = [];
            if ($data['target_type'] === 'advertisement') {
                abort_if($advertisement === null, 404);
                $advertisement = Advertisement::query()->whereKey($advertisement->id)->lockForUpdate()->firstOrFail();
                abort_if($advertisement->user_id === $actor->id, 422, 'Нельзя пожаловаться на своё объявление.');
                abort_if($advertisement->isHidden() || $users->get($advertisement->user_id)?->isBlocked(), 404);
                abort_unless($advertisement->status === AdvertisementStatus::PUBLISHED
                    || ($advertisement->status === AdvertisementStatus::CLOSED && ($conversation !== null
                        || $advertisement->responses()->where('user_id', $actor->id)->whereHas('conversation')->exists())), 404);
                // Match the public show: the resource expects this explicit projection.
                // Loading the full owner/profile into the snapshot would expose private data.
                $owner = $users->get($advertisement->user_id);
                $profile = $advertisement->type === AdvertisementType::TEACHER_TO_SERVICE ? $owner?->teacherProfile : null;
                if ($profile !== null && $profile->public_profile_requested
                    && $profile->public_profile_status === TeacherProfileVisibilityStatus::APPROVED) {
                    $advertisement->setAttribute('public_teacher', [
                        'id' => $owner->id, 'name' => $owner->name, 'surname' => $profile->surname,
                        'city' => $profile->city, 'subjects' => $profile->subjects,
                    ]);
                }
                $snapshot['advertisement'] = (new PublicAdvertisementResource($advertisement))->resolve($request);
                $targetId = $advertisement->id;
            } else {
                abort_if($targetUser === null, 404);
                $targetUser = $users->get($targetUser->id);
                abort_if($targetUser === null, 404);
                abort_if($targetUser->id === $actor->id, 422, 'Нельзя пожаловаться на себя.');
                $targetId = $targetUser->id;
                if ($conversation === null) {
                    $profile = $targetUser->teacherProfile;
                    abort_unless($targetUser->user_type === UserType::TEACHER && ! $targetUser->isBlocked()
                        && $profile !== null && $profile->public_profile_requested
                        && $profile->public_profile_status === TeacherProfileVisibilityStatus::APPROVED, 404);
                    $snapshot['teacher'] = [
                        'id' => $targetUser->id, 'name' => $targetUser->name, 'surname' => $profile->surname,
                        'city' => $profile->city->only(['id', 'name']),
                        'subjects' => $profile->subjects->map->only(['id', 'name'])->all(),
                    ];
                }
            }
            if ($conversation !== null) {
                $snapshot['conversation'] = [
                    'id' => $conversation->id, 'advertisement_response_id' => $conversation->advertisement_response_id,
                    'participants' => $users->map(fn (User $user) => $user->only(['id', 'name']))->values()->all(),
                ];
            }
            // The actor lock serializes submissions with block and duplicate requests;
            // the partial unique index remains the final storage guarantee.
            if (Report::query()->where('reporter_id', $actor->id)->where('target_type', $data['target_type'])
                ->where('target_id', $targetId)->where('status', 'open')->exists()) {
                return response()->json(['code' => 'report_already_open', 'message' => 'Жалоба уже принята.'], 422);
            }
            $report = Report::query()->create([
                'reporter_id' => $actor->id, 'target_type' => $data['target_type'], 'target_id' => $targetId,
                'context_type' => $data['context_type'], 'context_id' => $data['context_id'],
                'reason' => $data['reason'], 'comment' => $data['comment'] ?? null, 'snapshot' => $snapshot,
            ]);

            return response()->json(['report' => ['id' => $report->id, 'status' => 'open']], 201);
        });
    }
}
