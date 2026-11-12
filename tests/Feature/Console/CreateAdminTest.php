<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_hashes_password_and_repeat_preserves_credentials(): void
    {
        Notification::fake();
        $this->artisan('admin:create', ['email' => 'Owner@example.com', '--name' => 'Owner'])
            ->expectsQuestion('Password', 'Local-secret-123')
            ->expectsQuestion('Confirm password', 'Local-secret-123')->assertSuccessful();
        $admin = User::query()->sole();
        $this->assertSame('owner@example.com', $admin->email);
        $this->assertSame(UserType::ADMIN, $admin->user_type);
        $this->assertTrue(Hash::check('Local-secret-123', $admin->password));
        $this->assertTrue($admin->hasVerifiedEmail());
        Notification::assertNothingSent();
        $before = $admin->getAttributes();
        $this->artisan('admin:create', ['email' => 'owner@example.com', '--name' => 'Changed'])->assertSuccessful();
        $this->assertSame($before, $admin->fresh()->getAttributes());
    }

    public function test_existing_unverified_admin_is_not_modified(): void
    {
        $admin = User::factory()->unverified()->create(['user_type' => UserType::ADMIN]);
        $before = $admin->fresh()->getAttributes();
        $this->artisan('admin:create', ['email' => $admin->email])->assertSuccessful();
        $this->assertSame($before, $admin->fresh()->getAttributes());
    }

    public function test_existing_regular_user_is_never_promoted(): void
    {
        $user = User::factory()->create(['user_type' => UserType::FAMILY]);
        $before = $user->fresh()->getAttributes();
        $this->artisan('admin:create', ['email' => $user->email])->assertFailed();
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_bad_confirmation_and_noninteractive_input_do_not_create_admin(): void
    {
        $this->artisan('admin:create', ['email' => 'owner@example.com'])
            ->expectsQuestion('Password', 'Local-secret-123')
            ->expectsQuestion('Confirm password', 'different')->assertFailed();
        $this->artisan('admin:create', ['email' => 'owner@example.com', '--no-interaction' => true])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }
}
