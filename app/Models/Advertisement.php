<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdvertisementStatus;
use App\Enums\AdvertisementType;
use Database\Factories\AdvertisementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property AdvertisementType $type
 * @property AdvertisementStatus $status
 */
class Advertisement extends Model
{
    /** @use HasFactory<AdvertisementFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'status',
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
            'type' => AdvertisementType::class,
            'status' => AdvertisementStatus::class,
            'published_at' => 'datetime',
            'closed_at' => 'datetime',
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

    public function children(): BelongsToMany
    {
        return $this->belongsToMany(Child::class);
    }
}
