<?php

declare(strict_types=1);

use App\Http\Controllers\v1\Api\Admin\AdvertisementModerationController;
use App\Http\Controllers\v1\Api\Admin\BlockedTermController;
use App\Http\Controllers\v1\Api\Admin\CityController;
use App\Http\Controllers\v1\Api\Admin\DashboardController;
use App\Http\Controllers\v1\Api\Admin\DistrictController;
use App\Http\Controllers\v1\Api\Admin\MetroStationController;
use App\Http\Controllers\v1\Api\Admin\ReportModerationController;
use App\Http\Controllers\v1\Api\Admin\SubjectController;
use App\Http\Controllers\v1\Api\Admin\TeacherProfileController as AdminTeacherProfileController;
use App\Http\Controllers\v1\Api\Admin\UserBlockController;
use App\Http\Controllers\v1\Api\Admin\UserModerationController;
use App\Http\Controllers\v1\Api\AdvertisementController;
use App\Http\Controllers\v1\Api\AdvertisementResponseController;
use App\Http\Controllers\v1\Api\ConversationController;
use App\Http\Controllers\v1\Api\FamilyController;
use App\Http\Controllers\v1\Api\LocationController;
use App\Http\Controllers\v1\Api\MessageController;
use App\Http\Controllers\v1\Api\NotificationSettingController;
use App\Http\Controllers\v1\Api\ReportController;
use App\Http\Controllers\v1\Api\TeacherController;
use App\Http\Controllers\v1\Api\UserLanguageController;
use App\Http\Middleware\EnforceModeration;
use App\Http\Middleware\ThrottleAdvertisementRequests;
use Illuminate\Support\Facades\Route;

Route::get('/cities', [LocationController::class, 'cities']);
Route::get('/cities/{city}/districts', [LocationController::class, 'districts']);
Route::get('/cities/{city}/metro-stations', [LocationController::class, 'metroStations']);
Route::get('/subjects', [SubjectController::class, 'index']);

Route::middleware('web')->group(function () {
    Route::get('/csrf', static fn () => response()->noContent());
    Route::get('/advertisements/feed', [AdvertisementController::class, 'feed']);
    Route::get('/advertisements/{advertisement}', [AdvertisementController::class, 'show']);
    Route::get('/teachers/{user}', [TeacherController::class, 'publicProfile']);
});
Route::middleware(['web', 'auth'])->group(function () {
    Route::post('/reports', [ReportController::class, 'store'])->middleware(ThrottleAdvertisementRequests::using('reports-create'));
    Route::put('/user/language', [UserLanguageController::class, 'update']);
    Route::get('/advertisements', [AdvertisementController::class, 'index']);
    Route::get('/advertisements/{advertisement}/edit', [AdvertisementController::class, 'edit']);
    Route::post('/advertisements', [AdvertisementController::class, 'store'])
        ->middleware(ThrottleAdvertisementRequests::using('ads-create'))->middleware(EnforceModeration::class);
    Route::put('/advertisements/{advertisement}', [AdvertisementController::class, 'update'])->middleware(EnforceModeration::class);
    Route::post('/advertisements/{advertisement}/publish', [AdvertisementController::class, 'publish'])
        ->middleware(ThrottleAdvertisementRequests::using('ads-publish'))->middleware(EnforceModeration::class);
    Route::post('/advertisements/{advertisement}/close', [AdvertisementController::class, 'close'])->middleware(EnforceModeration::class);
    Route::post('/advertisements/{advertisement}/responses', [AdvertisementResponseController::class, 'store'])
        ->middleware(ThrottleAdvertisementRequests::using('responses-create'))->middleware(EnforceModeration::class);
    Route::post('/advertisement-responses/{advertisementResponse}/accept', [AdvertisementResponseController::class, 'accept'])->middleware(EnforceModeration::class);
    Route::post('/advertisement-responses/{advertisementResponse}/reject', [AdvertisementResponseController::class, 'reject'])->middleware(EnforceModeration::class);

    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/unread-count', [ConversationController::class, 'unreadCount']);
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
    Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages']);
    Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store'])
        ->middleware(ThrottleAdvertisementRequests::using('messages-create'))->middleware(EnforceModeration::class);
    Route::post('/conversations/{conversation}/read', [MessageController::class, 'read']);

    Route::get('/family', [FamilyController::class, 'show']);
    Route::put('/family', [FamilyController::class, 'update'])->middleware(EnforceModeration::class);
    Route::get('/teacher-profile', [TeacherController::class, 'show']);
    Route::put('/teacher-profile', [TeacherController::class, 'update'])->middleware(EnforceModeration::class);

    Route::get('/locations', [LocationController::class, 'index']);

    Route::get('/notification-settings', [NotificationSettingController::class, 'show']);
    Route::put('/notification-settings', [NotificationSettingController::class, 'update']);
});

Route::middleware(['auth', 'admin', 'web'])
    ->prefix('admin')
    ->group(function () {
        Route::get('dashboard', DashboardController::class);
        Route::get('reports', [ReportModerationController::class, 'index']);
        Route::get('reports/{report}', [ReportModerationController::class, 'show']);
        Route::get('reports/{report}/history', [ReportModerationController::class, 'history']);
        Route::post('reports/{report}/resolve', [ReportModerationController::class, 'resolve']);
        Route::get('advertisements', [AdvertisementModerationController::class, 'index']);
        Route::get('advertisements/{advertisement}', [AdvertisementModerationController::class, 'show']);
        Route::get('advertisements/{advertisement}/history', [AdvertisementModerationController::class, 'history']);
        Route::post('advertisements/{advertisement}/appeal-rejected', [AdvertisementModerationController::class, 'rejectAppeal']);
        Route::post('advertisements/{advertisement}/hide', [AdvertisementModerationController::class, 'hide']);
        Route::post('advertisements/{advertisement}/restore', [AdvertisementModerationController::class, 'restore']);
        Route::get('users', [UserModerationController::class, 'index']);
        Route::get('users/{user}', [UserModerationController::class, 'show']);
        Route::get('users/{user}/history', [UserModerationController::class, 'history']);
        Route::post('users/{user}/appeal-rejected', [UserModerationController::class, 'rejectAppeal']);
        Route::post('users/{user}/block', [UserBlockController::class, 'block']);
        Route::post('users/{user}/unblock', [UserBlockController::class, 'unblock']);
        Route::apiResource('cities', CityController::class);
        Route::apiResource('districts', DistrictController::class);
        Route::apiResource('metro-stations', MetroStationController::class);
        Route::apiResource('subjects', SubjectController::class);
        Route::apiResource('blocked-terms', BlockedTermController::class);
        Route::get('teacher-profiles', [AdminTeacherProfileController::class, 'index']);
        Route::post('teacher-profiles/{teacherProfile:user_id}/approve', [AdminTeacherProfileController::class, 'approve'])->middleware(EnforceModeration::class);
        Route::post('teacher-profiles/{teacherProfile:user_id}/reject', [AdminTeacherProfileController::class, 'reject'])->middleware(EnforceModeration::class);
    });
