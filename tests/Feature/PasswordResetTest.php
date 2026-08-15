<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_open_forgot_password_page(): void
    {
        $response = $this->get('/forgot-password');

        $response
            ->assertSuccessful()
            ->assertViewIs('app');
    }

    public function test_guest_can_request_password_reset_link(): void
    {
        $user = User::factory()->create();

        Notification::fake();

        $response = $this->post('/forgot-password', [
            'email' => $user->email,
        ]);

        $response
            ->assertSessionHasNoErrors();

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
        );
    }

    public function test_guest_can_open_reset_password_page(): void
    {
        $response = $this->get('/reset-password/test-token');

        $response
            ->assertSuccessful()
            ->assertViewIs('app');
    }

    public function test_user_can_reset_password(): void
    {
        $user = User::factory()->create([
            'password' => 'old-password',
        ]);

        $token = Password::broker()->createToken($user);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertTrue(
            Hash::check(
                'new-password',
                $user->fresh()->password,
            ),
        );
    }
}
