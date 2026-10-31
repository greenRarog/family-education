<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdvertisementStatus;
use App\Models\Advertisement;
use App\Models\AdvertisementResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AdvertisementResponseConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Concurrent inserts require the PostgreSQL test suite.');
        }
        $this->assertSame('family_education_test', config('database.connections.pgsql.database'));
    }

    public static function pairs(): array
    {
        return [
            'same pair' => ['same', [201, 422]],
            'different users' => ['users', [201, 201]],
            'different advertisements' => ['advertisements', [201, 201]],
        ];
    }

    #[DataProvider('pairs')]
    public function test_concurrent_requests_preserve_pair_uniqueness_and_create_only_winner_side_effects(string $pair, array $expectedStatuses): void
    {
        $user = User::factory()->create();
        $ad = Advertisement::factory()->create(['status' => AdvertisementStatus::PUBLISHED]);
        $inputs = [
            ['user_id' => $user->id, 'advertisement_id' => $ad->id, 'message' => 'First worker'],
            [
                'user_id' => $pair === 'users' ? User::factory()->create()->id : $user->id,
                'advertisement_id' => $pair === 'advertisements'
                    ? Advertisement::factory()->create(['status' => AdvertisementStatus::PUBLISHED])->id : $ad->id,
                'message' => 'Second worker',
            ],
        ];
        $this->assertSame(0, DB::transactionLevel(), 'Fixtures must be committed before starting workers.');
        $barrier = sys_get_temp_dir().'/response-race-'.bin2hex(random_bytes(12));
        File::makeDirectory($barrier);
        $workers = [];

        try {
            foreach ($inputs as $index => $input) {
                $workers[$index] = new Process([
                    PHP_BINARY, base_path('tests/Support/response-race-worker.php'),
                    json_encode($input + ['barrier' => $barrier, 'worker' => $index], JSON_THROW_ON_ERROR),
                ], base_path(), $this->workerEnvironment(), timeout: 20);
                $workers[$index]->start();
            }
            $deadline = microtime(true) + 10;
            while (! is_file($barrier.'/ready-0') || ! is_file($barrier.'/ready-1')) {
                foreach ($workers as $worker) {
                    if (! $worker->isRunning()) {
                        $this->fail($worker->getOutput().$worker->getErrorOutput());
                    }
                }
                if (microtime(true) >= $deadline) {
                    $this->fail('Both workers must pass precheck before either inserts.');
                }
                usleep(10000);
            }
            file_put_contents($barrier.'/release', 'go');

            $results = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getOutput().$worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame($expectedStatuses, $statuses, json_encode($results, JSON_THROW_ON_ERROR));
            $winners = count(array_filter($results, fn ($result) => $result['status'] === 201));
            foreach (['advertisement_responses', 'conversations', 'messages'] as $table) {
                $this->assertDatabaseCount($table, $winners);
            }
            $this->assertDatabaseCount('jobs', 0);
            $this->assertSame($winners, array_sum(array_column($results, 'notifications')));
            $this->assertSame($winners, array_sum(array_map(fn ($result) => count($result['events']), $results)));

            foreach ($results as $index => $result) {
                $this->assertTrue($result['reached_lock']);
                $this->assertSame('application/json', $result['content_type']);
                $this->assertSame(0, $result['transaction_level']);
                $this->assertSame(1, $result['connection_healthy']);
                if ($result['status'] === 422) {
                    $this->assertSame('Вы уже откликались на это объявление.', $result['body']['message']);
                    $this->assertSame([], $result['events']);
                    $this->assertSame(0, $result['notifications']);

                    continue;
                }
                $response = AdvertisementResponse::query()->findOrFail($result['body']['response']['id']);
                $this->assertSame($inputs[$index]['user_id'], $response->user_id);
                $this->assertSame($inputs[$index]['advertisement_id'], $response->advertisement_id);
                $this->assertSame($response->conversation->id, $result['body']['response']['conversation_id']);
                $this->assertDatabaseHas('messages', [
                    'conversation_id' => $response->conversation->id, 'user_id' => $response->user_id,
                    'body' => $inputs[$index]['message'],
                ]);
                $this->assertSame([$response->id], $result['events']);
            }
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(0);
                }
            }
            File::deleteDirectory($barrier);
        }
    }

    private function workerEnvironment(): array
    {
        $connection = config('database.connections.pgsql');

        return [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'),
            'APP_CONFIG_CACHE' => sys_get_temp_dir().'/response-race-no-config.php',
            'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'family_education_test', 'DB_URL' => '',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null',
        ];
    }
}
