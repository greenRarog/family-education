<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Advertisement;
use App\Models\AdvertisementResponse;
use App\Models\Conversation;
use App\Models\TeacherProfile;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Serialize business writes with UserBlockService, including both participants. */
class EnforceModeration
{
    public function handle(Request $request, Closure $next): Response
    {
        return DB::transaction(function () use ($request, $next): Response {
            $actorId = $request->user()->id;
            $ids = [$actorId];
            $authorized = true;
            $advertisement = $request->route('advertisement');
            $response = $request->route('advertisementResponse');
            $conversation = $request->route('conversation');
            $profile = $request->route('teacherProfile');
            if ($conversation instanceof Conversation) {
                $authorized = $conversation->hasParticipant($request->user());
                $response = $conversation->advertisementResponse;
            }
            if ($response instanceof AdvertisementResponse) {
                if (! $conversation instanceof Conversation) {
                    $authorized = $response->advertisement->user_id === $actorId;
                }
                $ids[] = $response->user_id;
                $ids[] = $response->advertisement->user_id;
            }
            if ($advertisement instanceof Advertisement) {
                $ids[] = $advertisement->user_id;
            }
            if ($profile instanceof TeacherProfile) {
                $ids[] = $profile->user_id;
            }
            // Stable ordering prevents two opposite-direction requests deadlocking.
            $users = User::query()->whereIn('id', array_unique($ids))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $users->get($actorId);
            abort_if($actor === null, 401);
            if ($actor->isBlocked()) {
                return response()->json(['code' => 'user_blocked', 'message' => 'Аккаунт заблокирован. Действие недоступно.'], 403);
            }
            $request->setUserResolver(fn () => $actor);
            // Route binding happened before waiting for locks: discard stale state.
            foreach ([$advertisement, $response, $conversation, $profile] as $model) {
                if ($model instanceof Model) {
                    $model->refresh();
                }
            }
            if ($authorized && $response instanceof AdvertisementResponse && ! $request->is('*/reject')) {
                if ($users->get($response->user_id)?->isBlocked() || $users->get($response->advertisement->user_id)?->isBlocked()) {
                    return response()->json(['code' => 'conversation_paused', 'message' => 'Общение приостановлено.'], 403);
                }
            }
            if ($advertisement instanceof Advertisement && $request->is('*/responses')) {
                abort_if($advertisement->isHidden() || $users->get($advertisement->user_id)?->isBlocked(), 404);
            }
            if ($profile instanceof TeacherProfile && $request->is('*/approve') && $users->get($profile->user_id)?->isBlocked()) {
                return response()->json(['code' => 'profile_owner_blocked', 'message' => 'Владелец профиля заблокирован.'], 403);
            }

            $result = $next($request);
            // The routing pipeline renders exceptions into responses. Roll back
            // even when a downstream exception has already been rendered.
            if ($result->getStatusCode() >= 400) {
                throw new HttpResponseException($result);
            }

            return $result;
        });
    }
}
