<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NotificationSettingFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docker compose exec app php artisan ide-helper:models "App\Models\NotificationSetting"
 *
 * @property int $id
 * @property int $user_id
 * @property bool $email_enabled
 * @property bool $telegram_enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 *
 * @method static NotificationSettingFactory factory($count = null, $state = [])
 * @method static Builder<static>|NotificationSetting newModelQuery()
 * @method static Builder<static>|NotificationSetting newQuery()
 * @method static Builder<static>|NotificationSetting query()
 * @method static Builder<static>|NotificationSetting whereCreatedAt($value)
 * @method static Builder<static>|NotificationSetting whereEmailEnabled($value)
 * @method static Builder<static>|NotificationSetting whereId($value)
 * @method static Builder<static>|NotificationSetting whereTelegramEnabled($value)
 * @method static Builder<static>|NotificationSetting whereUpdatedAt($value)
 * @method static Builder<static>|NotificationSetting whereUserId($value)
 *
 * @mixin Eloquent
 */
class NotificationSetting extends Model
{
    /** @use HasFactory<NotificationSettingFactory> */
    use HasFactory;

    protected $table = 'notification_settings';

    protected $fillable = [
        'user_id',
        'email_enabled',
        'telegram_enabled',
    ];

    protected function casts(): array
    {
        return [
            'email_enabled' => 'boolean',
            'telegram_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
