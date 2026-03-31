<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (Passport bearer tokens for product apps)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    $user = $request->user();

    return response()->json([
        'id' => $user->getAuthIdentifier(),
        'name' => $user->name,
        'email' => $user->email,
        'email_verified_at' => $user->email_verified_at?->toIso8601String(),
    ]);
});
