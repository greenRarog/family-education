<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

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
