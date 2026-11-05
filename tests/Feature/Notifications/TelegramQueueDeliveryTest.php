<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Conversation;
use App\Models\Message;
use App\Notifications\NewMessageNotification;
use App\Services\Telegram\TelegramLinkService;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConfirmedTelegram;
use Tests\TestCase;

class TelegramQueueDeliveryTest extends TestCase
{
    use ConfirmedTelegram;

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
        $connection = getenv('TELEGRAM_TEST_QUEUE') === 'redis' ? 'redis' : 'database';
        if ($connection === 'redis') {
            $this->assertSame('fe-telegram-redis', config('database.redis.default.host'));
            config(['queue.connections.redis.queue' => 'telegram-fixture']);
            app('queue')->connection('redis')->clear('telegram-fixture');
        }
        config(['queue.default' => $connection, 'queue.connections.database.queue' => 'telegram-fixture', 'mail.default' => 'array']);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['ok' => $change !== 'provider-failure', 'result' => ['message_id' => 1]], $change === 'provider-failure' ? 500 : 200)]);
        Event::fake([MessageSent::class]);
        $conversation = Conversation::factory()->create();
        $response = $conversation->advertisementResponse;
        $recipient = $response->advertisement->user;
        $this->confirmTelegramFixture($recipient);
        $recipient->notificationSetting()->create(['email_enabled' => true, 'telegram_enabled' => true]);
        $notification = new NewMessageNotification(Message::factory()->create([
            'conversation_id' => $conversation->id, 'user_id' => $response->user_id,
            'body' => 'Synthetic queue notification *plain* [text]',
        ]));
        if ($change === 'legacy') {
            (function (): void {
                unset($this->telegramVersions);
            })->call($notification);
        }
        $recipient->notify($notification);
        $this->assertSame(2, app('queue')->connection($connection)->size('telegram-fixture'));
        if (in_array($change, ['unlink', 'relink'], true)) {
            app(TelegramLinkService::class)->browser($recipient, 'fixture', 'unlink');
            if ($change === 'relink') {
                // Same chat, new binding generation: the old job must stay revoked.
                $recipient->refresh()->forceFill(['telegram_chat_id' => (string) (700000 + $recipient->id),
                    'telegram_confirmed_at' => now(), 'telegram_bot_id' => '123456', 'telegram_binding_version' => 3])->save();
                $recipient->notificationSetting()->update(['telegram_enabled' => true]);
            }
        } elseif ($change === 'bot-change') {
            config(['services.telegram.token' => '999999:synthetic']);
        } elseif ($change === 'disabled') {
            $recipient->notificationSetting()->update(['telegram_enabled' => false]);
        } elseif ($change === 'deleted') {
            $recipient->forceFill(['account_deleted_at' => now()])->save();
        }
        // Actual database payload serialization, reserve, Laravel worker, channel and HTTP client.
        $this->artisan('queue:work', ['connection' => $connection, '--queue' => 'telegram-fixture', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1, '--memory' => 512])->assertSuccessful();
        $this->assertSame(0, app('queue')->connection($connection)->size('telegram-fixture'));
        Event::assertDispatchedTimes(MessageSent::class, $change === 'deleted' ? 0 : 1);
        Http::assertSentCount(in_array($change, ['unchanged', 'provider-failure'], true) ? 1 : 0);
        if ($change === 'unchanged') {
            Http::assertSent(fn ($request) => $request['chat_id'] === $recipient->telegram_chat_id
                && str_contains($request['text'], '*plain* [text]') && ! isset($request['parse_mode']));
        }
        if ($change === 'provider-failure') {
            $this->assertDatabaseCount('failed_jobs', 1);
            $exception = DB::table('failed_jobs')->value('exception');
            $this->assertStringContainsString('Telegram request failed.', $exception);
            $this->assertStringNotContainsString('123456:synthetic', $exception);
        } else {
            $this->assertDatabaseCount('failed_jobs', 0);
        }
    }
}
