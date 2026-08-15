<?php

declare(strict_types=1);

use App\Http\Controllers\v1\Api\FamilyController;
use App\Http\Controllers\v1\Api\LocationController;
use Illuminate\Support\Facades\Route;

Route::get('/cities', [LocationController::class, 'cities']);
Route::get('/cities/{city}/districts', [LocationController::class, 'districts']);
Route::get('/cities/{city}/metro-stations', [LocationController::class, 'metroStations']);
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/family', [FamilyController::class, 'show']);
    Route::put('/family', [FamilyController::class, 'update']);

    Route::get('/locations', [LocationController::class, 'index']);
});
