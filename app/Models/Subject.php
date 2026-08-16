<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SubjectFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * docker compose exec app php artisan ide-helper:models "App\Models\Subject"
 *
 * @property int $id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static SubjectFactory factory($count = null, $state = [])
 * @method static Builder<static>|Subject newModelQuery()
 * @method static Builder<static>|Subject newQuery()
 * @method static Builder<static>|Subject query()
 * @method static Builder<static>|Subject whereCreatedAt($value)
 * @method static Builder<static>|Subject whereId($value)
 * @method static Builder<static>|Subject whereName($value)
 * @method static Builder<static>|Subject whereUpdatedAt($value)
 *
 * @mixin Eloquent
 */
class Subject extends Model
{
    /** @use HasFactory<SubjectFactory> */
    use HasFactory;

    protected $table = 'subjects';

    protected $fillable = [
        'name',
    ];
}
