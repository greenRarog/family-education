<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Conversation;
use App\Models\Message;
use App\Notifications\NewMessageNotification;
use App\Services\Max\MaxLinkService;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConfirmedMax;
use Tests\TestCase;

class MaxQueueDeliveryTest extends TestCase
{
    use ConfirmedMax;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') === 'pgsql') {
            $this->assertSame('family_education_test', config('database.connections.pgsql.database'));
        } else {
            $this->assertSame('sqlite', config('database.default'));
            $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    public static function changes(): array
    {
        return array_map(fn ($value) => [$value], ['unchanged', 'unlink', 'relink', 'bot-change', 'legacy', 'disabled', 'deleted', 'provider-failure']);
    }

    #[DataProvider('changes')]
    public function test_database_queue_worker_rechecks_binding_and_email_remains_independent(string $change): void
    {
        $connection = getenv('MAX_TEST_QUEUE') === 'redis' ? 'redis' : 'database';
        if ($connection === 'redis') {
            $this->assertSame('1', getenv('RUN_REDIS_TESTS'));
            config(['account_deletion.redis_queues' => ['max-fixture']]);
            config(['queue.connections.redis.queue' => 'max-fixture']);
            app('queue')->connection('redis')->clear('max-fixture');
        }
        config(['queue.default' => $connection, 'queue.connections.database.queue' => 'max-fixture', 'mail.default' => 'array']);
        Http::preventStrayRequests();
        Http::fake(['*/me' => Http::response(['user_id' => 123456, 'username' => 'education_test_bot', 'is_bot' => true]), '*' => Http::response(['message' => ['body' => ['mid' => '1']]], $change === 'provider-failure' ? 500 : 200)]);
        Event::fake([MessageSent::class]);
        $conversation = Conversation::factory()->create();
        $response = $conversation->advertisementResponse;
        $recipient = $response->advertisement->user;
        $this->confirmMaxFixture($recipient);
        $recipient->notificationSetting()->create(['email_enabled' => true, 'max_enabled' => true]);
        $notification = new NewMessageNotification(Message::factory()->create([
            'conversation_id' => $conversation->id, 'user_id' => $response->user_id,
            'body' => 'Synthetic queue notification *plain* [text]',
        ]));
        if ($change === 'legacy') {
            (function (): void {
                unset($this->maxVersions);
            })->call($notification);
        }
        $recipient->notify($notification);
        $this->assertSame(2, app('queue')->connection($connection)->size('max-fixture'));
        if (in_array($change, ['unlink', 'relink'], true)) {
            app(MaxLinkService::class)->browser($recipient, 'fixture', 'unlink');
            if ($change === 'relink') {
                // Same chat, new binding generation: the old job must stay revoked.
                $recipient->refresh()->forceFill(['max_user_id' => (string) (700000 + $recipient->id),
                    'max_confirmed_at' => now(), 'max_bot_id' => '123456', 'max_binding_version' => 3])->save();
                $recipient->notificationSetting()->update(['max_enabled' => true]);
            }
        } elseif ($change === 'bot-change') {
            config(['services.max.bot_id' => '999999']);
        } elseif ($change === 'disabled') {
            $recipient->notificationSetting()->update(['max_enabled' => false]);
        } elseif ($change === 'deleted') {
            $recipient->forceFill(['account_deleted_at' => now()])->save();
        }
        // Actual database payload serialization, reserve, Laravel worker, channel and HTTP client.
        $this->artisan('queue:work', ['connection' => $connection, '--queue' => 'max-fixture', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1, '--memory' => 512])->assertSuccessful();
        $this->assertSame(0, app('queue')->connection($connection)->size('max-fixture'));
        Event::assertDispatchedTimes(MessageSent::class, $change === 'deleted' ? 0 : 1);
        Http::assertSentCount(in_array($change, ['unchanged', 'provider-failure'], true) ? 2 : 0);
        if ($change === 'unchanged') {
            Http::assertSent(fn ($request) => str_contains($request->url(), 'messages?user_id='.$recipient->max_user_id)
                && str_contains($request['text'], '*plain* [text]') && ! isset($request['format']));
        }
        if ($change === 'provider-failure') {
            $this->assertDatabaseCount('failed_jobs', 1);
            $exception = DB::table('failed_jobs')->value('exception');
            $this->assertStringContainsString('MAX request failed.', $exception);
            $this->assertStringNotContainsString('synthetic-token', $exception);
        } else {
            $this->assertDatabaseCount('failed_jobs', 0);
        }
    }
}
