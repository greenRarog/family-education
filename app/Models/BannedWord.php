<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BannedWordFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * docker compose exec app php artisan ide-helper:models "App\Models\BannedWord"
 *
 * @property int $id
 * @property string $word
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static BannedWordFactory factory($count = null, $state = [])
 * @method static Builder<static>|BannedWord newModelQuery()
 * @method static Builder<static>|BannedWord newQuery()
 * @method static Builder<static>|BannedWord query()
 * @method static Builder<static>|BannedWord whereCreatedAt($value)
 * @method static Builder<static>|BannedWord whereId($value)
 * @method static Builder<static>|BannedWord whereUpdatedAt($value)
 * @method static Builder<static>|BannedWord whereWord($value)
 *
 * @mixin Eloquent
 */
class BannedWord extends Model
{
    /** @use HasFactory<BannedWordFactory> */
    use HasFactory;

    protected $table = 'banned_words';

    protected $fillable = [
        'word',
    ];
}
