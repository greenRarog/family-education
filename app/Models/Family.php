<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FamilyFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docker compose exec app php artisan ide-helper:models "App\Models\Family"
 *
 * @property int $id
 * @property int $user_id
 * @property string $surname
 * @property int $city_id
 * @property int|null $district_id
 * @property int|null $metro_station_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Child> $children
 * @property-read int|null $children_count
 * @property-read City $city
 * @property-read District|null $district
 * @property-read MetroStation|null $metroStation
 * @property-read User $user
 *
 * @method static FamilyFactory factory($count = null, $state = [])
 * @method static Builder<static>|Family newModelQuery()
 * @method static Builder<static>|Family newQuery()
 * @method static Builder<static>|Family query()
 * @method static Builder<static>|Family whereCityId($value)
 * @method static Builder<static>|Family whereCreatedAt($value)
 * @method static Builder<static>|Family whereDistrictId($value)
 * @method static Builder<static>|Family whereId($value)
 * @method static Builder<static>|Family whereMetroStationId($value)
 * @method static Builder<static>|Family whereSurname($value)
 * @method static Builder<static>|Family whereUpdatedAt($value)
 * @method static Builder<static>|Family whereUserId($value)
 *
 * @mixin Eloquent
 */
class Family extends Model
{
    /** @use HasFactory<FamilyFactory> */
    use HasFactory;

    protected $table = 'families';

    protected $fillable = [
        'user_id',
        'surname',
        'city_id',
        'district_id',
        'metro_station_id',
    ];

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

    public function children(): HasMany
    {
        return $this->hasMany(Child::class);
    }
}
