<?php

declare(strict_types=1);

use App\Http\Controllers\v1\Api\Admin\BlockedTermController;
use App\Http\Controllers\v1\Api\Admin\CityController;
use App\Http\Controllers\v1\Api\Admin\DistrictController;
use App\Http\Controllers\v1\Api\Admin\MetroStationController;
use App\Http\Controllers\v1\Api\Admin\SubjectController;
use App\Http\Controllers\v1\Api\AdvertisementController;
use App\Http\Controllers\v1\Api\FamilyController;
use App\Http\Controllers\v1\Api\LocationController;
use App\Http\Controllers\v1\Api\NotificationSettingController;
use Illuminate\Support\Facades\Route;

Route::get('/cities', [LocationController::class, 'cities']);
Route::get('/cities/{city}/districts', [LocationController::class, 'districts']);
Route::get('/cities/{city}/metro-stations', [LocationController::class, 'metroStations']);
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/advertisements', [AdvertisementController::class, 'index']);
    Route::post('/advertisements', [AdvertisementController::class, 'store']);
    Route::get('/advertisements/{advertisement}', [AdvertisementController::class, 'show']);
    Route::put('/advertisements/{advertisement}', [AdvertisementController::class, 'update']);
    Route::post('/advertisements/{advertisement}/publish', [AdvertisementController::class, 'publish']);
    Route::post('/advertisements/{advertisement}/close', [AdvertisementController::class, 'close']);

    Route::get('/family', [FamilyController::class, 'show']);
    Route::put('/family', [FamilyController::class, 'update']);

    Route::get('/locations', [LocationController::class, 'index']);

    Route::get('/notification-settings', [NotificationSettingController::class, 'show']);
    Route::put('/notification-settings', [NotificationSettingController::class, 'update']);
});

Route::middleware(['auth', 'admin', 'web'])
    ->prefix('admin')
    ->group(function () {
        Route::apiResource('cities', CityController::class);
        Route::apiResource('districts', DistrictController::class);
        Route::apiResource('metro-stations', MetroStationController::class);
        Route::apiResource('subjects', SubjectController::class);
        Route::apiResource('blocked-terms', BlockedTermController::class);
    });
