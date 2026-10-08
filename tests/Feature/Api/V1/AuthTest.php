<?php

use App\Models\Sector;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $sector = Sector::query()->create(['name' => 'Operations']);

    $this->user = User::factory()->create([
        'sector_id' => $sector->id,
    ]);
    $this->user->assignRole(Role::query()->create([
        'name' => 'client',
        'guard_name' => 'web',
    ]));

    $ip = request()->ip();
    RateLimiter::clear(md5('api'.$ip));
    RateLimiter::clear(md5('login'.Str::transliterate(Str::lower($this->user->email)).'|'.$ip));
    RateLimiter::clear(md5('login|'.$ip));
});

it('logs in and returns a Sanctum token with the user roles and sector', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $this->user->email,
        'password' => 'password',
        'device_name' => 'test-device',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'token',
            'user' => ['id', 'name', 'email', 'roles', 'sector' => ['id', 'name']],
        ])
        ->assertJsonPath('user.roles', ['client'])
        ->assertJsonPath('user.sector.name', 'Operations')
        ->assertJsonMissingPath('user.password');

    expect($this->user->fresh()->last_login_at)->not->toBeNull()
        ->and($this->user->tokens()->count())->toBe(1);
});

it('rejects invalid credentials without creating a token', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => $this->user->email,
        'password' => 'incorrect',
        'device_name' => 'test-device',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    expect($this->user->tokens()->count())->toBe(0);
});

it('validates all required login fields', function () {
    $this->postJson('/api/v1/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password', 'device_name']);
});

it('requires authentication to access current user and logout endpoints', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
});

it('returns a JSON unauthorized response for API requests without an Accept header', function () {
    $this->call('GET', '/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('returns the authenticated user and revokes the current token on logout', function () {
    $token = $this->user->createToken('test-device');

    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('id', $this->user->id)
        ->assertJsonPath('roles', ['client'])
        ->assertJsonPath('sector.name', 'Operations')
        ->assertJsonMissingPath('password');

    $tokenId = $token->accessToken->id;

    $this->withToken($token->plainTextToken)
        ->postJson('/api/v1/auth/logout')
        ->assertOk()
        ->assertExactJson(['message' => 'Logged out successfully.']);

    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    Auth::forgetGuards();

    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});

it('limits repeated login attempts', function () {
    $credentials = [
        'email' => $this->user->email,
        'password' => 'incorrect',
        'device_name' => 'test-device',
    ];

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/login', $credentials)->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/login', $credentials)->assertTooManyRequests();
});

it('limits API traffic to 60 requests per minute', function () {
    RateLimiter::clear(md5('api192.0.2.1'));
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1']);

    foreach (range(1, 60) as $_) {
        $this->getJson('/api/v1/status')->assertOk();
    }

    $this->getJson('/api/v1/status')->assertTooManyRequests();

    RateLimiter::clear(md5('api192.0.2.1'));
    RateLimiter::clear(md5('api127.0.0.1'));
});

it('seeds the standard roles idempotently', function () {
    $seeder = new RoleSeeder;
    $seeder->run();
    $seeder->run();

    expect(Role::query()->where('guard_name', 'web')->count())->toBe(6);
});

it('enforces the documented role gates', function () {
    $role = fn (string $name) => Role::query()->firstOrCreate([
        'name' => $name,
        'guard_name' => 'web',
    ]);

    expect(Gate::forUser($this->user)->allows('triage-work-orders'))->toBeFalse();
    expect(Gate::forUser($this->user)->allows('view-users'))->toBeFalse();

    $this->user->syncRoles([$role('planner')]);
    $planner = $this->user->fresh();
    expect(Gate::forUser($planner)->allows('manage-users'))->toBeFalse()
        ->and(Gate::forUser($planner)->allows('view-users'))->toBeTrue()
        ->and(Gate::forUser($planner)->allows('view-reports'))->toBeTrue()
        ->and(Gate::forUser($planner)->allows('triage-work-orders'))->toBeTrue()
        ->and(Gate::forUser($planner)->allows('close-work-orders'))->toBeTrue()
        ->and(Gate::forUser($planner)->allows('manage-preventive-plans'))->toBeTrue();

    $this->user->syncRoles([$role('manager')]);
    $manager = $this->user->fresh();
    expect(Gate::forUser($manager)->allows('view-reports'))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('view-users'))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('triage-work-orders'))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('manage-preventive-plans'))->toBeFalse();

    $this->user->syncRoles([$role('maintainer')]);
    $maintainer = $this->user->fresh();
    expect(Gate::forUser($maintainer)->allows('close-work-orders'))->toBeTrue()
        ->and(Gate::forUser($maintainer)->allows('triage-work-orders'))->toBeFalse();

    $this->user->syncRoles([$role('system_admin')]);
    $admin = $this->user->fresh();
    foreach (['view-users', 'manage-users', 'view-reports', 'triage-work-orders', 'close-work-orders', 'manage-preventive-plans'] as $ability) {
        expect(Gate::forUser($admin)->allows($ability))->toBeTrue();
    }
});
