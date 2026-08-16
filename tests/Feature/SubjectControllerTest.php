<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserType;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubjectControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_subjects(): void
    {
        $this->getJson('/api/admin/subjects')
            ->assertUnauthorized();
    }

    public function test_regular_user_cannot_access_subjects(): void
    {
        $user = User::factory()->create([
            'user_type' => UserType::FAMILY,
        ]);

        $this->actingAs($user)
            ->getJson('/api/admin/subjects')
            ->assertForbidden();
    }

    public function test_admin_can_get_subjects(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::ADMIN,
        ]);

        Subject::factory()->create([
            'name' => 'Математика',
        ]);

        Subject::factory()->create([
            'name' => 'Русский язык',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/subjects')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment([
                'name' => 'Математика',
            ])
            ->assertJsonFragment([
                'name' => 'Русский язык',
            ]);
    }

    public function test_admin_can_create_subject(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::ADMIN,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/admin/subjects', [
                'name' => 'Физика',
            ])
            ->assertCreated()
            ->assertJson([
                'name' => 'Физика',
            ]);

        $this->assertDatabaseHas('subjects', [
            'name' => 'Физика',
        ]);
    }

    public function test_regular_user_cannot_create_subject(): void
    {
        $user = User::factory()->create([
            'user_type' => UserType::FAMILY,
        ]);

        $this->actingAs($user)
            ->postJson('/api/admin/subjects', [
                'name' => 'Физика',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('subjects', [
            'name' => 'Физика',
        ]);
    }

    public function test_admin_can_update_subject(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::ADMIN,
        ]);

        $subject = Subject::factory()->create([
            'name' => 'Физика',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/admin/subjects/{$subject->id}", [
                'name' => 'Астрономия',
            ])
            ->assertOk()
            ->assertJson([
                'id' => $subject->id,
                'name' => 'Астрономия',
            ]);

        $this->assertDatabaseHas('subjects', [
            'id' => $subject->id,
            'name' => 'Астрономия',
        ]);
    }

    public function test_regular_user_cannot_update_subject(): void
    {
        $user = User::factory()->create([
            'user_type' => UserType::FAMILY,
        ]);

        $subject = Subject::factory()->create([
            'name' => 'Физика',
        ]);

        $this->actingAs($user)
            ->putJson("/api/admin/subjects/{$subject->id}", [
                'name' => 'Астрономия',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('subjects', [
            'id' => $subject->id,
            'name' => 'Физика',
        ]);
    }

    public function test_admin_can_delete_subject(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::ADMIN,
        ]);

        $subject = Subject::factory()->create([
            'name' => 'Физика',
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/subjects/{$subject->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('subjects', [
            'id' => $subject->id,
        ]);
    }

    public function test_regular_user_cannot_delete_subject(): void
    {
        $user = User::factory()->create([
            'user_type' => UserType::FAMILY,
        ]);

        $subject = Subject::factory()->create([
            'name' => 'Физика',
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/admin/subjects/{$subject->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('subjects', [
            'id' => $subject->id,
        ]);
    }

    public function test_admin_cannot_create_subject_without_name(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::ADMIN,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/admin/subjects', [
                'name' => '',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
            ]);
    }

    public function test_admin_cannot_create_duplicate_subject(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::ADMIN,
        ]);

        Subject::factory()->create([
            'name' => 'Физика',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/admin/subjects', [
                'name' => 'Физика',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
            ]);
    }
}
