<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Advertisement;
use App\Models\AdvertisementResponse;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;
use UnexpectedValueException;

/** Inspect Laravel payloads as data: never instantiate a queued class or execute a job. */
class AccountQueueCleaner
{
    public function clean(Connection $db, int $userId): void
    {
        foreach (['jobs', 'failed_jobs'] as $table) {
            $db->table($table)->orderBy('id')->chunkById(100, function ($rows) use ($db, $table, $userId): void {
                foreach ($rows as $row) {
                    if ($this->payloadReferences($row->payload, $db, $userId)) {
                        $db->table($table)->where('id', $row->id)->delete();
                    }
                }
            });
        }
        if (config('queue.connections.'.config('queue.default').'.driver') === 'redis') {
            if ($db->transactionLevel() > 0) {
                $db->afterCommit(function () use ($db, $userId): void {
                    try {
                        $this->cleanRedis($db, $userId);
                    } catch (Throwable) {
                        Log::warning('Redis account cleanup pending.', ['user_id' => $userId]);
                    }
                });
            } else {
                $this->cleanRedis($db, $userId);
            }
        }
    }

    public function payloadReferences(string $raw, Connection $db, int $userId): bool
    {
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $command = $payload['data']['command'] ?? null;
        if (! is_string($command)) {
            return false;
        }
        $data = @unserialize($command, ['allowed_classes' => false, 'max_depth' => 128]);
        if ($data === false) {
            throw new UnexpectedValueException('Cannot safely inspect queue payload for account cleanup.');
        }

        return $this->references($data, $db, $userId);
    }

    /** Removal follows a committed deletion; the hourly purge retries failures/late releases. */
    public function cleanRedis(Connection $db, int $userId): void
    {
        $queue = Queue::connection();
        if (! $queue instanceof RedisQueue) {
            return;
        }
        /** @var PhpRedisConnection $redis Laravel's phpredis wrapper adapts eval arguments. */
        $redis = $queue->getConnection();
        foreach ($this->redisQueueKeys($queue) as $key) {
            $payloads = [...$redis->lrange($key, 0, -1), ...$redis->zrange($key.':delayed', 0, -1),
                ...$redis->zrange($key.':reserved', 0, -1)];
            foreach ($payloads as $raw) {
                if (! $this->payloadReferences($raw, $db, $userId)) {
                    continue;
                }
                $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                // Match UUID atomically across all states: pop/release can change attempts and move a job.
                $redis->eval(<<<'LUA'
local function matches(raw)
    if raw == ARGV[1] then return true end
    if ARGV[2] == '' then return false end
    local ok, data = pcall(cjson.decode, raw)
    return ok and type(data) == 'table' and data.uuid == ARGV[2]
end
for _, raw in ipairs(redis.call('LRANGE', KEYS[1], 0, -1)) do
    if matches(raw) then redis.call('LREM', KEYS[1], 0, raw) end
end
for i = 2, 3 do
    for _, raw in ipairs(redis.call('ZRANGE', KEYS[i], 0, -1)) do
        if matches(raw) then redis.call('ZREM', KEYS[i], raw) end
    end
end
return 1
LUA, 3, $key, $key.':delayed', $key.':reserved', $raw, $payload['uuid'] ?? '');
            }
        }
    }

    /** Closed restore only: no old job, including unrelated ones, may be delivered again. */
    public function clearRestoredRedisQueues(): void
    {
        $queue = Queue::connection();
        if ($queue instanceof RedisQueue) {
            foreach ($this->redisQueueKeys($queue) as $key) {
                $queue->getConnection()->del($key, $key.':delayed', $key.':reserved', $key.':notify');
            }
        }
    }

    /** @return list<string> */
    private function redisQueueKeys(RedisQueue $queue): array
    {
        $names = array_unique([config('queue.connections.'.config('queue.default').'.queue', 'default'),
            ...config('account_deletion.redis_queues', [])]);

        return array_map(function (string $name) use ($queue): string {
            if ($name === '' || trim($name) !== $name) {
                throw new UnexpectedValueException('Invalid account cleanup queue configuration.');
            }
            if ($queue->getConnection()->isCluster() && ! preg_match('/\{[^}]+\}/', $name)) {
                $name = '{'.$name.'}';
            }

            return $queue->getQueue($name);
        }, array_values($names));
    }

    private function references(mixed $value, Connection $db, int $userId, int $depth = 0): bool
    {
        if (! is_array($value) && ! is_object($value)) {
            return false;
        }
        if ($depth > 64) {
            throw new UnexpectedValueException('Queue payload nesting exceeds account cleanup limits.');
        }
        $fields = [];
        foreach ((array) $value as $key => $field) {
            $parts = explode("\0", (string) $key);
            $fields[end($parts)] = $field;
        }
        if (isset($fields['moderationUserIds']) && in_array($userId, $fields['moderationUserIds'], true)) {
            return true;
        }
        // SerializesModels stores ModelIdentifier, including old pre-moderation jobs.
        $class = $fields['class'] ?? null;
        $ids = (array) ($fields['id'] ?? []);
        if ($class === User::class && in_array($userId, $ids, true)) {
            return true;
        }
        if ($class === Advertisement::class && $db->table('advertisements')->whereIn('id', $ids)->where('user_id', $userId)->exists()) {
            return true;
        }
        if ($class === AdvertisementResponse::class && $db->table('advertisement_responses as r')
            ->join('advertisements as a', 'a.id', '=', 'r.advertisement_id')->whereIn('r.id', $ids)
            ->where(fn ($q) => $q->where('r.user_id', $userId)->orWhere('a.user_id', $userId))->exists()) {
            return true;
        }
        if ($class === Message::class && $db->table('messages as m')
            ->join('conversations as c', 'c.id', '=', 'm.conversation_id')
            ->join('advertisement_responses as r', 'r.id', '=', 'c.advertisement_response_id')
            ->join('advertisements as a', 'a.id', '=', 'r.advertisement_id')->whereIn('m.id', $ids)
            ->where(fn ($q) => $q->where('m.user_id', $userId)->orWhere('r.user_id', $userId)->orWhere('a.user_id', $userId))->exists()) {
            return true;
        }
        foreach ($fields as $field) {
            if ($this->references($field, $db, $userId, $depth + 1)) {
                return true;
            }
        }

        return false;
    }
}
