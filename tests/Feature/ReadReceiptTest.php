<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReadReceiptTest extends TestCase
{
    use RefreshDatabase;

    public static function participantRoles(): array
    {
        return ['owner' => [true], 'respondent' => [false]];
    }

    #[DataProvider('participantRoles')]
    public function test_receipt_limits_updates_and_retries_preserve_existing_read_times(bool $isOwner): void
    {
        $this->travelTo(now()->startOfSecond());
        $conversation = Conversation::factory()->create();
        $owner = $conversation->advertisementResponse->advertisement->user;
        $respondent = $conversation->advertisementResponse->user;
        [$viewer, $sender] = $isOwner ? [$owner, $respondent] : [$respondent, $owner];
        $attributes = ['conversation_id' => $conversation->id, 'user_id' => $sender->id];
        $alreadyRead = Message::factory()->create([...$attributes, 'read_at' => now()->subHour()]);
        $incoming = Message::factory()->create($attributes);
        $own = Message::factory()->create([...$attributes, 'user_id' => $viewer->id]);
        $other = Conversation::factory()->create();
        $foreign = Message::factory()->create([
            'conversation_id' => $other->id, 'user_id' => $other->advertisementResponse->user_id,
        ]);
        $later = Message::factory()->create($attributes);
        $url = "/api/conversations/{$conversation->id}/read";

        $receipt = $this->actingAs($viewer)->postJson($url, ['through_id' => $own->id])->assertOk()
            ->assertJsonPath('through_id', $own->id);
        $this->assertSame($receipt->json('read_at'), $incoming->fresh()->read_at->toJSON());
        $this->assertTrue($alreadyRead->read_at->equalTo($alreadyRead->fresh()->read_at));
        foreach ([$own, $foreign, $later] as $untouched) {
            $this->assertNull($untouched->fresh()->read_at);
        }
        $this->getJson('/api/conversations/unread-count')->assertOk()->assertJsonPath('count', 1);
        $this->getJson('/api/conversations')->assertOk()->assertJsonPath('conversations.data.0.unread_count', 1);
        $this->actingAs($sender)->getJson("/api/conversations/{$conversation->id}?after_id={$later->id}")
            ->assertOk()->assertJsonPath('messages_meta.read_by_other_through_id', $incoming->id);

        $this->travel(5)->minutes();
        $this->actingAs($viewer)->postJson($url, ['through_id' => $own->id])->assertOk()
            ->assertJsonPath('through_id', $own->id)->assertJsonPath('read_at', now()->toJSON());
        $this->assertSame($receipt->json('read_at'), $incoming->fresh()->read_at->toJSON());
        $this->assertTrue($alreadyRead->read_at->equalTo($alreadyRead->fresh()->read_at));
        foreach ([$own, $foreign, $later] as $untouched) {
            $this->assertNull($untouched->fresh()->read_at);
        }

        // An older repeated boundary does not undo any previously acknowledged reads.
        $this->postJson($url, ['through_id' => $alreadyRead->id])->assertOk()
            ->assertJsonPath('through_id', $alreadyRead->id);
        $this->assertSame($receipt->json('read_at'), $incoming->fresh()->read_at->toJSON());
        $advance = $this->postJson($url, ['through_id' => $later->id])->assertOk()
            ->assertJsonPath('through_id', $later->id);
        $this->assertSame($advance->json('read_at'), $later->fresh()->read_at->toJSON());
        $this->assertNull($own->fresh()->read_at);
        $this->assertNull($foreign->fresh()->read_at);
        $this->getJson('/api/conversations/unread-count')->assertOk()->assertJsonPath('count', 0);
        $this->getJson('/api/conversations')->assertOk()->assertJsonPath('conversations.data.0.has_unread_messages', false);
        $this->actingAs($sender)->getJson("/api/conversations/{$conversation->id}?after_id={$later->id}")
            ->assertOk()->assertJsonPath('messages_meta.read_by_other_through_id', $later->id);
    }

    public static function cursorBounds(): array
    {
        return ['before first' => ['before'], 'foreign gap' => ['gap'], 'beyond last' => ['beyond'], 'numeric string' => ['string']];
    }

    #[DataProvider('cursorBounds')]
    public function test_receipt_confirms_only_an_existing_boundary_in_this_conversation(string $case): void
    {
        $conversation = Conversation::factory()->create();
        $viewer = $conversation->advertisementResponse->user;
        $sender = $conversation->advertisementResponse->advertisement->user;
        $other = Conversation::factory()->create();
        $foreignAttributes = ['conversation_id' => $other->id, 'user_id' => $other->advertisementResponse->user_id];
        $before = Message::factory()->create($foreignAttributes);
        $incoming = Message::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $sender->id]);
        $gap = Message::factory()->create($foreignAttributes);
        $own = Message::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $viewer->id]);
        [$requested, $expected] = match ($case) {
            'before' => [$before->id, null],
            'gap' => [$gap->id, $incoming->id],
            'beyond' => [$own->id + 1000, $own->id],
            'string' => [(string) $own->id, $own->id],
        };
        $url = "/api/conversations/{$conversation->id}/read";
        $this->actingAs($viewer)->postJson($url, ['through_id' => $requested])->assertOk()
            ->assertJsonPath('through_id', $expected);
        $this->assertSame($case === 'before', $incoming->fresh()->read_at === null);
        foreach ([$before, $gap, $own] as $untouched) {
            $this->assertNull($untouched->fresh()->read_at);
        }
        if ($expected !== null) {
            $later = Message::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $sender->id]);
            $this->postJson($url, ['through_id' => $expected])->assertOk()->assertJsonPath('through_id', $expected);
            $this->assertNull($later->fresh()->read_at);
        }
    }

    public function test_receipt_time_matches_the_persisted_timestamp_precision(): void
    {
        $this->travelTo(now()->setMicrosecond(123456));
        $conversation = Conversation::factory()->create();
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $conversation->advertisementResponse->advertisement->user_id,
        ]);
        $receipt = $this->actingAs($conversation->advertisementResponse->user)
            ->postJson("/api/conversations/{$conversation->id}/read", ['through_id' => $message->id])->assertOk();
        $this->assertSame($message->fresh()->read_at->toJSON(), $receipt->json('read_at'));
    }

    public static function defaultBoundaries(): array
    {
        return ['omitted' => [[]], 'null' => [['through_id' => null]], 'empty' => [['through_id' => '']]];
    }

    #[DataProvider('defaultBoundaries')]
    public function test_receipt_without_a_boundary_uses_the_current_conversation_maximum(array $payload): void
    {
        $conversation = Conversation::factory()->create();
        $viewer = $conversation->advertisementResponse->user;
        $incoming = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $conversation->advertisementResponse->advertisement->user_id,
        ]);
        $own = Message::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $viewer->id]);
        $this->actingAs($viewer)->postJson("/api/conversations/{$conversation->id}/read", $payload)
            ->assertOk()->assertJsonPath('through_id', $own->id);
        $this->assertNotNull($incoming->fresh()->read_at);
        $this->assertNull($own->fresh()->read_at);
    }

    public function test_receipt_handles_empty_and_own_message_only_conversations(): void
    {
        $conversation = Conversation::factory()->create();
        $viewer = $conversation->advertisementResponse->user;
        $url = "/api/conversations/{$conversation->id}/read";
        $this->actingAs($viewer);
        foreach ([[], ['through_id' => 1000]] as $payload) {
            $this->postJson($url, $payload)->assertOk()->assertJsonPath('through_id', null);
        }
        $own = Message::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $viewer->id]);
        $this->postJson($url, ['through_id' => $own->id])->assertOk()->assertJsonPath('through_id', $own->id);
        $this->assertNull($own->fresh()->read_at);
        $this->getJson('/api/conversations/unread-count')->assertOk()->assertJsonPath('count', 0);
    }

    public function test_receipt_rejects_unauthorized_or_invalid_requests_without_changing_messages(): void
    {
        $conversation = Conversation::factory()->create();
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $conversation->advertisementResponse->advertisement->user_id,
        ]);
        $url = "/api/conversations/{$conversation->id}/read";
        $this->postJson($url, ['through_id' => $message->id])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson($url, ['through_id' => $message->id])->assertForbidden();
        $this->assertNull($message->fresh()->read_at);
        $this->actingAs($conversation->advertisementResponse->user);
        foreach ([0, -1, 'abc', '1.5', [1]] as $invalid) {
            $this->postJson($url, ['through_id' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('through_id');
            $this->assertNull($message->fresh()->read_at);
        }
    }
}
