<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register(): void
    {
        $response = $this->postJson('/register', [
            'name' => 'Иван Иванов',
            'email' => 'ivan@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'name' => 'Иван Иванов',
            'email' => 'ivan@example.com',
            'user_type' => UserType::FAMILY->value,
        ]);

        $user = User::where('email', 'ivan@example.com')->first();

        $this->assertNotNull($user);
        $this->assertTrue(
            Hash::check('Password123!', $user->password)
        );
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_requires_valid_email(): void
    {
        $response = $this->postJson('/register', [
            'name' => 'Иван Иванов',
            'email' => 'not-an-email',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_registration_requires_unique_email(): void
    {
        User::factory()->create([
            'email' => 'ivan@example.com',
        ]);

        $response = $this->postJson('/register', [
            'name' => 'Другой Иван',
            'email' => 'ivan@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_registration_requires_matching_password_confirmation(): void
    {
        $response = $this->postJson('/register', [
            'name' => 'Иван Иванов',
            'email' => 'ivan@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'DifferentPassword123!',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_registration_requires_name(): void
    {
        $response = $this->postJson('/register', [
            'name' => '',
            'email' => 'ivan@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_user_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'ivan@example.com',
            'password' => 'Password123!',
        ]);

        $this->assertGuest();

        $response = $this->postJson('/login', [
            'email' => 'ivan@example.com',
            'password' => 'Password123!',
        ]);

        $response->assertSuccessful();

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_cannot_login_with_invalid_password(): void
    {
        $user = User::factory()->create([
            'email' => 'ivan@example.com',
            'password' => 'Password123!',
        ]);

        $response = $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'WrongPassword123!',
        ]);

        $response->assertStatus(422);

        $this->assertGuest();
    }

    public function test_user_cannot_login_with_unknown_email(): void
    {
        $response = $this->postJson('/login', [
            'email' => 'unknown@example.com',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(422);

        $this->assertGuest();
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->assertAuthenticatedAs($user);

        $response = $this->postJson('/logout');

        $response->assertSuccessful();

        $this->assertGuest();
    }

    public function test_authenticated_user_can_get_current_user(): void
    {
        $user = User::factory()->create([
            'name' => 'Иван Иванов',
            'email' => 'ivan@example.com',
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson('/api/user');

        $response
            ->assertSuccessful()
            ->assertJson([
                'authenticated' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => 'Иван Иванов',
                    'email' => 'ivan@example.com',
                ],
            ]);
    }

    public function test_guest_can_get_current_user_status(): void
    {
        $response = $this->getJson('/api/user');

        $response
            ->assertSuccessful()
            ->assertJson([
                'authenticated' => false,
                'user' => null,
            ]);
    }
}
