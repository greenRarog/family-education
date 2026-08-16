<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserType;
use App\Models\BannedWord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlockedTermControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_user_cannot_access_blocked_terms(): void
    {
        $user = User::factory()->create([
            'user_type' => UserType::FAMILY,
        ]);

        $this->actingAs($user)
            ->getJson('/api/admin/blocked-terms')
            ->assertForbidden();
    }

    public function test_admin_can_get_blocked_terms(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::ADMIN,
        ]);

        BannedWord::factory()->create([
            'word' => 'запрещённое слово',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/blocked-terms')
            ->assertOk()
            ->assertJsonFragment([
                'word' => 'запрещённое слово',
            ]);
    }

    public function test_admin_can_create_blocked_term(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::ADMIN,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/admin/blocked-terms', [
                'word' => 'новое слово',
            ])
            ->assertCreated()
            ->assertJson([
                'word' => 'новое слово',
            ]);

        $this->assertDatabaseHas('banned_words', [
            'word' => 'новое слово',
        ]);
    }

    public function test_admin_can_delete_blocked_term(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::ADMIN,
        ]);

        $blockedTerm = BannedWord::factory()->create();

        $this->actingAs($admin)
            ->deleteJson("/api/admin/blocked-terms/{$blockedTerm->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('banned_words', [
            'id' => $blockedTerm->id,
        ]);
    }
}
