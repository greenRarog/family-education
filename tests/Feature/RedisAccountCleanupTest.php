<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use App\Services\AccountQueueCleaner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RedisAccountCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_REDIS_TESTS') !== '1') {
            $this->markTestSkipped('Requires explicitly isolated Redis.');
        }
        config(['queue.default' => 'redis', 'queue.connections.redis.queue' => 'cleanup-test',
            'account_deletion.redis_queues' => ['cleanup-test']]);
        Queue::connection()->clear('cleanup-test');
    }

    protected function tearDown(): void
    {
        if (getenv('RUN_REDIS_TESTS') === '1') {
            Queue::connection()->clear('cleanup-test');
        }
        parent::tearDown();
    }

    private function payload(User $recipient, Message $message): string
    {
        $queue = Queue::connection();
        $job = (new SendQueuedNotifications($recipient, new NewMessageNotification($message), ['mail']))->beforeCommit();
        $queue->push($job, '', 'cleanup-test');

        return $queue->getConnection()->rpop('queues:cleanup-test');
    }

    public function test_removes_only_related_payloads_in_all_states_including_moved_attempts(): void
    {
        $chat = Conversation::factory()->create();
        $owner = $chat->advertisementResponse->advertisement->user;
        $respondent = $chat->advertisementResponse->user;
        $message = Message::factory()->create(['conversation_id' => $chat->id, 'user_id' => $owner->id]);
        $otherChat = Conversation::factory()->create();
        $other = $this->payload($otherChat->advertisementResponse->user,
            Message::factory()->create(['conversation_id' => $otherChat->id, 'user_id' => $otherChat->advertisementResponse->advertisement->user_id]));
        $raw = $this->payload($respondent, $message);
        $reserved = json_decode($raw, true);
        $reserved['attempts'] = 1;
        $reserved = json_encode($reserved, JSON_THROW_ON_ERROR);
        $redis = Queue::connection()->getConnection();
        $key = 'queues:cleanup-test';
        $redis->rpush($key, $raw, $other);
        $redis->zadd($key.':delayed', time() + 600, $raw);
        $redis->zadd($key.':delayed', time() + 600, $other);
        $redis->zadd($key.':reserved', time() + 90, $reserved);
        $redis->zadd($key.':reserved', time() + 90, $other);
        app(AccountQueueCleaner::class)->cleanRedis(DB::connection(), $owner->id);
        $this->assertSame([$other], $redis->lrange($key, 0, -1));
        $this->assertSame([$other], $redis->zrange($key.':delayed', 0, -1));
        $this->assertSame([$other], $redis->zrange($key.':reserved', 0, -1));
        // A worker holding an old reserved payload can release it later; next cleanup retries.
        $redis->zadd($key.':delayed', time() + 600, $reserved);
        app(AccountQueueCleaner::class)->cleanRedis(DB::connection(), $owner->id);
        $this->assertSame([$other], $redis->zrange($key.':delayed', 0, -1));
    }

    public function test_rolled_back_deletion_does_not_remove_redis_payload(): void
    {
        $user = User::factory()->family()->create();
        $redis = Queue::connection()->getConnection();
        $key = 'queues:cleanup-test';
        $raw = json_encode(['data' => ['command' => serialize(['moderationUserIds' => [$user->id]])]], JSON_THROW_ON_ERROR);
        $redis->rpush($key, $raw);
        DB::beginTransaction();
        app(AccountQueueCleaner::class)->clean(DB::connection(), $user->id);
        DB::rollBack();
        $this->assertSame([$raw], $redis->lrange($key, 0, -1));
    }
}
