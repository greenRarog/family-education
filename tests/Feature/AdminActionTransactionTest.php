<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminActionType;
use App\Enums\AdvertisementStatus;
use App\Enums\UserType;
use App\Models\Advertisement;
use App\Models\User;
use App\Services\AdminActionLogger;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class AdminActionTransactionTest extends TestCase
{
    use DatabaseMigrations;

    public function test_requires_a_transaction_and_commits_with_the_business_change(): void
    {
        $actor = User::factory()->create(['user_type' => UserType::ADMIN]);
        $ad = Advertisement::factory()->create(['status' => AdvertisementStatus::PUBLISHED]);
        $write = fn () => app(AdminActionLogger::class)->record($actor, $ad, AdminActionType::ADVERTISEMENT_EXPIRED,
            'expired', 'The publication has expired.', ['status' => 'published'], ['status' => 'closed']);
        $this->assertSame(0, DB::transactionLevel());
        try {
            $write();
            $this->fail('An audit entry was written without a transaction.');
        } catch (LogicException) {
            $this->assertDatabaseCount('admin_actions', 0);
        }
        $entry = DB::transaction(function () use ($ad, $write) {
            $ad->update(['status' => AdvertisementStatus::CLOSED]);

            return $write();
        });
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(AdvertisementStatus::CLOSED, $ad->fresh()->status);
        $this->assertDatabaseHas('admin_actions', ['id' => $entry->id, 'target_id' => $ad->id]);
    }
}
