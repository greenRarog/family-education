<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Child;
use App\Models\City;
use App\Models\Family;
use App\Models\User;
use App\Services\TurnstileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChildBirthDateTest extends TestCase
{
    use RefreshDatabase;

    public static function dates(): iterable
    {
        $cases = [
            'past' => ['2026-10-29 12:00:00', '2020-05-15', true],
            'today at midnight' => ['2026-10-29 00:00:00', '2026-10-29', true],
            'today with legacy time' => ['2026-10-29 00:00:00', '2026-10-29 23:59:59', true],
            'tomorrow at end of day' => ['2026-10-29 23:59:59', '2026-10-30', false],
            'future year' => ['2026-10-29 12:00:00', '2030-01-01', false],
            'year rollover' => ['2026-12-31 23:59:59', '2027-01-01', false],
            'leap day today' => ['2024-02-29 23:59:59', '2024-02-29', true],
            'month rollover' => ['2026-02-28 23:59:59', '2026-03-01', false],
            'invalid date' => ['2026-10-29 12:00:00', 'not-a-date', false],
            'missing date' => ['2026-10-29 12:00:00', null, false],
        ];

        foreach (['register', 'add', 'edit'] as $path) {
            foreach ($cases as $name => [$now, $date, $valid]) {
                yield "$path: $name" => [$path, $now, $date, $valid];
            }
        }
    }

    #[DataProvider('dates')]
    public function test_birth_date_boundary_and_atomic_rejection(string $path, string $now, ?string $date, bool $valid): void
    {
        $this->travelTo(Carbon::parse($now, config('app.timezone')));
        Notification::fake();
        $this->mock(TurnstileService::class, fn ($mock) => $mock->shouldReceive('verify')->andReturn(true));
        $city = City::factory()->create();
        $children = [
            ['name' => 'Первый', 'birth_date' => '2020-01-01', 'sex' => 'male'],
            ['name' => 'Второй', 'birth_date' => $date, 'sex' => 'female'],
        ];
        $family = null;
        $beforeFamily = null;
        $beforeChildren = [];

        if ($path !== 'register') {
            $user = User::factory()->unverified()->create();
            $family = Family::factory()->create(['user_id' => $user->id, 'city_id' => $city->id]);
            $existing = Child::factory()->count(3)->create(['family_id' => $family->id]);
            $children[0]['id'] = $existing[0]->id;
            if ($path === 'edit') {
                $children[1]['id'] = $existing[1]->id;
            }
            $beforeFamily = $family->fresh()->getAttributes();
            $beforeChildren = Child::query()->orderBy('id')->get()->map->getAttributes()->all();
            $this->actingAs($user);
        }

        $payload = ['surname' => 'Изменённая семья', 'city_id' => $city->id, 'children' => $children];
        $response = $path === 'register'
            ? $this->postJson('/register', $payload + [
                'name' => 'Родитель', 'email' => 'birthday@example.test', 'user_type' => 'family',
                'password' => 'Password123!', 'password_confirmation' => 'Password123!',
                'cf-turnstile-response' => 'test-token',
            ])
            : $this->putJson('/api/family', $payload);

        if ($valid) {
            $response->assertSuccessful();
            $this->assertDatabaseCount('children', 2);
            $child = Child::query()->where('name', 'Второй')->sole();
            $this->assertSame(substr($date, 0, 10), $child->birth_date->toDateString());
            $this->assertDatabaseHas('families', ['surname' => 'Изменённая семья']);
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors(['children.1.birth_date']);
            if ($date !== null && $date !== 'not-a-date') {
                $response->assertJsonFragment(['children.1.birth_date' => ['Дата рождения не может быть в будущем.']]);
            }
            Notification::assertNothingSent();
            if ($path === 'register') {
                $this->assertGuest();
                $this->assertDatabaseCount('users', 0);
                $this->assertDatabaseCount('families', 0);
                $this->assertDatabaseCount('children', 0);
            } else {
                $this->assertSame($beforeFamily, $family->fresh()->getAttributes());
                $this->assertSame($beforeChildren, Child::query()->orderBy('id')->get()->map->getAttributes()->all());
            }
        }
    }
}
