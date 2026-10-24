<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TeacherProfileVisibilityStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $surname
 * @property int $city_id
 * @property int|null $district_id
 * @property int|null $metro_station_id
 * @property string|null $about
 * @property string|null $education
 * @property bool $public_profile_requested
 * @property TeacherProfileVisibilityStatus $public_profile_status
 * @property Carbon|null $public_profile_reviewed_at
 * @property int|null $public_profile_reviewed_by
 * @property-read User $user
 * @property-read City $city
 * @property-read District|null $district
 * @property-read MetroStation|null $metroStation
 * @property-read Collection<int, Subject> $subjects
 */
class TeacherProfile extends Model
{
    protected $fillable = [
        'user_id',
        'surname',
        'city_id',
        'district_id',
        'metro_station_id',
        'about',
        'education',
        'public_profile_requested',
        'public_profile_status',
        'public_profile_reviewed_at',
        'public_profile_reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'public_profile_requested' => 'boolean',
            'public_profile_status' => TeacherProfileVisibilityStatus::class,
            'public_profile_reviewed_at' => 'datetime',
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

    /** @return BelongsToMany<Subject, $this> */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'teacher_profile_subject');
    }
}
