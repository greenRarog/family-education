<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Conversation;
use App\Models\User;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Personal writes not governed by moderation (also allowed for blocked accounts). */
class SerializeAccountAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        return DB::transaction(function () use ($request, $next): Response {
            $ids = [$request->user()->id];
            $conversation = $request->route('conversation');
            if ($conversation instanceof Conversation) {
                abort_unless($conversation->hasParticipant($request->user()), 403);
                $ids[] = $conversation->advertisementResponse->user_id;
                $ids[] = $conversation->advertisementResponse->advertisement->user_id;
            }
            $users = User::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $users->get($request->user()->id);
            abort_if($actor === null || $actor->isAccountDeleted(), 401);
            $request->setUserResolver(fn () => $actor);
            if ($conversation instanceof Conversation) {
                $conversation->refresh();
                abort_if($conversation->account_deleted_at !== null, 403);
            }
            $result = $next($request);
            if ($result->getStatusCode() >= 400) {
                throw new HttpResponseException($result);
            }

            return $result;
        });
    }
}
