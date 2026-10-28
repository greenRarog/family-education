<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdvertisementResponseStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConversationPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_conversations_are_accessible_without_loading_their_histories(): void
    {
        $this->freezeTime();
        $viewer = User::factory()->create();
        $ids = [];
        $lastMessages = [];
        $unreadCounts = [];
        for ($i = 0; $i < 21; $i++) {
            $conversation = Conversation::factory()->create();
            $conversation->advertisementResponse->update(['user_id' => $viewer->id]);
            $ids[] = $conversation->id;
            $unreadCounts[$conversation->id] = $i === 0 ? 80 : 2;
            $messages = Message::factory()->count($unreadCounts[$conversation->id])->create([
                'conversation_id' => $conversation->id,
                'user_id' => $conversation->advertisementResponse->advertisement->user_id,
            ]);
            $lastMessages[$conversation->id] = $messages->last();
        }
        $loadedMessageIds = [];
        Event::listen('eloquent.retrieved: '.Message::class, function (Message $message) use (&$loadedMessageIds): void {
            $loadedMessageIds[] = $message->id;
        });
        $first = $this->actingAs($viewer)->getJson('/api/conversations')->assertOk()
            ->assertJsonCount(20, 'conversations.data')
            ->assertJsonPath('conversations.meta.current_page', 1)
            ->assertJsonPath('conversations.meta.last_page', 2)
            ->assertJsonPath('conversations.meta.per_page', 20)
            ->assertJsonPath('conversations.meta.total', 21)
            ->assertJsonPath('conversations.meta.from', 1)
            ->assertJsonPath('conversations.meta.to', 20)
            ->assertJsonPath('conversations.links.prev', null)
            ->assertJsonPath('conversations.data.0.unread_count', 2)
            ->assertJsonMissingPath('conversations.data.0.messages');
        $expectedFirstIds = array_slice(array_reverse($ids), 0, 20);
        $this->assertSame($expectedFirstIds, array_column($first->json('conversations.data'), 'id'));
        $this->assertEqualsCanonicalizing(array_map(fn ($id) => $lastMessages[$id]->id, $expectedFirstIds), $loadedMessageIds);
        $next = $first->json('conversations.links.next');
        $this->assertSame('/api/conversations', parse_url($next, PHP_URL_PATH));
        $this->assertSame('page=2', parse_url($next, PHP_URL_QUERY));
        $this->assertSame($next, $first->json('conversations.links.last'));
        $loadedMessageIds = [];
        $second = $this->getJson($next)->assertOk()
            ->assertJsonCount(1, 'conversations.data')
            ->assertJsonPath('conversations.meta.current_page', 2)
            ->assertJsonPath('conversations.meta.last_page', 2)
            ->assertJsonPath('conversations.meta.total', 21)
            ->assertJsonPath('conversations.meta.from', 21)
            ->assertJsonPath('conversations.meta.to', 21)
            ->assertJsonPath('conversations.links.next', null);
        $this->assertSame($first->json('conversations.links.first'), $second->json('conversations.links.prev'));
        $this->assertSame([$lastMessages[$ids[0]]->id], $loadedMessageIds);
        $received = array_merge(array_column($first->json('conversations.data'), 'id'), array_column($second->json('conversations.data'), 'id'));
        $this->assertSame(array_reverse($ids), $received);
        foreach (array_merge($first->json('conversations.data'), $second->json('conversations.data')) as $item) {
            $this->assertArrayNotHasKey('messages', $item);
            $this->assertSame($lastMessages[$item['id']]->id, $item['last_message']['id']);
            $this->assertSame($unreadCounts[$item['id']], $item['unread_count']);
            $this->assertTrue($item['has_unread_messages']);
        }
        $this->getJson('/api/conversations/unread-count')->assertOk()->assertJsonPath('count', 120);
    }

    public function test_conversation_list_summaries_are_correct_for_both_participants(): void
    {
        $conversation = Conversation::factory()->create();
        $owner = $conversation->advertisementResponse->advertisement->user;
        $respondent = $conversation->advertisementResponse->user;
        foreach ([[$owner, 2], [$respondent, 1]] as [$sender, $unreadCount]) {
            Message::factory()->count($unreadCount)->create([
                'conversation_id' => $conversation->id, 'user_id' => $sender->id,
            ]);
            Message::factory()->count(3)->read()->create([
                'conversation_id' => $conversation->id, 'user_id' => $sender->id,
            ]);
        }
        $lastMessage = Message::factory()->read()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $respondent->id,
            'body' => 'Latest message preview',
        ]);
        $unrelated = Conversation::factory()->create();
        Message::factory()->count(5)->create([
            'conversation_id' => $unrelated->id,
            'user_id' => $unrelated->advertisementResponse->user_id,
        ]);

        foreach ([[$owner, 1, true], [$respondent, 2, false]] as [$viewer, $unreadCount, $isOwner]) {
            $this->actingAs($viewer)->getJson('/api/conversations')->assertOk()
                ->assertJsonCount(1, 'conversations.data')
                ->assertJsonPath('conversations.meta.total', 1)
                ->assertJsonPath('conversations.data.0.id', $conversation->id)
                ->assertJsonPath('conversations.data.0.unread_count', $unreadCount)
                ->assertJsonPath('conversations.data.0.has_unread_messages', true)
                ->assertJsonPath('conversations.data.0.viewer.id', $viewer->id)
                ->assertJsonPath('conversations.data.0.viewer.is_advertisement_owner', $isOwner)
                ->assertJsonPath('conversations.data.0.viewer.is_response_author', ! $isOwner)
                ->assertJsonPath('conversations.data.0.last_message', [
                    'id' => $lastMessage->id,
                    'user' => ['id' => $respondent->id, 'name' => $respondent->name],
                    'message' => 'Latest message preview',
                    'read_at' => $lastMessage->read_at->toJSON(),
                    'created_at' => $lastMessage->created_at->toJSON(),
                ])
                ->assertJsonMissingPath('conversations.data.0.messages');
            $this->getJson('/api/conversations/unread-count')->assertOk()->assertJsonPath('count', $unreadCount);
        }
        $this->assertSame(3, $conversation->messages()->whereNull('read_at')->count());
    }

    public function test_conversation_list_prioritizes_unread_then_recent_activity(): void
    {
        $this->freezeTime();
        $viewer = User::factory()->create();
        $ids = [];
        foreach ([['empty', 1], ['read', 0], ['unread_old', 3], ['unread_new', 2]] as [$kind, $daysAgo]) {
            $conversation = Conversation::factory()->create(['updated_at' => now()->subDays($daysAgo)]);
            $conversation->advertisementResponse->update(['user_id' => $viewer->id]);
            $ids[$kind] = $conversation->id;
            if ($kind !== 'empty') {
                Message::factory()->create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $conversation->advertisementResponse->advertisement->user_id,
                    'read_at' => $kind === 'read' ? now() : null,
                ]);
            }
        }
        $result = $this->actingAs($viewer)->getJson('/api/conversations')->assertOk()
            ->assertJsonPath('conversations.data.2.unread_count', 0)
            ->assertJsonPath('conversations.data.2.has_unread_messages', false)
            ->assertJsonPath('conversations.data.3.unread_count', 0)
            ->assertJsonPath('conversations.data.3.has_unread_messages', false)
            ->assertJsonPath('conversations.data.3.last_message', null);
        $this->assertSame([$ids['unread_new'], $ids['unread_old'], $ids['read'], $ids['empty']], array_column($result->json('conversations.data'), 'id'));
    }

    public function test_conversation_list_requires_authentication_and_handles_an_empty_list(): void
    {
        $this->getJson('/api/conversations')->assertUnauthorized();
        $this->getJson('/api/conversations/unread-count')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/conversations')->assertOk()
            ->assertJsonCount(0, 'conversations.data')
            ->assertJsonPath('conversations.meta.total', 0)
            ->assertJsonPath('conversations.meta.current_page', 1)
            ->assertJsonPath('conversations.meta.last_page', 1)
            ->assertJsonPath('conversations.meta.per_page', 20)
            ->assertJsonPath('conversations.meta.from', null)
            ->assertJsonPath('conversations.meta.to', null)
            ->assertJsonPath('conversations.links.prev', null)
            ->assertJsonPath('conversations.links.next', null);
        $this->getJson('/api/conversations/unread-count')->assertOk()->assertJsonPath('count', 0);
    }

    public function test_history_and_updates_have_stable_boundaries_and_do_not_skip_messages(): void
    {
        $this->freezeTime();
        $conversation = Conversation::factory()->create();
        $viewer = $conversation->advertisementResponse->user;
        $messages = Message::factory()->count(120)->create([
            'conversation_id' => $conversation->id,
            'user_id' => $viewer->id,
        ]);
        $initial = $this->actingAs($viewer)->getJson("/api/conversations/{$conversation->id}")->assertOk()
            ->assertJsonCount(50, 'conversation.messages')->assertJsonPath('messages_meta.has_older', true);
        $this->assertSame($messages[70]->id, $initial->json('messages_meta.oldest_id'));
        $this->assertSame($messages[119]->id, $initial->json('messages_meta.latest_id'));
        $this->assertSame($messages->slice(70)->pluck('id')->all(), array_column($initial->json('conversation.messages'), 'id'));
        $plainHistory = $this->getJson("/api/conversations/{$conversation->id}/messages")->assertOk()
            ->assertJsonPath('meta.has_more', true);
        $this->assertSame($initial->json('conversation.messages'), $plainHistory->json('messages'));

        // New arrivals must not shift older pages based on the initial snapshot.
        $newMessages = Message::factory()->count(3)->create([
            'conversation_id' => $conversation->id,
            'user_id' => $conversation->advertisementResponse->advertisement->user_id,
        ]);

        $older = $this->getJson("/api/conversations/{$conversation->id}/messages?before_id={$messages[70]->id}")
            ->assertOk()->assertJsonCount(50, 'messages')->assertJsonPath('meta.has_more', true);
        $oldest = $this->getJson("/api/conversations/{$conversation->id}/messages?before_id={$messages[20]->id}")
            ->assertOk()->assertJsonCount(20, 'messages')->assertJsonPath('meta.has_more', false);
        $all = array_merge($oldest->json('messages'), $older->json('messages'), $initial->json('conversation.messages'));
        $this->assertSame($messages->pluck('id')->all(), array_column($all, 'id'));

        $expected = $messages->concat($newMessages)->pluck('id')->all();
        $received = [];
        $cursor = 0;
        foreach ([50, 50, 23] as $page => $count) {
            $updates = $this->getJson("/api/conversations/{$conversation->id}?after_id={$cursor}")
                ->assertOk()->assertJsonCount($count, 'conversation.messages')
                ->assertJsonPath('messages_meta.has_older', false)
                ->assertJsonPath('messages_meta.has_more', $page < 2);
            $history = $this->getJson("/api/conversations/{$conversation->id}/messages?after_id={$cursor}")
                ->assertOk()->assertJsonPath('meta.has_more', $page < 2);
            $this->assertSame($updates->json('conversation.messages'), $history->json('messages'));
            $pageIds = array_column($history->json('messages'), 'id');
            $this->assertSame(array_slice($expected, $page * 50, $count), $pageIds);
            $this->assertSame($pageIds[0], $updates->json('messages_meta.oldest_id'));
            $this->assertSame($pageIds[array_key_last($pageIds)], $updates->json('messages_meta.latest_id'));
            $received = array_merge($received, $pageIds);
            $cursor = $updates->json('messages_meta.latest_id');
        }
        $this->assertSame($expected, $received);
        $this->getJson("/api/conversations/{$conversation->id}?after_id={$cursor}")
            ->assertOk()->assertJsonCount(0, 'conversation.messages')
            ->assertJsonPath('messages_meta.has_more', false)
            ->assertJsonPath('messages_meta.oldest_id', null)
            ->assertJsonPath('messages_meta.latest_id', null);
        $this->getJson("/api/conversations/{$conversation->id}/messages?before_id={$messages[0]->id}")
            ->assertOk()->assertJsonCount(0, 'messages')->assertJsonPath('meta.has_more', false);
    }

    public static function historySizes(): array
    {
        return ['empty' => [0], 'one' => [1], 'below limit' => [49], 'at limit' => [50], 'above limit' => [51]];
    }

    #[DataProvider('historySizes')]
    public function test_history_page_limits_and_cursor_metadata(int $count): void
    {
        $conversation = Conversation::factory()->create();
        $viewer = $conversation->advertisementResponse->user;
        $messages = Message::factory()->count($count)->create([
            'conversation_id' => $conversation->id, 'user_id' => $viewer->id,
        ]);
        $ids = $messages->pluck('id')->all();
        $this->actingAs($viewer);
        foreach (['' => array_slice($ids, -50), '?after_id=0' => array_slice($ids, 0, 50)] as $suffix => $expected) {
            $detail = $this->getJson("/api/conversations/{$conversation->id}{$suffix}")->assertOk()
                ->assertJsonCount(min(50, $count), 'conversation.messages')
                ->assertJsonPath('messages_meta.has_older', $suffix === '' && $count > 50)
                ->assertJsonPath('messages_meta.has_more', $suffix !== '' && $count > 50)
                ->assertJsonPath('messages_meta.oldest_id', $expected[0] ?? null)
                ->assertJsonPath('messages_meta.latest_id', $expected === [] ? null : $expected[array_key_last($expected)]);
            $history = $this->getJson("/api/conversations/{$conversation->id}/messages{$suffix}")->assertOk()
                ->assertJsonPath('meta.has_more', $count > 50);
            $this->assertSame($expected, array_column($detail->json('conversation.messages'), 'id'));
            $this->assertSame($detail->json('conversation.messages'), $history->json('messages'));
        }
        // Empty nullable cursors behave like an initial request.
        $emptyCursor = $this->getJson("/api/conversations/{$conversation->id}/messages?before_id=&after_id=")->assertOk();
        $this->assertSame(array_slice($ids, -50), array_column($emptyCursor->json('messages'), 'id'));
        $afterLast = $messages->last()?->id ?? 0;
        $beforeAll = $afterLast + 1;
        foreach (['?before_id='.$beforeAll.'&after_id=' => array_slice($ids, -50), '?before_id=&after_id=0' => array_slice($ids, 0, 50)] as $suffix => $expected) {
            $result = $this->getJson("/api/conversations/{$conversation->id}/messages{$suffix}")
                ->assertOk()->assertJsonPath('meta.has_more', $count > 50);
            $this->assertSame($expected, array_column($result->json('messages'), 'id'));
        }
        $this->getJson("/api/conversations/{$conversation->id}/messages?after_id={$afterLast}")
            ->assertOk()->assertJsonCount(0, 'messages')->assertJsonPath('meta.has_more', false);
    }

    public function test_history_cursors_remain_scoped_to_the_conversation_with_gaps_in_ids(): void
    {
        $conversation = Conversation::factory()->create();
        $other = Conversation::factory()->create();
        $viewer = $conversation->advertisementResponse->user;
        $ids = [];
        $foreignIds = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = Message::factory()->create([
                'conversation_id' => $conversation->id, 'user_id' => $viewer->id,
            ])->id;
            $foreignIds[] = Message::factory()->create([
                'conversation_id' => $other->id, 'user_id' => $other->advertisementResponse->user_id,
            ])->id;
        }
        $this->actingAs($viewer);
        foreach (['' => $ids, '?before_id='.$foreignIds[1] => array_slice($ids, 0, 2), '?after_id='.$foreignIds[1] => [$ids[2]]] as $suffix => $expected) {
            $result = $this->getJson("/api/conversations/{$conversation->id}/messages{$suffix}")->assertOk();
            $this->assertSame($expected, array_column($result->json('messages'), 'id'));
        }
        $detail = $this->getJson("/api/conversations/{$conversation->id}?after_id={$foreignIds[1]}")->assertOk();
        $this->assertSame([$ids[2]], array_column($detail->json('conversation.messages'), 'id'));
    }

    public function test_history_requires_membership_and_valid_cursors(): void
    {
        $conversation = Conversation::factory()->create();
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $conversation->advertisementResponse->advertisement->user_id,
            'body' => 'Private conversation history',
        ]);
        $base = "/api/conversations/{$conversation->id}";
        $urls = [$base, $base.'?after_id=0', $base.'/messages', $base.'/messages?before_id='.($message->id + 1), $base.'/messages?after_id=0'];
        foreach ($urls as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        $this->actingAs(User::factory()->create());
        foreach ($urls as $url) {
            $this->getJson($url)->assertForbidden()->assertJsonMissingPath('messages')->assertJsonMissingPath('conversation');
        }
        foreach ([$conversation->advertisementResponse->user, $conversation->advertisementResponse->advertisement->user] as $participant) {
            $this->actingAs($participant);
            foreach ($urls as $url) {
                $path = str_contains($url, '/messages') ? 'messages' : 'conversation.messages';
                $this->getJson($url)->assertOk()->assertJsonCount(1, $path)
                    ->assertJsonPath($path.'.0.id', $message->id)
                    ->assertJsonPath($path.'.0.message', 'Private conversation history');
            }
        }
        foreach (['before_id' => [0, -1, '1.5', 'abc', [1]], 'after_id' => [-1, '1.5', 'abc', [1]]] as $field => $values) {
            foreach ($values as $value) {
                $query = http_build_query([$field => $value]);
                $this->getJson($base.'/messages?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
                if ($field === 'after_id') {
                    $this->getJson($base.'?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
                }
            }
        }
        foreach ([0, 1] as $afterId) {
            $this->getJson($base.'/messages?before_id=2&after_id='.$afterId)
                ->assertUnprocessable()->assertJsonValidationErrors(['before_id', 'after_id']);
        }
    }

    public function test_read_receipt_does_not_mark_messages_arriving_after_the_snapshot(): void
    {
        $conversation = Conversation::factory()->create();
        $viewer = $conversation->advertisementResponse->user;
        $sender = $conversation->advertisementResponse->advertisement->user;
        $messages = Message::factory()->count(2)->create([
            'conversation_id' => $conversation->id, 'user_id' => $sender->id,
        ]);
        $this->actingAs($viewer)->postJson("/api/conversations/{$conversation->id}/read", ['through_id' => $messages[0]->id])
            ->assertOk()->assertJsonPath('through_id', $messages[0]->id);
        $this->assertNotNull($messages[0]->fresh()->read_at);
        $this->assertNull($messages[1]->fresh()->read_at);
        $this->getJson('/api/conversations/unread-count')->assertOk()->assertJsonPath('count', 1);
        $this->actingAs($sender)->getJson("/api/conversations/{$conversation->id}?after_id={$messages[1]->id}")
            ->assertOk()->assertJsonPath('messages_meta.read_by_other_through_id', $messages[0]->id);
    }

    public function test_terminating_an_accepted_conversation_blocks_both_participants_but_keeps_history(): void
    {
        Notification::fake();
        $conversation = Conversation::factory()->create();
        $response = $conversation->advertisementResponse;
        $owner = $response->advertisement->user;
        $response->update(['status' => AdvertisementResponseStatus::ACCEPTED]);
        Message::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $owner->id]);
        $this->actingAs($owner)->postJson("/api/advertisement-responses/{$response->id}/reject")->assertOk();
        foreach ([$owner, $response->user] as $viewer) {
            $this->actingAs($viewer)->postJson("/api/conversations/{$conversation->id}/messages", ['message' => 'Too late'])
                ->assertUnprocessable();
            $this->getJson("/api/conversations/{$conversation->id}")->assertOk()->assertJsonCount(1, 'conversation.messages');
        }
    }
}
