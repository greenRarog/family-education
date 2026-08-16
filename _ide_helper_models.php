<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * docker compose exec app php artisan ide-helper:models "App\Models\City"
 *
 * @property int $id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, District> $districts
 * @property-read int|null $districts_count
 * @property-read Collection<int, Family> $families
 * @property-read int|null $families_count
 * @property-read Collection<int, MetroStation> $metroStations
 * @property-read int|null $metro_stations_count
 * @method static CityFactory factory($count = null, $state = [])
 * @method static Builder<static>|City newModelQuery()
 * @method static Builder<static>|City newQuery()
 * @method static Builder<static>|City query()
 * @method static Builder<static>|City whereCreatedAt($value)
 * @method static Builder<static>|City whereId($value)
 * @method static Builder<static>|City whereName($value)
 * @method static Builder<static>|City whereUpdatedAt($value)
 * @mixin Eloquent
 */
	class City extends \Eloquent {}
}

