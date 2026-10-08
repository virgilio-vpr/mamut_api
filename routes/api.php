<?php

use App\Services\ApiResponse;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    require base_path('routes/api_v1.php');
});

Route::fallback(function () {
    return ApiResponse::error('Endpoint not found.', 404);
});
