<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MetroStationFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docker compose exec app php artisan ide-helper:models "App\Models\MetroStation"
 *
 * @property int $id
 * @property int $city_id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read City $city
 *
 * @method static MetroStationFactory factory($count = null, $state = [])
 * @method static Builder<static>|MetroStation newModelQuery()
 * @method static Builder<static>|MetroStation newQuery()
 * @method static Builder<static>|MetroStation query()
 * @method static Builder<static>|MetroStation whereCityId($value)
 * @method static Builder<static>|MetroStation whereCreatedAt($value)
 * @method static Builder<static>|MetroStation whereId($value)
 * @method static Builder<static>|MetroStation whereName($value)
 * @method static Builder<static>|MetroStation whereUpdatedAt($value)
 *
 * @mixin Eloquent
 */
class MetroStation extends Model
{
    /** @use HasFactory<MetroStationFactory> */
    use HasFactory;

    protected $table = 'metro_stations';

    protected $fillable = [
        'name',
        'city_id',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function families(): HasMany
    {
        return $this->hasMany(Family::class);
    }
}
