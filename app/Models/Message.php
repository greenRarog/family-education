<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MessageFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docker compose exec app php artisan ide-helper:models "App\Models\Message"
 *
 * @property int $id
 * @property int $conversation_id
 * @property int $user_id
 * @property string $body
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Conversation $conversation
 * @property-read User $user
 *
 * @method static MessageFactory factory($count = null, $state = [])
 * @method static Builder<static>|Message newModelQuery()
 * @method static Builder<static>|Message newQuery()
 * @method static Builder<static>|Message query()
 * @method static Builder<static>|Message whereBody($value)
 * @method static Builder<static>|Message whereConversationId($value)
 * @method static Builder<static>|Message whereCreatedAt($value)
 * @method static Builder<static>|Message whereId($value)
 * @method static Builder<static>|Message whereReadAt($value)
 * @method static Builder<static>|Message whereUpdatedAt($value)
 * @method static Builder<static>|Message whereUserId($value)
 *
 * @mixin Eloquent
 */
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    protected $table = 'messages';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'body',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
