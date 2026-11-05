<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConversationFactory;
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
 * docker compose exec app php artisan ide-helper:models "App\Models\Conversation"
 *
 * @property int $id
 * @property int $advertisement_response_id
 * @property Carbon|null $account_deleted_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AdvertisementResponse $advertisementResponse
 * @property-read Collection<int, Message> $messages
 * @property-read int|null $messages_count
 *
 * @method static ConversationFactory factory($count = null, $state = [])
 * @method static Builder<static>|Conversation newModelQuery()
 * @method static Builder<static>|Conversation newQuery()
 * @method static Builder<static>|Conversation query()
 * @method static Builder<static>|Conversation whereAdvertisementResponseId($value)
 * @method static Builder<static>|Conversation whereCreatedAt($value)
 * @method static Builder<static>|Conversation whereId($value)
 * @method static Builder<static>|Conversation whereUpdatedAt($value)
 *
 * @mixin Eloquent
 */
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    protected $table = 'conversations';

    protected $fillable = [
        'advertisement_response_id',
    ];

    public function hasParticipant(User $user): bool
    {
        if ($user->isAccountDeleted()) {
            return false;
        }
        $this->loadMissing('advertisementResponse.advertisement');
        $response = $this->advertisementResponse;

        return $response->user_id === $user->id
            || $response->advertisement->user_id === $user->id;
    }

    protected function casts(): array
    {
        return ['account_deleted_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /** @param Builder<static> $query */
    public function scopeRetained(Builder $query): void
    {
        $query->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lte(now());
    }

    public function advertisementResponse(): BelongsTo
    {
        return $this->belongsTo(AdvertisementResponse::class);
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return HasOne<Message, $this> */
    public function lastMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }
}
