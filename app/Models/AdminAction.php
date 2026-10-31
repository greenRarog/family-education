<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdminActionType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * @property int $id
 * @property int $actor_id
 * @property string $target_type
 * @property int $target_id
 * @property AdminActionType $action
 * @property string $reason_code
 * @property string $reason
 * @property array<string, bool|string> $before_state
 * @property array<string, bool|string> $after_state
 * @property int|null $report_id
 * @property int|null $related_action_id
 * @property CarbonImmutable $occurred_at
 * @property-read User $actor
 * @property-read User|Advertisement|Report|null $target
 * @property-read Report|null $report
 * @property-read AdminAction|null $relatedAction
 */
class AdminAction extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'action' => AdminActionType::class,
            'before_state' => 'array',
            'after_state' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Administrative actions are immutable.'));
        static::deleting(static fn () => throw new LogicException('Administrative actions are immutable.'));
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return MorphTo<Model, $this> */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Report, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /** @return BelongsTo<AdminAction, $this> */
    public function relatedAction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_action_id');
    }
}
