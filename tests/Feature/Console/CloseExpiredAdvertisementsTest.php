<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\AdvertisementStatus;
use App\Models\Advertisement;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CloseExpiredAdvertisementsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_closes_expired_published_advertisements(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-22 12:00:00'));
        $expired = Advertisement::factory()->create([
            'status' => AdvertisementStatus::PUBLISHED,
            'published_at' => now()->subYear()->subSecond(),
        ]);
        $exactlyOneYear = Advertisement::factory()->create([
            'status' => AdvertisementStatus::PUBLISHED,
            'published_at' => now()->subYear(),
        ]);
        $notExpired = Advertisement::factory()->create([
            'status' => AdvertisementStatus::PUBLISHED,
            'published_at' => now()->subYear()->addSecond(),
        ]);
        $draft = Advertisement::factory()->create([
            'status' => AdvertisementStatus::DRAFT,
            'published_at' => now()->subYears(2),
        ]);
        $closed = Advertisement::factory()->create([
            'status' => AdvertisementStatus::CLOSED,
            'published_at' => now()->subYears(2),
        ]);
        $withoutPublishedAt = Advertisement::factory()->create([
            'status' => AdvertisementStatus::PUBLISHED,
            'published_at' => null,
        ]);

        $this->artisan('advertisements:close-expired')->assertSuccessful();

        $this->assertDatabaseHas('advertisements', [
            'id' => $expired->id,
            'status' => AdvertisementStatus::CLOSED->value,
        ]);
        $this->assertDatabaseHas('advertisements', [
            'id' => $exactlyOneYear->id,
            'status' => AdvertisementStatus::CLOSED->value,
        ]);
        $this->assertDatabaseHas('advertisements', [
            'id' => $notExpired->id,
            'status' => AdvertisementStatus::PUBLISHED->value,
        ]);
        $this->assertDatabaseHas('advertisements', [
            'id' => $draft->id,
            'status' => AdvertisementStatus::DRAFT->value,
        ]);
        $this->assertDatabaseHas('advertisements', [
            'id' => $closed->id,
            'status' => AdvertisementStatus::CLOSED->value,
        ]);
        $this->assertDatabaseHas('advertisements', [
            'id' => $withoutPublishedAt->id,
            'status' => AdvertisementStatus::PUBLISHED->value,
        ]);
    }
}
