<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('app');
});
Route::get('/register', function () {
    return view('app');
});
Route::get('/login', function () {
    return view('app');
});
