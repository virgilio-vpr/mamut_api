<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexUsersRequest;
use App\Http\Requests\Api\V1\StoreUserRequest;
use App\Http\Requests\Api\V1\UpdateUserRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function index(IndexUsersRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $users = User::query()
            ->with(['roles', 'sector'])
            ->when(
                isset($filters['role']),
                fn (Builder $query) => $query->whereHas(
                    'roles',
                    fn (Builder $roles) => $roles
                        ->where('name', $filters['role'])
                        ->where('guard_name', 'web'),
                ),
            )
            ->when(
                isset($filters['sector_id']),
                fn (Builder $query) => $query->where('sector_id', $filters['sector_id']),
            )
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 20);

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data): User {
            $user = User::query()->create(Arr::only($data, [
                'sector_id',
                'name',
                'email',
                'username',
                'phone',
                'password',
            ]));
            $user->syncRoles($data['roles']);

            return $user->load(['roles', 'sector']);
        });

        return UserResource::make($user)->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data, $user): User {
            $user->update(Arr::only($data, [
                'sector_id',
                'name',
                'email',
                'username',
                'phone',
                'password',
            ]));

            if (array_key_exists('roles', $data)) {
                $user->syncRoles($data['roles']);
            }

            return $user->load(['roles', 'sector']);
        });

        return UserResource::make($user);
    }

    public function destroy(User $user): JsonResponse
    {
        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            $user->delete();
        });

        return response()->json(['message' => 'User deactivated successfully.']);
    }
}
