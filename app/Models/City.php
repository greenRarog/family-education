<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CityFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docker compose exec app php artisan ide-helper:models "App\Models\City"
 *
 * @property int $id
 * @property string $name
 * @property string $fias_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, District> $districts
 * @property-read int|null $districts_count
 * @property-read Collection<int, Family> $families
 * @property-read int|null $families_count
 * @property-read Collection<int, MetroStation> $metroStations
 * @property-read int|null $metro_stations_count
 *
 * @method static CityFactory factory($count = null, $state = [])
 * @method static Builder<static>|City newModelQuery()
 * @method static Builder<static>|City newQuery()
 * @method static Builder<static>|City query()
 * @method static Builder<static>|City whereCreatedAt($value)
 * @method static Builder<static>|City whereFiasId($value)
 * @method static Builder<static>|City whereId($value)
 * @method static Builder<static>|City whereName($value)
 * @method static Builder<static>|City whereUpdatedAt($value)
 *
 * @mixin Eloquent
 */
class City extends Model
{
    /** @use HasFactory<CityFactory> */
    use HasFactory;

    protected $table = 'cities';

    protected $fillable = [
        'name',
    ];

    public function families(): HasMany
    {
        return $this->hasMany(Family::class);
    }

    public function districts(): HasMany
    {
        return $this->hasMany(District::class);
    }

    public function metroStations(): HasMany
    {
        return $this->hasMany(MetroStation::class);
    }
}
