<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Sex;
use Database\Factories\ChildFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docker compose exec app php artisan ide-helper:models "App\Models\Child"
 *
 * @property int $id
 * @property int $family_id
 * @property string $name
 * @property Carbon $birth_date
 * @property Sex $sex
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Family $family
 *
 * @method static ChildFactory factory($count = null, $state = [])
 * @method static Builder<static>|Child newModelQuery()
 * @method static Builder<static>|Child newQuery()
 * @method static Builder<static>|Child query()
 * @method static Builder<static>|Child whereBirthDate($value)
 * @method static Builder<static>|Child whereCreatedAt($value)
 * @method static Builder<static>|Child whereFamilyId($value)
 * @method static Builder<static>|Child whereId($value)
 * @method static Builder<static>|Child whereName($value)
 * @method static Builder<static>|Child whereSex($value)
 * @method static Builder<static>|Child whereUpdatedAt($value)
 *
 * @mixin Eloquent
 */
class Child extends Model
{
    /** @use HasFactory<ChildFactory> */
    use HasFactory;

    protected $table = 'children';

    protected $fillable = [
        'family_id',
        'name',
        'birth_date',
        'sex',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'sex' => Sex::class,
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }
}
