<?php

use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function userManagementActor(string $role): User
{
    $roleModel = Role::query()->firstOrCreate([
        'name' => $role,
        'guard_name' => 'web',
    ]);

    $user = User::factory()->create();
    $user->assignRole($roleModel);

    return $user;
}

it('requires authentication to list users', function () {
    $this->getJson('/api/v1/users')->assertUnauthorized();
});

it('requires authentication to create, update, and deactivate users', function () {
    $user = User::factory()->create();

    $this->postJson('/api/v1/users', [])->assertUnauthorized();
    $this->putJson("/api/v1/users/{$user->id}", [])->assertUnauthorized();
    $this->deleteJson("/api/v1/users/{$user->id}")->assertUnauthorized();
});

it('allows planners and system administrators to list users', function () {
    $planner = userManagementActor('planner');

    $this->actingAs($planner, 'sanctum')
        ->getJson('/api/v1/users')
        ->assertOk();
});

it('filters the paginated user list by role and sector', function () {
    $admin = userManagementActor('system_admin');
    $sector = Sector::query()->create(['name' => 'Facilities']);
    $matchingUser = User::factory()->create([
        'name' => 'Matching User',
        'sector_id' => $sector->id,
    ]);
    $matchingUser->assignRole(Role::query()->firstOrCreate([
        'name' => 'maintainer',
        'guard_name' => 'web',
    ]));

    $otherUser = User::factory()->create([
        'name' => 'Other User',
        'sector_id' => $sector->id,
    ]);
    $otherUser->assignRole(Role::query()->firstOrCreate([
        'name' => 'client',
        'guard_name' => 'web',
    ]));

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/users?role=maintainer&sector_id={$sector->id}&per_page=1")
        ->assertOk()
        ->assertJsonPath('data.0.id', $matchingUser->id)
        ->assertJsonPath('data.0.roles', ['maintainer'])
        ->assertJsonPath('data.0.sector.name', 'Facilities')
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonMissing(['id' => $otherUser->id]);
});

it('rejects unknown user-list filters', function () {
    $admin = userManagementActor('system_admin');

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/users?role=not-a-role&sector_id=999')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role', 'sector_id']);
});

it('forbids users without the user-list permission from listing users', function () {
    $client = userManagementActor('client');

    $this->actingAs($client, 'sanctum')
        ->getJson('/api/v1/users')
        ->assertForbidden();
});

it('creates a user with the requested roles and sector without exposing credentials', function () {
    $admin = userManagementActor('system_admin');
    $sector = Sector::query()->create(['name' => 'Operations']);
    Role::query()->firstOrCreate(['name' => 'maintainer', 'guard_name' => 'web']);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/users', [
            'name' => 'Maria Souza',
            'email' => 'maria@example.com',
            'username' => 'maria.souza',
            'phone' => '+5511999998888',
            'password' => 'a-strong-password',
            'sector_id' => $sector->id,
            'roles' => ['maintainer'],
            'deleted_at' => now()->toISOString(),
        ])
        ->assertCreated()
        ->assertJsonPath('name', 'Maria Souza')
        ->assertJsonPath('roles', ['maintainer'])
        ->assertJsonPath('sector.name', 'Operations')
        ->assertJsonMissingPath('password')
        ->assertJsonMissingPath('deleted_at');

    $user = User::query()->where('email', 'maria@example.com')->firstOrFail();
    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'email' => 'maria@example.com',
        'deleted_at' => null,
    ]);
    expect($user->hasRole('maintainer'))->toBeTrue()
        ->and(Hash::check('a-strong-password', $user->password))->toBeTrue();
});

it('forbids planners from creating users', function () {
    $planner = userManagementActor('planner');

    $this->actingAs($planner, 'sanctum')
        ->postJson('/api/v1/users', [])
        ->assertForbidden();

    $this->assertDatabaseCount('users', 1);
});

it('validates required user fields and role assignment', function () {
    $admin = userManagementActor('system_admin');

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/users', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email', 'password', 'roles']);

    $this->postJson('/api/v1/users', [
        'name' => 'New User',
        'email' => 'new@example.com',
        'password' => 'a-strong-password',
        'roles' => ['unknown-role'],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('roles.0');

    $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
});

it('rejects duplicate emails and usernames when creating users', function () {
    $admin = userManagementActor('system_admin');
    $existing = User::factory()->create([
        'email' => 'existing@example.com',
        'username' => 'existing.user',
    ]);
    Role::query()->firstOrCreate(['name' => 'maintainer', 'guard_name' => 'web']);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/users', [
            'name' => 'Duplicate User',
            'email' => $existing->email,
            'username' => $existing->username,
            'password' => 'a-strong-password',
            'roles' => ['maintainer'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'username']);

    $this->assertDatabaseMissing('users', ['name' => 'Duplicate User']);
});

it('rejects duplicate role assignments and deactivated sectors', function () {
    $admin = userManagementActor('system_admin');
    $sector = Sector::query()->create(['name' => 'Closed Sector']);
    $sector->delete();

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/users', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'a-strong-password',
            'sector_id' => $sector->id,
            'roles' => ['maintainer', 'maintainer'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sector_id', 'roles.1']);

    $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
});

it('updates a user partially and synchronizes their roles', function () {
    $admin = userManagementActor('system_admin');
    Role::query()->firstOrCreate(['name' => 'maintainer', 'guard_name' => 'web']);
    $user = User::factory()->create([
        'name' => 'Original Name',
        'password' => 'original-password',
    ]);
    $user->assignRole(Role::query()->firstOrCreate([
        'name' => 'client',
        'guard_name' => 'web',
    ]));

    $this->actingAs($admin, 'sanctum')
        ->putJson("/api/v1/users/{$user->id}", [
            'name' => 'Updated Name',
            'email' => $user->email,
            'roles' => ['maintainer'],
        ])
        ->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('name', 'Updated Name')
        ->assertJsonPath('roles', ['maintainer'])
        ->assertJsonMissingPath('password');

    $user->refresh();
    expect($user->hasRole('maintainer'))->toBeTrue()
        ->and($user->hasRole('client'))->toBeFalse()
        ->and(Hash::check('original-password', $user->password))->toBeTrue();
});

it('does not allow a planner to update or deactivate a user', function () {
    $planner = userManagementActor('planner');
    $user = User::factory()->create(['name' => 'Protected User']);

    $this->actingAs($planner, 'sanctum')
        ->putJson("/api/v1/users/{$user->id}", ['name' => 'Changed'])
        ->assertForbidden();

    $this->deleteJson("/api/v1/users/{$user->id}")
        ->assertForbidden();

    expect($user->fresh()->name)->toBe('Protected User')
        ->and($user->fresh()->deleted_at)->toBeNull();
});

it('soft deletes a user and revokes their Sanctum tokens', function () {
    $admin = userManagementActor('system_admin');
    $user = User::factory()->create();
    $token = $user->createToken('test-device');

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/v1/users/{$user->id}")
        ->assertOk()
        ->assertExactJson(['message' => 'User deactivated successfully.']);

    $this->assertSoftDeleted($user);
    $this->assertDatabaseMissing('personal_access_tokens', [
        'id' => $token->accessToken->id,
    ]);
    $this->assertDatabaseMissing('users', [
        'id' => $user->id,
        'deleted_at' => null,
    ]);
});

it('returns not found when updating a soft-deleted user', function () {
    $admin = userManagementActor('system_admin');
    $user = User::factory()->create();
    $user->delete();

    $this->actingAs($admin, 'sanctum')
        ->putJson("/api/v1/users/{$user->id}", ['name' => 'Changed'])
        ->assertNotFound();
});
