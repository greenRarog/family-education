<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportContextType;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $reporter_id
 * @property string $target_type
 * @property int $target_id
 * @property ReportContextType $context_type
 * @property int $context_id
 * @property ReportReason $reason
 * @property string|null $comment
 * @property array<string, mixed> $snapshot
 * @property ReportStatus $status
 * @property int|null $resolved_by
 * @property string|null $resolution_note
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $reporter
 * @property-read User|null $resolver
 * @property-read Advertisement|User|null $target
 */
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    protected $fillable = [
        'reporter_id', 'target_type', 'target_id', 'context_type', 'context_id',
        'reason', 'comment', 'snapshot',
    ];

    protected function casts(): array
    {
        return [
            // MorphTo expects a string alias, not a backed enum.
            'context_type' => ReportContextType::class,
            'reason' => ReportReason::class,
            'status' => ReportStatus::class,
            'snapshot' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @return MorphTo<Model, $this> */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsToMany<AdminAction, $this> */
    public function resolutionActions(): BelongsToMany
    {
        return $this->belongsToMany(AdminAction::class, 'report_resolution_actions');
    }

    /** @return HasMany<AdminAction, $this> */
    public function adminActions(): HasMany
    {
        return $this->hasMany(AdminAction::class);
    }
}
