<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\AccountDeletionRegistry;
use App\Models\Child;
use App\Models\City;
use App\Models\Family;
use App\Models\NotificationSetting;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\TurnstileService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/** Explicitly registered ONLY in an isolated acceptance copy, never in application providers. */
class LifecycleFixtureProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (getenv('LIFECYCLE_FIXTURE_ENABLED') !== '1' || ! $this->app->environment('local')
            || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'family_education_test') {
            throw new RuntimeException('Lifecycle fixture requires an isolated local PostgreSQL test database.');
        }
        config(['account_deletion.enabled' => true]);
        $this->app->instance(AccountDeletionRegistry::class, new FakeAccountDeletionRegistry);
        $this->app->instance(TurnstileService::class, new class extends TurnstileService
        {
            public function verify(string $token, ?string $ip = null): bool
            {
                return true;
            }
        });
        $clock = storage_path('framework/lifecycle-clock');
        if (is_file($clock)) {
            Carbon::setTestNow(trim(file_get_contents($clock)));
        }
        // No application middleware is bypassed: these separate fixture routes only control test data/time.
        Route::prefix('__lifecycle')->group(function () use ($clock): void {
            Route::post('seed', function (Request $request) use ($clock) {
                abort_unless($request->header('X-Lifecycle-Fixture') === 'isolated-d09', 403);
                $role = $request->validate(['owner_role' => 'required|in:family,teacher'])['owner_role'];
                file_put_contents($clock, '2028-02-29 12:00:00 UTC');
                Carbon::setTestNow('2028-02-29 12:00:00 UTC');
                Artisan::call('migrate:fresh', ['--force' => true]);
                $city = City::factory()->create();
                $subject = Subject::factory()->create();
                $users = [];
                foreach (['family', 'teacher'] as $type) {
                    $user = User::factory()->create(['name' => 'D09 '.$type, 'email' => $type.'@d09.test', 'user_type' => $type, 'locale' => 'ru']);
                    NotificationSetting::query()->create(['user_id' => $user->id, 'email_enabled' => true, 'telegram_enabled' => false]);
                    if ($type === 'family') {
                        $family = Family::factory()->create(['user_id' => $user->id, 'city_id' => $city->id]);
                        $child = Child::factory()->create(['family_id' => $family->id]);
                    } else {
                        TeacherProfile::query()->create(['user_id' => $user->id, 'surname' => 'Fixture', 'city_id' => $city->id, 'public_profile_requested' => true, 'public_profile_status' => 'approved'])->subjects()->attach($subject->id);
                    }
                    $users[$type] = ['id' => $user->id, 'email' => $user->email];
                }

                return ['users' => $users, 'city_id' => $city->id, 'subject_id' => $subject->id,
                    'child_id' => $child->id, 'reset_token' => Password::createToken(User::findOrFail($users[$role]['id']))];
            });
            Route::post('time', function (Request $request) use ($clock) {
                abort_unless($request->header('X-Lifecycle-Fixture') === 'isolated-d09', 403);
                $time = $request->validate(['time' => 'required|date'])['time'];
                file_put_contents($clock, $time);

                return ['time' => $time];
            });
            Route::post('purge', function (Request $request) {
                abort_unless($request->header('X-Lifecycle-Fixture') === 'isolated-d09', 403);
                $exit = Artisan::call('accounts:purge-expired');

                return ['exit' => $exit, 'counts' => json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR)];
            });
            Route::get('state/{user}', function (Request $request, int $user) {
                abort_unless($request->header('X-Lifecycle-Fixture') === 'isolated-d09', 403);

                return ['user' => User::find($user)?->only(['id', 'name', 'email', 'account_deleted_at']),
                    'families' => DB::table('families')->where('user_id', $user)->count(),
                    'children' => DB::table('children')->count(),
                    'teachers' => DB::table('teacher_profiles')->where('user_id', $user)->count(),
                    'settings' => DB::table('notification_settings')->where('user_id', $user)->count(),
                    'sessions' => DB::table('sessions')->where('user_id', $user)->count(),
                    'resets' => DB::table('password_reset_tokens')->count(),
                    'conversations' => DB::table('conversations')->count(),
                    'messages' => DB::table('messages')->count(),
                    'responses' => DB::table('advertisement_responses')->count(),
                    'ads' => DB::table('advertisements')->where('user_id', $user)->get(),
                    'deletion' => DB::table('account_deletions')->where('user_id', $user)->first()];
            });
        });
    }
}
