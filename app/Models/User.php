<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserType;
use App\Services\EmailVerificationService;
use Database\Factories\UserFactory;
use Eloquent;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * docker compose exec app php artisan ide-helper:models "App\Models\User"
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $telegram_chat_id
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property UserType $user_type
 * @property string|null $locale
 * @property int|null $block_action_id
 * @property-read AdminAction|null $blockingAction
 * @property-read Collection<int, AdvertisementResponse> $advertisementResponses
 * @property-read int|null $advertisement_responses_count
 * @property-read Collection<int, Advertisement> $advertisements
 * @property-read int|null $advertisements_count
 * @property-read Family|null $family
 * @property-read Collection<int, Message> $messages
 * @property-read int|null $messages_count
 * @property-read NotificationSetting|null $notificationSetting
 * @property-read TeacherProfile|null $teacherProfile
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 *
 * @method static UserFactory factory($count = null, $state = [])
 * @method static Builder<static>|User newModelQuery()
 * @method static Builder<static>|User newQuery()
 * @method static Builder<static>|User query()
 * @method static Builder<static>|User whereCreatedAt($value)
 * @method static Builder<static>|User whereEmail($value)
 * @method static Builder<static>|User whereEmailVerifiedAt($value)
 * @method static Builder<static>|User whereId($value)
 * @method static Builder<static>|User whereName($value)
 * @method static Builder<static>|User wherePassword($value)
 * @method static Builder<static>|User whereRememberToken($value)
 * @method static Builder<static>|User whereTelegramChatId($value)
 * @method static Builder<static>|User whereUpdatedAt($value)
 * @method static Builder<static>|User whereUserType($value)
 *
 * @mixin Eloquent
 */
#[Fillable(['name', 'email', 'password', 'user_type', 'telegram_chat_id', 'locale'])]
#[Hidden(['password', 'remember_token', 'block_action_id', 'blockingAction'])]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, MustVerifyEmail, Notifiable;

    protected $fillable = [
        'name',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'user_type' => UserType::class,
            'block_action_id' => 'integer',
        ];
    }

    public function preferredLocale(): string
    {
        return $this->locale ?? 'ru';
    }

    public function isBlocked(): bool
    {
        return $this->block_action_id !== null;
    }

    /** @return BelongsTo<AdminAction, $this> */
    public function blockingAction(): BelongsTo
    {
        return $this->belongsTo(AdminAction::class, 'block_action_id');
    }

    /** @return HasOne<Family, $this> */
    public function family(): HasOne
    {
        return $this->hasOne(Family::class);
    }

    /** @return HasOne<TeacherProfile, $this> */
    public function teacherProfile(): HasOne
    {
        return $this->hasOne(TeacherProfile::class);
    }

    /** @return HasOne<NotificationSetting, $this> */
    public function notificationSetting(): HasOne
    {
        return $this->hasOne(NotificationSetting::class);
    }

    /** @return HasMany<Advertisement, $this> */
    public function advertisements(): HasMany
    {
        return $this->hasMany(Advertisement::class);
    }

    public function advertisementResponses(): HasMany
    {
        return $this->hasMany(AdvertisementResponse::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return HasMany<Report, $this> */
    public function reportsSubmitted(): HasMany
    {
        return $this->hasMany(Report::class, 'reporter_id');
    }

    /** @return MorphMany<Report, $this> */
    public function reportsReceived(): MorphMany
    {
        return $this->morphMany(Report::class, 'target');
    }

    public function sendEmailVerificationNotification(): void
    {
        // Registration must still create a usable session when mail is unavailable.
        app(EmailVerificationService::class)->send($this);
    }

    public function emailNotificationsEnabled(): bool
    {
        $setting = $this->notificationSetting;

        return $this->hasVerifiedEmail() && ($setting === null || $setting->email_enabled);
    }

    public function telegramNotificationsEnabled(): bool
    {
        $setting = $this->notificationSetting;

        return $setting !== null
            && $setting->telegram_enabled
            && $this->telegram_chat_id !== null;
    }
}
