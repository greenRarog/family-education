<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdvertisementStatus;
use App\Events\NewMessage;
use App\Events\NewResponse;
use App\Models\Advertisement;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResponseLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public static function transitionCases(): array
    {
        $cases = [
            'owner accepts sent' => ['owner', 'sent', 'accept', 200, 'accepted'],
            'owner rejects sent' => ['owner', 'sent', 'reject', 200, 'rejected'],
            'owner cannot accept accepted' => ['owner', 'accepted', 'accept', 422, 'accepted'],
            'owner terminates accepted' => ['owner', 'accepted', 'reject', 200, 'rejected'],
            'owner cannot reopen rejected' => ['owner', 'rejected', 'accept', 422, 'rejected'],
            'owner cannot reject rejected again' => ['owner', 'rejected', 'reject', 422, 'rejected'],
        ];
        foreach (['respondent' => 403, 'stranger' => 403, 'guest' => 401] as $role => $httpStatus) {
            foreach (['sent', 'accepted', 'rejected'] as $status) {
                foreach (['accept', 'reject'] as $action) {
                    $cases["{$role} {$action} {$status}"] = [$role, $status, $action, $httpStatus, $status];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('transitionCases')]
    public function test_transition_matrix_preserves_history_and_rejects_unauthorized_changes(string $role, string $status, string $action, int $httpStatus, string $expectedStatus): void
    {
        $this->travelTo(now()->startOfSecond());
        $conversation = Conversation::factory()->create();
        $response = $conversation->advertisementResponse;
        $response->update(['status' => $status]);
        $message = Message::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $response->user_id]);
        $before = $response->fresh()->updated_at->toJSON();
        $viewer = match ($role) {
            'owner' => $response->advertisement->user,
            'respondent' => $response->user,
            'stranger' => User::factory()->create(),
            'guest' => null,
        };
        if ($viewer !== null) {
            $this->actingAs($viewer);
        }
        $this->travel(1)->minute();
        $result = $this->postJson("/api/advertisement-responses/{$response->id}/{$action}")->assertStatus($httpStatus);
        $this->assertSame($expectedStatus, $response->fresh()->status->value);
        if ($httpStatus === 200) {
            $result->assertJsonPath('response.id', $response->id)
                ->assertJsonPath('response.status', $expectedStatus)
                ->assertJsonPath('response.conversation_id', $conversation->id)
                ->assertJsonPath('response.user.id', $response->user_id);
        } else {
            $this->assertSame($before, $response->fresh()->updated_at->toJSON());
        }
        $this->assertDatabaseCount('advertisement_responses', 1);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertEquals($message->toArray(), $message->fresh()->toArray());
    }

    public static function sendingCases(): array
    {
        $cases = [
            'owner sent' => ['owner', 'sent', 201],
            'owner accepted' => ['owner', 'accepted', 201],
            'owner rejected' => ['owner', 'rejected', 422],
            'respondent sent' => ['respondent', 'sent', 422],
            'respondent accepted' => ['respondent', 'accepted', 201],
            'respondent rejected' => ['respondent', 'rejected', 422],
        ];
        foreach (['stranger' => 403, 'guest' => 401] as $role => $httpStatus) {
            foreach (['sent', 'accepted', 'rejected'] as $status) {
                $cases["{$role} {$status}"] = [$role, $status, $httpStatus];
            }
        }

        return $cases;
    }

    #[DataProvider('sendingCases')]
    public function test_sending_matrix_creates_messages_and_events_only_when_allowed(string $role, string $status, int $httpStatus): void
    {
        $this->travelTo(now()->startOfSecond());
        $conversation = Conversation::factory()->create();
        $response = $conversation->advertisementResponse;
        $response->update(['status' => $status]);
        $message = Message::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $response->user_id]);
        $before = $conversation->fresh()->updated_at->toJSON();
        $viewer = match ($role) {
            'owner' => $response->advertisement->user,
            'respondent' => $response->user,
            'stranger' => User::factory()->create(),
            'guest' => null,
        };
        if ($viewer !== null) {
            $this->actingAs($viewer);
        }
        Event::fake([NewMessage::class]);
        $this->travel(1)->minute();
        $result = $this->postJson("/api/conversations/{$conversation->id}/messages", ['message' => 'New message from matrix'])
            ->assertStatus($httpStatus);
        if ($httpStatus === 201) {
            $result->assertJsonPath('message.message', 'New message from matrix')
                ->assertJsonPath('message.user.id', $viewer->id);
            $this->assertDatabaseCount('messages', 2);
            $this->assertDatabaseHas('messages', [
                'id' => $result->json('message.id'), 'conversation_id' => $conversation->id,
                'user_id' => $viewer->id, 'body' => 'New message from matrix',
            ]);
            $this->assertSame(now()->toJSON(), $conversation->fresh()->updated_at->toJSON());
            Event::assertDispatchedTimes(NewMessage::class, 1);
            Event::assertDispatched(NewMessage::class, fn (NewMessage $event) => $event->message->id === $result->json('message.id'));
        } else {
            $this->assertDatabaseCount('messages', 1);
            $this->assertSame($before, $conversation->fresh()->updated_at->toJSON());
            Event::assertNotDispatched(NewMessage::class);
            if ($httpStatus === 422) {
                $result->assertJsonPath('message', $status === 'rejected' ? 'Диалог завершён.' : 'Нельзя отправлять сообщения до принятия отклика.');
            }
        }
        $this->assertSame($status, $response->fresh()->status->value);
        $this->assertEquals($message->toArray(), $message->fresh()->toArray());
    }

    public function test_lifecycle_keeps_history_after_termination_and_cannot_be_reopened(): void
    {
        Event::fake([NewResponse::class, NewMessage::class]);
        $owner = User::factory()->create();
        $respondent = User::factory()->create();
        $advertisement = Advertisement::factory()->create(['user_id' => $owner->id, 'status' => AdvertisementStatus::PUBLISHED]);
        $created = $this->actingAs($respondent)->postJson("/api/advertisements/{$advertisement->id}/responses", ['message' => 'Initial response'])
            ->assertCreated()->assertJsonPath('response.status', 'sent');
        $responseId = $created->json('response.id');
        $conversationId = $created->json('response.conversation_id');
        $base = "/api/conversations/{$conversationId}";
        $this->postJson($base.'/messages', ['message' => 'Too early'])->assertUnprocessable();
        $this->actingAs($owner)->postJson($base.'/messages', ['message' => 'Owner before acceptance'])->assertCreated();
        $this->postJson("/api/advertisement-responses/{$responseId}/accept")->assertOk()->assertJsonPath('response.status', 'accepted');
        foreach ([$respondent, $owner] as $participant) {
            $this->actingAs($participant)->postJson($base.'/messages', ['message' => 'Accepted exchange'])->assertCreated();
        }
        $messages = Message::query()->where('conversation_id', $conversationId)->orderBy('id')->get();
        $this->assertCount(4, $messages);
        $this->actingAs($owner)->postJson("/api/advertisement-responses/{$responseId}/reject")
            ->assertOk()->assertJsonPath('response.status', 'rejected');
        foreach ([$owner, $respondent] as $participant) {
            $this->actingAs($participant)->postJson($base.'/messages', ['message' => 'Too late'])->assertUnprocessable();
            $detail = $this->getJson($base)->assertOk()->assertJsonPath('conversation.response.status', 'rejected');
            $history = $this->getJson($base.'/messages?after_id=0')->assertOk();
            $this->assertSame($messages->pluck('id')->all(), array_column($detail->json('conversation.messages'), 'id'));
            $this->assertSame($detail->json('conversation.messages'), $history->json('messages'));
            $this->assertSame($messages->pluck('body')->all(), array_column($history->json('messages'), 'message'));
        }
        $this->actingAs($owner);
        foreach (['accept', 'reject'] as $action) {
            $this->postJson("/api/advertisement-responses/{$responseId}/{$action}")->assertUnprocessable();
        }
        $this->assertDatabaseHas('advertisement_responses', ['id' => $responseId, 'status' => 'rejected']);
        $this->assertDatabaseCount('advertisement_responses', 1);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 4);
        Event::assertDispatchedTimes(NewResponse::class, 1);
        Event::assertDispatchedTimes(NewMessage::class, 3);
    }
}
