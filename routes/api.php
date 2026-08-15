<?php

declare(strict_types=1);

use App\Http\Controllers\v1\Api\LocationController;
use Illuminate\Support\Facades\Route;

Route::get('/cities', [LocationController::class, 'cities']);
Route::get('/cities/{city}/districts', [LocationController::class, 'districts']);
Route::get('/cities/{city}/metro-stations', [LocationController::class, 'metroStations']);
