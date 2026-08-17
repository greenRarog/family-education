<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Sex;
use App\Enums\UserType;
use App\Models\City;
use App\Models\District;
use App\Models\MetroStation;
use App\Models\User;
use App\Services\TurnstileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_user_can_register(): void
    {
        $city = City::factory()->create();
        $district = District::factory()->create([
            'city_id' => $city->id,
        ]);
        $metroStation = MetroStation::factory()->create([
            'city_id' => $city->id,
        ]);
        $this->mock(TurnstileService::class, function ($mock) {
            $mock
                ->shouldReceive('verify')
                ->once()
                ->with('test-turnstile-token')
                ->andReturn(true);
        });

        $response = $this->postJson('/register', [
            'cf-turnstile-response' => 'test-turnstile-token',
            'name' => 'Иван',
            'email' => 'ivan@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'surname' => 'Иванов',
            'city_id' => $city->id,
            'district_id' => $district->id,
            'metro_station_id' => $metroStation->id,
            'children' => [
                [
                    'name' => 'Пётр',
                    'birth_date' => '2020-05-15',
                    'sex' => Sex::MALE->value,
                ],
            ],
        ]);

        $response->assertSuccessful();
        $user = User::where('email', 'ivan@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('Иван', $user->name);
        $this->assertSame(UserType::FAMILY, $user->user_type);
        $this->assertTrue(Hash::check('Password123!', $user->password));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('families', [
            'user_id' => $user->id,
            'surname' => 'Иванов',
            'city_id' => $city->id,
            'district_id' => $district->id,
            'metro_station_id' => $metroStation->id,
        ]);
        $this->assertDatabaseHas('children', [
            'name' => 'Пётр',
            'birth_date' => '2020-05-15 00:00:00',
            'sex' => Sex::MALE->value,
        ]);
    }

    public function test_registration_requires_all_mandatory_fields(): void
    {
        $response = $this->postJson('/register', []);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'name',
                'email',
                'password',
                'surname',
                'city_id',
                'children',
            ]);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('families', 0);
        $this->assertDatabaseCount('children', 0);
        $this->assertGuest();
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

    public function test_user_cannot_login_with_invalid_credentials(): void
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

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->assertAuthenticatedAs($user);

        $response = $this->postJson('/logout');

        $response->assertSuccessful();

        $this->assertGuest();
    }

    public function test_login_page_has_route_name_required_by_auth_middleware(): void
    {
        $this->get('/login')
            ->assertOk();

        $this->assertSame(url('/login'), route('login'));
    }

    public function test_guest_logout_redirects_to_login_instead_of_throwing_route_exception(): void
    {
        $this->post('/logout')
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_get_current_user(): void
    {
        $user = User::factory()->create([
            'name' => 'Иван',
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
                    'name' => 'Иван',
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

    public function test_registration_requires_successful_turnstile_verification(): void
    {
        $this->mock(TurnstileService::class, function ($mock) {
            $mock
                ->shouldReceive('verify')
                ->once()
                ->with('invalid-turnstile-token')
                ->andReturn(false);
        });

        $response = $this->postJson('/register', [
            'name' => 'Иван Иванов',
            'email' => 'ivan@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'surname' => 'Иванов',
            'city_id' => City::factory()->create()->id,
            'children' => [
                [
                    'name' => 'Пётр',
                    'birth_date' => '2020-01-01',
                    'sex' => 'male',
                ],
            ],
            'cf-turnstile-response' => 'invalid-turnstile-token',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cf-turnstile-response']);

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_user_can_register_with_successful_turnstile_verification(): void
    {
        $city = City::factory()->create();
        $this->mock(TurnstileService::class, function ($mock) {
            $mock
                ->shouldReceive('verify')
                ->once()
                ->with('valid-turnstile-token')
                ->andReturn(true);
        });

        $response = $this->postJson('/register', [
            'name' => 'Иван Иванов',
            'email' => 'ivan@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'surname' => 'Иванов',
            'city_id' => $city->id,
            'children' => [
                [
                    'name' => 'Пётр',
                    'birth_date' => '2020-01-01',
                    'sex' => 'male',
                ],
            ],
            'cf-turnstile-response' => 'valid-turnstile-token',
        ]);

        $response->assertSuccessful();
        $this->assertDatabaseHas('users', [
            'name' => 'Иван Иванов',
            'email' => 'ivan@example.com',
        ]);
        $this->assertDatabaseHas('families', [
            'surname' => 'Иванов',
            'city_id' => $city->id,
        ]);
        $this->assertDatabaseHas('children', [
            'name' => 'Пётр',
            'birth_date' => '2020-01-01 00:00:00',
            'sex' => 'male',
        ]);
        $this->assertAuthenticated();
    }
}
