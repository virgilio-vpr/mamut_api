<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ApiResponse;
use Illuminate\Http\JsonResponse;

class MainController extends Controller
{
    public function status(): JsonResponse
    {
        return ApiResponse::success(
            ['status' => 'ok'],
            'API is running.',
        );
    }
}
