<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Telegram\BotApi;
use App\Services\Telegram\BotConfiguration;
use App\Services\Telegram\TelegramLinkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class PollTelegram extends Command
{
    protected $signature = 'telegram:poll {--once : Process one bounded batch}';

    protected $description = 'Receive Telegram linking updates using outbound long polling';

    public function handle(BotApi $bot, TelegramLinkService $links): int
    {
        if (! BotConfiguration::available()) {
            $this->error('Telegram is not configured/enabled.');

            return self::FAILURE;
        }
        // PostgreSQL session lock survives arbitrarily slow DB waits; a cache lease alone
        // is insufficient for exclusive cursor ownership. SQLite is a test-only transport.
        $postgres = DB::connection()->getDriverName() === 'pgsql';
        if (! $postgres && ! app()->environment('testing')) {
            $this->error('The poller requires PostgreSQL.');

            return self::FAILURE;
        }
        $lockKey = 'family-education:telegram:'.BotConfiguration::id();
        if ($postgres && ! DB::selectOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS acquired', [$lockKey])->acquired) {
            $this->error('Another poller owns the PostgreSQL bot lock.');

            return self::FAILURE;
        }
        try {
            return $this->poll($bot, $links);
        } finally {
            if ($postgres) {
                DB::select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$lockKey]);
            }
        }
    }

    private function poll(BotApi $bot, TelegramLinkService $links): int
    {
        do {
            // Bounded: identity/webhook (2x5s), poll (35s), max 10 sends (10x5s).
            $lock = Cache::lock('telegram-poller:'.BotConfiguration::id(), 120);
            if (! $lock->get()) {
                $this->error('Another poller holds the bot lock.');

                return self::FAILURE;
            }
            try {
                $identity = $bot->call('getMe');
                if ((string) ($identity['id'] ?? '') !== BotConfiguration::id()
                    || strcasecmp($identity['username'] ?? '', config('services.telegram.username')) !== 0) {
                    $this->error('Bot identity does not match configuration.');

                    return self::FAILURE;
                }
                if (($bot->call('getWebhookInfo')['url'] ?? null) !== '') {
                    $this->error('Webhook is set or could not be verified. No changes made.');

                    return self::FAILURE;
                }
                $id = BotConfiguration::id();
                DB::table('telegram_polling_cursors')->insertOrIgnore(['bot_id' => $id, 'next_update_id' => 0]);
                $offset = (int) DB::table('telegram_polling_cursors')->where('bot_id', $id)->value('next_update_id');
                // Telegram retains updates for at most 24 h and may randomize IDs after
                // a week idle. Request all unacknowledged updates after a long pause;
                // offset=0 does not drop updates (unlike negative offsets).
                if (! DB::table('telegram_processed_updates')->where('bot_id', $id)
                    ->where('received_at', '>', now()->subDay())->exists()) {
                    $offset = 0;
                }
                $updates = $bot->call('getUpdates', ['offset' => $offset, 'limit' => 10, 'timeout' => 25, 'allowed_updates' => ['message']]);
                if (! is_array($updates)) {
                    throw new \RuntimeException('Invalid updates response.');
                }
                foreach ($updates as $update) {
                    if (! is_array($update) || ! is_int($update['update_id'] ?? null) || $update['update_id'] < 0) {
                        throw new \RuntimeException('Invalid update identifier.');
                    }
                    $links->update($update, $bot);
                    // Durable handling precedes cursor advancement. Retries never issue a new code.
                    // Telegram may choose a lower random update_id after a week without updates.
                    DB::table('telegram_polling_cursors')->where('bot_id', $id)->update(['next_update_id' => $update['update_id'] + 1]);
                }
            } catch (Throwable) {
                $this->error('Telegram polling failed. No provider details logged; retry manually.');

                return self::FAILURE;
            } finally {
                $lock->release();
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
