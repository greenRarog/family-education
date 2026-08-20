<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AdvertisementResponseFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docker compose exec app php artisan ide-helper:models "App\Models\AdvertisementResponse"
 *
 * @property int $id
 * @property int $advertisement_id
 * @property int $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Advertisement $advertisement
 * @property-read User $user
 *
 * @method static AdvertisementResponseFactory factory($count = null, $state = [])
 * @method static Builder<static>|AdvertisementResponse newModelQuery()
 * @method static Builder<static>|AdvertisementResponse newQuery()
 * @method static Builder<static>|AdvertisementResponse query()
 * @method static Builder<static>|AdvertisementResponse whereAdvertisementId($value)
 * @method static Builder<static>|AdvertisementResponse whereCreatedAt($value)
 * @method static Builder<static>|AdvertisementResponse whereId($value)
 * @method static Builder<static>|AdvertisementResponse whereUpdatedAt($value)
 * @method static Builder<static>|AdvertisementResponse whereUserId($value)
 *
 * @mixin Eloquent
 */
class AdvertisementResponse extends Model
{
    /** @use HasFactory<AdvertisementResponseFactory> */
    use HasFactory;

    protected $fillable = [
        'advertisement_id',
        'user_id',
    ];

    public function advertisement(): BelongsTo
    {
        return $this->belongsTo(Advertisement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
