<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdvertisementResponseStatus;
use App\Enums\AdvertisementStatus;
use App\Events\NewResponse;
use App\Models\Advertisement;
use App\Models\AdvertisementResponse;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdvertisementResponseAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public static function statuses(): array
    {
        return array_map(fn ($status) => [$status], AdvertisementResponseStatus::cases());
    }

    #[DataProvider('statuses')]
    public function test_duplicates_do_not_modify_an_existing_response_or_its_history(AdvertisementResponseStatus $status): void
    {
        Event::fake([NewResponse::class]);
        $user = User::factory()->create();
        $ad = Advertisement::factory()->create(['status' => AdvertisementStatus::PUBLISHED]);
        $response = AdvertisementResponse::query()->create([
            'advertisement_id' => $ad->id, 'user_id' => $user->id, 'status' => $status,
        ]);
        $conversation = Conversation::query()->create(['advertisement_response_id' => $response->id]);
        Message::query()->create(['conversation_id' => $conversation->id, 'user_id' => $user->id, 'body' => 'Original']);
        $snapshot = $this->businessState();
        $this->actingAs($user)->postJson("/api/advertisements/{$ad->id}/responses", ['message' => 'Duplicate'])
            ->assertUnprocessable()->assertJsonPath('message', 'Вы уже откликались на это объявление.');
        $this->assertSame($snapshot, $this->businessState());
        Event::assertNotDispatched(NewResponse::class);
    }

    public static function unrelatedFailures(): array
    {
        return [['response primary key'], ['message foreign key']];
    }

    #[DataProvider('unrelatedFailures')]
    public function test_unrelated_database_failures_propagate_and_roll_back_all_new_records(string $failure): void
    {
        Event::fake([NewResponse::class]);
        $user = User::factory()->create();
        $ad = Advertisement::factory()->create(['status' => AdvertisementStatus::PUBLISHED]);
        if ($failure === 'response primary key') {
            $existing = AdvertisementResponse::query()->create([
                'advertisement_id' => $ad->id, 'user_id' => User::factory()->create()->id,
                'status' => AdvertisementResponseStatus::SENT,
            ]);
            AdvertisementResponse::creating(function (AdvertisementResponse $response) use ($existing): void {
                $response->id = $existing->id;
            });
        } else {
            Message::creating(function (Message $message): void {
                $message->conversation_id = -1;
            });
        }
        $snapshot = $this->businessState();
        $this->withoutExceptionHandling()->actingAs($user);
        try {
            $this->postJson("/api/advertisements/{$ad->id}/responses", ['message' => 'Must roll back']);
            $this->fail('The unrelated database failure must not become a duplicate response.');
        } catch (QueryException $exception) {
            if ($failure === 'response primary key') {
                $this->assertInstanceOf(UniqueConstraintViolationException::class, $exception);
            } else {
                $this->assertNotInstanceOf(UniqueConstraintViolationException::class, $exception);
            }
        }
        $this->assertSame($snapshot, $this->businessState());
        Event::assertNotDispatched(NewResponse::class);
    }

    private function businessState(): array
    {
        $state = [];
        foreach (['advertisement_responses', 'conversations', 'messages', 'jobs'] as $table) {
            $state[$table] = DB::table($table)->get()->toJson();
        }

        return $state;
    }
}
