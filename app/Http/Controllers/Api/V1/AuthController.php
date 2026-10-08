<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\Api\V1\AuthUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\JsonResponse;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        $user->update(['last_login_at' => now()]);
        $user->load(['roles', 'sector']);

        return response()->json([
            'token' => $user->createToken($credentials['device_name'])->plainTextToken,
            'user' => AuthUserResource::make($user)->resolve($request),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        abort_unless($token instanceof PersonalAccessToken, 401);

        $token->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request): AuthUserResource
    {
        return AuthUserResource::make($request->user()->load(['roles', 'sector']));
    }
}
