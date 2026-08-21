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
use Illuminate\Support\Carbon;

/**
 * docker compose exec app php artisan ide-helper:models "App\Models\Conversation"
 *
 * @property int $id
 * @property int $advertisement_response_id
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

    public function advertisementResponse(): BelongsTo
    {
        return $this->belongsTo(AdvertisementResponse::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
