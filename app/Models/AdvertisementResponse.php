<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdvertisementResponseStatus;
use Database\Factories\AdvertisementResponseFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * docker compose exec app php artisan ide-helper:models "App\Models\AdvertisementResponse"
 *
 * @property int $id
 * @property int $advertisement_id
 * @property int $user_id
 * @property AdvertisementResponseStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Advertisement $advertisement
 * @property-read Conversation|null $conversation
 * @property-read Collection<int, AdvertisementResponse> $responses
 * @property-read int|null $responses_count
 * @property-read User $user
 *
 * @method static AdvertisementResponseFactory factory($count = null, $state = [])
 * @method static Builder<static>|AdvertisementResponse newModelQuery()
 * @method static Builder<static>|AdvertisementResponse newQuery()
 * @method static Builder<static>|AdvertisementResponse query()
 * @method static Builder<static>|AdvertisementResponse whereAdvertisementId($value)
 * @method static Builder<static>|AdvertisementResponse whereCreatedAt($value)
 * @method static Builder<static>|AdvertisementResponse whereId($value)
 * @method static Builder<static>|AdvertisementResponse whereStatus($value)
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
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => AdvertisementResponseStatus::class,
        ];
    }

    public function advertisement(): BelongsTo
    {
        return $this->belongsTo(Advertisement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(AdvertisementResponse::class);
    }
}
