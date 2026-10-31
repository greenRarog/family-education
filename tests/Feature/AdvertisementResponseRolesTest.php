<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdvertisementResponseStatus;
use App\Enums\AdvertisementStatus;
use App\Enums\AdvertisementType;
use App\Enums\UserType;
use App\Events\NewMessage;
use App\Events\NewResponse;
use App\Models\Advertisement;
use App\Models\AdvertisementResponse;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdvertisementResponseRolesTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return [
            'group family' => [AdvertisementType::FAMILY_TO_FAMILY, UserType::FAMILY, 201],
            'group teacher' => [AdvertisementType::FAMILY_TO_FAMILY, UserType::TEACHER, 403],
            'group admin' => [AdvertisementType::FAMILY_TO_FAMILY, UserType::ADMIN, 403],
            'group guest' => [AdvertisementType::FAMILY_TO_FAMILY, null, 401],
            'search family' => [AdvertisementType::FAMILY_TO_TEACHER, UserType::FAMILY, 403],
            'search teacher' => [AdvertisementType::FAMILY_TO_TEACHER, UserType::TEACHER, 201],
            'search admin' => [AdvertisementType::FAMILY_TO_TEACHER, UserType::ADMIN, 403],
            'search guest' => [AdvertisementType::FAMILY_TO_TEACHER, null, 401],
            'service family' => [AdvertisementType::TEACHER_TO_SERVICE, UserType::FAMILY, 201],
            'service teacher' => [AdvertisementType::TEACHER_TO_SERVICE, UserType::TEACHER, 403],
            'service admin' => [AdvertisementType::TEACHER_TO_SERVICE, UserType::ADMIN, 403],
            'service guest' => [AdvertisementType::TEACHER_TO_SERVICE, null, 401],
        ];
    }

    #[DataProvider('roles')]
    public function test_new_response_roles_match_the_public_capability_without_side_effects_on_denial(
        AdvertisementType $type, ?UserType $role, int $status
    ): void {
        Event::fake([NewResponse::class]);
        $ad = Advertisement::factory()->create(['type' => $type, 'status' => AdvertisementStatus::PUBLISHED]);
        if ($role !== null) {
            $this->actingAs(User::factory()->create(['user_type' => $role]));
        }

        $this->getJson("/api/advertisements/{$ad->id}")->assertOk()
            ->assertJsonPath('viewer.can_respond', $status === 201);
        $response = $this->postJson("/api/advertisements/{$ad->id}/responses", ['message' => 'Initial response'])
            ->assertStatus($status);
        $count = $status === 201 ? 1 : 0;
        $this->assertDatabaseCount('advertisement_responses', $count);
        $this->assertDatabaseCount('conversations', $count);
        $this->assertDatabaseCount('messages', $count);
        $this->assertSame(AdvertisementStatus::PUBLISHED, $ad->fresh()->status);
        Event::assertDispatchedTimes(NewResponse::class, $count);

        if ($status === 201) {
            $this->assertDatabaseHas('advertisement_responses', [
                'id' => $response->json('response.id'), 'advertisement_id' => $ad->id,
                'user_id' => auth()->id(), 'status' => AdvertisementResponseStatus::SENT->value,
            ]);
            $this->getJson("/api/advertisements/{$ad->id}")->assertOk()
                ->assertJsonPath('viewer.can_respond', false)
                ->assertJsonPath('viewer.has_responded', true);
            $this->postJson("/api/advertisements/{$ad->id}/responses", ['message' => 'Duplicate'])
                ->assertUnprocessable();
            $this->assertDatabaseCount('messages', 1);
            Event::assertDispatchedTimes(NewResponse::class, 1);
        }
    }

    public static function types(): array
    {
        return array_map(fn (AdvertisementType $type) => [$type], AdvertisementType::cases());
    }

    #[DataProvider('types')]
    public function test_owners_cannot_respond_even_when_their_role_matches(AdvertisementType $type): void
    {
        $owner = User::factory()->create([
            'user_type' => $type === AdvertisementType::TEACHER_TO_SERVICE ? UserType::TEACHER : UserType::FAMILY,
        ]);
        $ad = Advertisement::factory()->create([
            'user_id' => $owner->id, 'type' => $type, 'status' => AdvertisementStatus::PUBLISHED,
        ]);
        $this->actingAs($owner)->getJson("/api/advertisements/{$ad->id}")->assertOk()
            ->assertJsonPath('viewer.can_respond', false)->assertJsonPath('viewer.is_owner', true);
        $this->postJson("/api/advertisements/{$ad->id}/responses", ['message' => 'Own ad'])
            ->assertUnprocessable()->assertJsonPath('message', 'Нельзя откликнуться на собственное объявление.');
        $this->assertDatabaseCount('advertisement_responses', 0);
    }

    #[DataProvider('types')]
    public function test_an_allowed_role_still_cannot_respond_to_draft_or_closed_ads(AdvertisementType $type): void
    {
        Event::fake([NewResponse::class]);
        $this->actingAs(User::factory()->create([
            'user_type' => $type === AdvertisementType::FAMILY_TO_TEACHER ? UserType::TEACHER : UserType::FAMILY,
        ]));
        foreach ([AdvertisementStatus::DRAFT, AdvertisementStatus::CLOSED] as $status) {
            $ad = Advertisement::factory()->create(['type' => $type, 'status' => $status]);
            $this->postJson("/api/advertisements/{$ad->id}/responses", ['message' => 'Unavailable ad'])
                ->assertUnprocessable();
        }
        $this->assertDatabaseCount('advertisement_responses', 0);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
        Event::assertNotDispatched(NewResponse::class);
    }

    public static function legacyRoles(): array
    {
        return [
            'teacher in group' => [AdvertisementType::FAMILY_TO_FAMILY, UserType::TEACHER],
            'admin in group' => [AdvertisementType::FAMILY_TO_FAMILY, UserType::ADMIN],
            'family in search' => [AdvertisementType::FAMILY_TO_TEACHER, UserType::FAMILY],
            'admin in search' => [AdvertisementType::FAMILY_TO_TEACHER, UserType::ADMIN],
        ];
    }

    #[DataProvider('legacyRoles')]
    public function test_previous_participants_keep_their_conversation_lifecycle(AdvertisementType $type, UserType $role): void
    {
        Event::fake([NewResponse::class, NewMessage::class]);
        $owner = User::factory()->create();
        $respondent = User::factory()->create(['user_type' => $role]);
        $ad = Advertisement::factory()->create([
            'user_id' => $owner->id, 'type' => $type, 'status' => AdvertisementStatus::CLOSED,
        ]);
        $response = AdvertisementResponse::factory()->create([
            'advertisement_id' => $ad->id, 'user_id' => $respondent->id,
            'status' => AdvertisementResponseStatus::SENT,
        ]);
        $conversation = Conversation::query()->create(['advertisement_response_id' => $response->id]);
        $initial = Message::factory()->create([
            'conversation_id' => $conversation->id, 'user_id' => $respondent->id, 'body' => 'Legacy response',
        ]);
        $url = "/api/conversations/{$conversation->id}";
        $this->actingAs($respondent)->getJson("/api/advertisements/{$ad->id}")->assertOk()
            ->assertJsonPath('viewer.can_respond', false)->assertJsonPath('viewer.has_conversation', true);
        $this->postJson("{$url}/messages", ['message' => 'Before acceptance'])->assertUnprocessable();
        $this->actingAs($owner)->postJson("{$url}/messages", ['message' => 'Owner before acceptance'])->assertCreated();
        $this->postJson("/api/advertisement-responses/{$response->id}/accept")->assertOk();
        $this->actingAs($respondent)->postJson("{$url}/messages", ['message' => 'Legacy participant after acceptance'])
            ->assertCreated();
        foreach ([$owner, $respondent] as $participant) {
            $this->actingAs($participant)->getJson('/api/conversations')->assertOk()
                ->assertJsonPath('conversations.data.0.id', $conversation->id);
            $this->getJson($url)->assertOk()->assertJsonPath('conversation.id', $conversation->id);
            $this->getJson("{$url}/messages?after_id=0")->assertOk()->assertJsonCount(3, 'messages');
            $this->postJson("{$url}/read", ['through_id' => $initial->id])->assertOk();
        }
        $this->actingAs($owner)->postJson("/api/advertisement-responses/{$response->id}/reject")->assertOk();
        foreach ([$owner, $respondent] as $participant) {
            $this->actingAs($participant)->postJson("{$url}/messages", ['message' => 'After rejection'])->assertUnprocessable();
            $this->getJson("{$url}/messages?after_id=0")->assertOk()
                ->assertJsonCount(3, 'messages')->assertJsonPath('messages.0.message', 'Legacy response');
        }
        $this->actingAs(User::factory()->create(['user_type' => UserType::ADMIN]))->getJson($url)->assertForbidden();
        $this->assertSame(3, $conversation->messages()->count());
        $this->assertSame(AdvertisementResponseStatus::REJECTED, $response->fresh()->status);
    }
}
