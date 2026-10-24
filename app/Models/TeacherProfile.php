<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TeacherProfileVisibilityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function metroStation(): BelongsTo
    {
        return $this->belongsTo(MetroStation::class);
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'teacher_profile_subject');
    }
}
