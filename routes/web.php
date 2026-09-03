<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

//Route::get('/admin', function () {
//    return view('app');
//});
//Route::get('/admin/users', function () {
//    return view('app');
//});
//Route::get('/admin/subjects', function () {
//    return view('app');
//});
//Route::get('/admin/blocked-terms', function () {
//    return view('app');
//});
//Route::get('/admin/cities', function () {
//    return view('app');
//});
//Route::get('/admin/reports', function () {
//    return view('app');
//});
//Route::get('/admin/advertisements', function () {
//    return view('app');
//});
//Route::get('/family/profile', function () {
//    return view('app');
//});
//Route::get('/advertisements', function () {
//    return view('app');
//});
//
//Route::get('/my-advertisements', function () {
//    return view('app');
//});
//Route::get('/advertisements/create', function () {
//    return view('app');
//});
//Route::get('/advertisements/new', function () {
//    return view('app');
//});
//Route::get('/advertisements/new-teacher', function () {
//    return view('app');
//});
//Route::middleware(['web', 'auth'])->group(function () {
//    Route::get('/advertisements/{advertisement}/edit', function () {
//        return view('app');
//    })->whereNumber('advertisement');
//});
//Route::get('/conversations/{conversation}', function () {
//    return view('app');
//})->whereNumber('conversation');
//Route::get('/conversations', function () {
//    return view('app');
//});
//Route::get('/advertisements/{advertisement}', function () {
//    return view('app');
//})->whereNumber('advertisement');
//Route::get('/reset-password/{token}', function () {
//    return view('app');
//})->name('password.reset');
//Route::get('/forgot-password', function () {
//    return view('app');
//});
//Route::get('/register', function () {
//    return view('app');
//});
//Route::get('/login', function () {
//    return view('app');
//})->name('login');
Route::get('/api/user', function () {
    if (! Auth::check()) {
        return response()->json([
            'authenticated' => false,
            'user' => null,
        ]);
    }
    $user = Auth::user();

    return response()->json([
        'authenticated' => true,
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ],
    ]);
});
//Route::get('/', function () {
//    return view('app');
//});
