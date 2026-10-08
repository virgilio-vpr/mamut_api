<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MainController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/status', [MainController::class, 'status']);

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/users', [UserController::class, 'index'])
        ->middleware('can:view-users')
        ->name('users.index');

    Route::apiResource('users', UserController::class)
        ->only(['store', 'update', 'destroy'])
        ->middleware('can:manage-users');
});
