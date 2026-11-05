<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdvertisementStatus;
use App\Enums\AdvertisementStudyFormat;
use App\Enums\AdvertisementType;
use App\Enums\LessonType;
use Database\Factories\AdvertisementFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * docker compose exec app php artisan ide-helper:models "App\Models\Advertisement"
 *
 * @property int|null $hide_action_id
 * @property Carbon|null $account_deleted_at
 * @property-read AdminAction|null $hidingAction
 * @property int $id
 * @property int $user_id
 * @property AdvertisementType $type
 * @property AdvertisementStatus $status
 * @property string|null $subject
 * @property AdvertisementStudyFormat|null $format
 * @property string $language
 * @property LessonType|null $lesson_type
 * @property int|null $city_id
 * @property int|null $district_id
 * @property int|null $metro_station_id
 * @property int|null $participant_age_from
 * @property int|null $participant_age_to
 * @property string $description
 * @property Carbon|null $published_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Child> $children
 * @property-read int|null $children_count
 * @property-read City $city
 * @property-read District|null $district
 * @property-read MetroStation|null $metroStation
 * @property-read Collection<int, AdvertisementResponse> $responses
 * @property-read int|null $responses_count
 * @property-read Collection<int, Subject> $subjects
 * @property-read int|null $subjects_count
 * @property-read User $user
 *
 * @method static AdvertisementFactory factory($count = null, $state = [])
 * @method static Builder<static>|Advertisement newModelQuery()
 * @method static Builder<static>|Advertisement newQuery()
 * @method static Builder<static>|Advertisement query()
 * @method static Builder<static>|Advertisement whereCityId($value)
 * @method static Builder<static>|Advertisement whereClosedAt($value)
 * @method static Builder<static>|Advertisement whereCreatedAt($value)
 * @method static Builder<static>|Advertisement whereDescription($value)
 * @method static Builder<static>|Advertisement whereDistrictId($value)
 * @method static Builder<static>|Advertisement whereFormat($value)
 * @method static Builder<static>|Advertisement whereId($value)
 * @method static Builder<static>|Advertisement whereMetroStationId($value)
 * @method static Builder<static>|Advertisement whereParticipantAgeFrom($value)
 * @method static Builder<static>|Advertisement whereParticipantAgeTo($value)
 * @method static Builder<static>|Advertisement wherePublishedAt($value)
 * @method static Builder<static>|Advertisement whereStatus($value)
 * @method static Builder<static>|Advertisement whereSubject($value)
 * @method static Builder<static>|Advertisement whereType($value)
 * @method static Builder<static>|Advertisement whereUpdatedAt($value)
 * @method static Builder<static>|Advertisement whereUserId($value)
 *
 * @mixin Eloquent
 */
class Advertisement extends Model
{
    /** @use HasFactory<AdvertisementFactory> */
    use HasFactory;

    protected $hidden = ['hide_action_id', 'hidingAction', 'hiding_action'];

    protected $fillable = [
        'user_id',
        'type',
        'status',
        'format',
        'language',
        'lesson_type',
        'city_id',
        'district_id',
        'metro_station_id',
        'participant_age_from',
        'participant_age_to',
        'description',
        'published_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'hide_action_id' => 'integer',
            'account_deleted_at' => 'datetime',
            'type' => AdvertisementType::class,
            'status' => AdvertisementStatus::class,
            'format' => AdvertisementStudyFormat::class,
            'lesson_type' => LessonType::class,
            'published_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function publicationExpiresAt(): ?Carbon
    {
        return $this->published_at?->copy()->utc()->addYearNoOverflow();
    }

    public function publicationExpired(?Carbon $at = null): bool
    {
        return $this->publicationExpiresAt()?->lte($at ?? now()) ?? false;
    }

    /** @param Builder<Advertisement> $query
     * @return Builder<Advertisement>
     */
    public function scopeExpired(Builder $query, ?Carbon $at = null): Builder
    {
        return $query->whereRaw($this->publicationDeadlineSql($query).' <= ?', [($at ?? now())->copy()->utc()->toDateTimeString()]);
    }

    /** @param Builder<Advertisement> $query
     * @return Builder<Advertisement>
     */
    public function scopeUnexpired(Builder $query, ?Carbon $at = null): Builder
    {
        // Legacy records without a publication date have no invented expiry date.
        return $query->where(fn (Builder $q) => $q->whereNull('published_at')
            ->orWhereRaw($this->publicationDeadlineSql($query).' > ?', [($at ?? now())->copy()->utc()->toDateTimeString()]));
    }

    /** Same calendar-year clamp as publicationExpiresAt(), before SQL pagination.
     * @param  Builder<Advertisement>  $query
     */
    private function publicationDeadlineSql(Builder $query): string
    {
        $column = $query->qualifyColumn('published_at');

        return match ($query->getModel()->getConnection()->getDriverName()) {
            'pgsql' => "($column + interval '1 year')",
            'sqlite' => "(CASE WHEN strftime('%m-%d', $column) = '02-29' THEN datetime($column, '+1 year', '-1 day') ELSE datetime($column, '+1 year') END)",
            default => throw new \LogicException('Advertisement expiry supports the configured PostgreSQL and SQLite stores.'),
        };
    }

    public function isHidden(): bool
    {
        return ! $this->isAccountDeleted() && $this->hide_action_id !== null;
    }

    public function isAccountDeleted(): bool
    {
        return $this->account_deleted_at !== null;
    }

    /** @return BelongsTo<AdminAction, $this> */
    public function hidingAction(): BelongsTo
    {
        return $this->belongsTo(AdminAction::class, 'hide_action_id');
    }

    /** Only expose to the owner or through an authorized admin endpoint.
     * @return array<string, mixed>
     */
    public function moderationDetails(): array
    {
        $decision = $this->isAccountDeleted() ? null : $this->hidingAction;

        return [
            'is_hidden' => $this->isHidden(), 'decision_id' => $decision?->id,
            'hidden_at' => $decision?->occurred_at->toISOString(),
            'reason_code' => $decision?->reason_code, 'reason' => $decision?->reason,
            'appeal_email' => config('moderation.appeal_email'),
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<District, $this> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /** @return BelongsTo<MetroStation, $this> */
    public function metroStation(): BelongsTo
    {
        return $this->belongsTo(MetroStation::class);
    }

    /** @return BelongsToMany<Child, $this> */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(Child::class);
    }

    /** @return BelongsToMany<Subject, $this> */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class);
    }

    /** @return HasMany<AdvertisementResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(AdvertisementResponse::class);
    }

    /** @return MorphMany<Report, $this> */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'target');
    }
}
