<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $user_id
 * @property Carbon $deleted_at
 * @property string $policy_version
 * @property Carbon|null $registry_confirmed_at
 * @property Carbon|null $backups_cleared_at
 * @property string|null $request_token_hash
 */
class AccountDeletion extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['request_token_hash'];

    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
            'registry_confirmed_at' => 'datetime',
            'backups_cleared_at' => 'datetime',
        ];
    }
}
