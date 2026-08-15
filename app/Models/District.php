<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DistrictFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docker compose exec app php artisan ide-helper:models "App\Models\District"
 *
 * @property int $id
 * @property int $city_id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read City $city
 *
 * @method static DistrictFactory factory($count = null, $state = [])
 * @method static Builder<static>|District newModelQuery()
 * @method static Builder<static>|District newQuery()
 * @method static Builder<static>|District query()
 * @method static Builder<static>|District whereCityId($value)
 * @method static Builder<static>|District whereCreatedAt($value)
 * @method static Builder<static>|District whereId($value)
 * @method static Builder<static>|District whereName($value)
 * @method static Builder<static>|District whereUpdatedAt($value)
 *
 * @mixin Eloquent
 */
class District extends Model
{
    /** @use HasFactory<DistrictFactory> */
    use HasFactory;

    protected $table = 'districts';

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
