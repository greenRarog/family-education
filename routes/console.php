<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('advertisements:close-expired')
    ->hourly()->withoutOverlapping();

Schedule::command('accounts:purge-expired')->hourly()->withoutOverlapping();
Schedule::command('accounts:confirm-deletions')->hourly()->withoutOverlapping();

Schedule::command('telegram:prune')->hourly()->withoutOverlapping();

Schedule::command('max:prune')->hourly()->withoutOverlapping();
