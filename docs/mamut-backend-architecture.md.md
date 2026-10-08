# Mamut — Backend Architecture Specification
# Módulo: Gestão de Manutenção de Ativos e Predial

> **Project goal**: Build a scalable REST API for an Asset & Facility Maintenance Management System (CMMS).
> **Stack**: Laravel 13 + PostgreSQL 16 + Redis 7 + Docker (FrankenPHP).
> **Current module**: Asset & Facility Maintenance.
> **Future modules**: Materials Management, Utilities Consumption Management, Web Frontend, Mobile App.
> **Principles**: API-first, security-first, clean code, evolutionary architecture, step-by-step deliverables.

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Environment Setup](#2-environment-setup)
3. [Database Design](#3-database-design)
4. [Authentication & Authorization](#4-authentication--authorization)
5. [Eloquent Models & Relationships](#5-eloquent-models--relationships)
6. [Service Layer & Business Logic](#6-service-layer--business-logic)
7. [API Layer & Response Standard](#7-api-layer--response-standard)
8. [Preventive Maintenance Scheduling](#8-preventive-maintenance-scheduling)
9. [Reports & KPIs](#9-reports--kpis)
10. [Testing & QA](#10-testing--qa)
11. [Production Deployment & Expansion](#11-production-deployment--expansion)
12. [Appendix: Command Cheat Sheet](#12-appendix-command-cheat-sheet)

---

## 1. Architecture Overview

### 1.1 Core Philosophy

Mamut uses a strict **layered architecture** separating Controller, Service, Repository and Model. All business rules live in the Service layer. Controllers only validate requests and format responses. Models stay thin for easy testing.

```
┌─────────────────────────────────────────────┐
│                 API Routes                   │
│           (Routes + Middleware)              │
├─────────────────────────────────────────────┤
│              Controllers                     │
│        (Validation + Orchestration)          │
├─────────────────────────────────────────────┤
│                Services                      │
│           (Business Logic)                   │
├─────────────────────────────────────────────┤
│              Repositories                    │
│          (Data Access)                       │
├─────────────────────────────────────────────┤
│               Models                         │
│        (Eloquent + Relations)                │
├─────────────────────────────────────────────┤
│            PostgreSQL + Redis                │
└─────────────────────────────────────────────┘
```

### 1.2 Modular Design

To enable future expansion into **Materials** and **Utilities** modules, adopt a modular directory structure from day one:

```
app/
├── Modules/
│   ├── Maintenance/          # Current module
│   │   ├── Controllers/
│   │   ├── Models/
│   │   ├── Services/
│   │   ├── Repositories/
│   │   ├── Requests/
│   │   ├── Resources/
│   │   ├── Mail/
│   │   └── Jobs/
│   ├── Materials/            # Reserved for future
│   └── Utilities/            # Reserved for future
├── Shared/                   # Cross-module components
│   ├── Traits/
│   ├── Enums/
│   └── Contracts/
```

### 1.3 Security Requirements

| Security Requirement | Solution |
|---|---|
| Authentication | Laravel Sanctum (token-based) |
| Authorization | spatie/laravel-permission (RBAC) |
| Request protection | CSRF middleware + FormRequest validation |
| Rate limiting | throttle:api (60 req/min) |
| Audit trail | spatie/laravel-activitylog |
| Sensitive data | Eloquent casts with encryption |

---

## 2. Environment Setup

### 2.1 Docker Configuration

**`docker-compose.yml`**:

```yaml
services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
    ports:
      - "8000:8000"
    volumes:
      - .:/var/www/html
    environment:
      - APP_ENV=local
      - DB_CONNECTION=pgsql
      - DB_HOST=db
      - DB_PORT=5432
      - DB_DATABASE=mamut
      - DB_USERNAME=mamut
      - DB_PASSWORD=secret
      - REDIS_HOST=redis
    depends_on:
      - db
      - redis
    networks:
      - mamut

  db:
    image: postgres:16-alpine
    ports:
      - "5432:5432"
    environment:
      POSTGRES_DB: mamut
      POSTGRES_USER: mamut
      POSTGRES_PASSWORD: secret
    volumes:
      - pgdata:/var/lib/postgresql/data
    networks:
      - mamut

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"
    networks:
      - mamut

networks:
  mamut:
    driver: bridge

volumes:
  pgdata:
```

**`Dockerfile`** (uses FrankenPHP as application server):

```dockerfile
FROM dunglas/frankenphp:php8.4

RUN install-php-extensions pdo_pgsql redis intl zip gd

WORKDIR /var/www/html

COPY . .

RUN composer install --no-interaction --optimize-autoloader

EXPOSE 8000

CMD ["frankenphp", "php-server", "--root", "public/", "--listen", ":8000"]
```

### 2.2 Project Bootstrap

```bash
# 1. Build and start containers
docker compose up -d --build

# 2. Install dependencies
docker compose exec app composer install

# 3. Generate application key
docker compose exec app php artisan key:generate

# 4. Run migrations (after DB is ready)
docker compose exec app php artisan migrate

# 5. Seed initial admin user
docker compose exec app php artisan db:seed
```

### 2.3 Required Packages

```bash
# Authentication & permissions
composer require laravel/sanctum
composer require spatie/laravel-permission

# Audit trail
composer require spatie/laravel-activitylog

# Dev tooling
composer require barryvdh/laravel-debugbar --dev
composer require pestphp/pest --dev
```

**Checkpoints**:
- [ ] `docker compose ps` shows all containers as `Up`
- [ ] `http://localhost:8000` returns the Laravel welcome page
- [ ] `php artisan migrate` runs without errors

---

## 3. Database Design

### 3.1 Review of the Original Diagram

Improvements applied over the original Drawio model:

| Original Issue | Improvement |
|---|---|
| `Enum role`, `Enum hierarchical` as single-column tables | Use **PHP native Enums** + `string` column, avoid unnecessary JOINs |
| Unclear boundary between `corrective_order` and `execution` | Split into **WorkOrder (header)** + **WorkOrderExecution (detail)** supporting multiple maintainers |
| Preventive and corrective orders fully separate | Use a **unified `work_orders` table** with a `type` discriminator for easier reporting |
| No status history table | Add **`work_order_status_logs`** to record every status change |
| No customer satisfaction table | Add **`satisfaction_ratings`** for corrective orders only |
| `preventive_schedule` with 12 boolean month columns | Use **JSON `active_months`** column, more flexible |

### 3.2 Schema Definition

#### 3.2.1 Users

```php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->foreignId('sector_id')->nullable()->constrained()->nullOnDelete();
    $table->string('name');
    $table->string('email')->unique();
    $table->string('username')->unique()->nullable();
    $table->string('phone')->nullable();
    $table->string('password');
    $table->timestamp('email_verified_at')->nullable();
    $table->timestamp('last_login_at')->nullable();
    $table->rememberToken();
    $table->timestamps();
    $table->softDeletes();

    $table->index('sector_id');
    $table->index('email');
});
```

#### 3.2.2 Roles (spatie/laravel-permission)

| Role | Description |
|---|---|
| `system_admin` | Full system access |
| `aux_admin` | Panel monitoring, triage, closing |
| `planner` | All aux_admin + planning + reports |
| `manager` | Management reports, KPI dashboards |
| `client` | Corrective work order creation only |
| `maintainer` | Task execution, closing |

```php
// database/seeders/RoleSeeder.php
use Spatie\Permission\Models\Role;

$roles = ['system_admin', 'aux_admin', 'planner', 'manager', 'client', 'maintainer'];

foreach ($roles as $role) {
    Role::create(['name' => $role]);
}
```

#### 3.2.3 Sectors

```php
Schema::create('sectors', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('hierarchical')->default('operation');
    $table->string('site')->nullable();       // HESAP, HLS, AMA
    $table->string('block')->nullable();      // main building, utility annex...
    $table->integer('cost_center')->nullable();
    $table->string('logo')->nullable();
    $table->timestamps();
    $table->softDeletes();
});
```

#### 3.2.4 Locations

```php
Schema::create('locations', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('floor')->nullable();
    $table->string('wing')->nullable();
    $table->timestamps();
    $table->softDeletes();
});
```

#### 3.2.5 Equipment

```php
Schema::create('equipment', function (Blueprint $table) {
    $table->id();
    $table->foreignId('sector_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
    $table->string('name');
    $table->text('description')->nullable();
    $table->string('local')->nullable();
    $table->string('type')->nullable();
    $table->string('tag_equip')->unique()->nullable();
    $table->timestamps();
    $table->softDeletes();
    $table->index('tag_equip');
});
```

#### 3.2.6 Components

```php
Schema::create('components', function (Blueprint $table) {
    $table->id();
    $table->foreignId('equipment_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->text('description')->nullable();
    $table->string('tag_comp')->nullable();
    $table->timestamps();
    $table->softDeletes();
});
```

#### 3.2.7 Subcomponents

```php
Schema::create('subcomponents', function (Blueprint $table) {
    $table->id();
    $table->foreignId('component_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->text('description')->nullable();
    $table->string('tag_subcomp')->nullable();
    $table->timestamps();
    $table->softDeletes();
});
```

#### 3.2.8 Employees (Maintainers)

```php
Schema::create('employees', function (Blueprint $table) {
    $table->id();
    $table->string('drt_number')->unique()->nullable();
    $table->string('name');
    $table->string('specialty')->nullable();
    $table->timestamps();
    $table->softDeletes();
});
```

#### 3.2.9 Work Orders (Unified Header)

**Important design decision**: unify corrective, preventive, predictive, and inspection in a single table, using `type` as discriminator.

```php
Schema::create('work_orders', function (Blueprint $table) {
    $table->id();
    $table->string('order_number')->unique();
    $table->string('type');                                // corrective, preventive, predictive, inspection
    $table->string('status')->default('open');             // open, in_progress, waiting_material, waiting_approval, closed, cancelled
    $table->string('priority')->nullable();                // emergency, urgency, high, medium, low
    $table->string('work_group')->nullable();              // hydraulic, electric, building, gas_therapy, air_conditioning, gardening
    $table->string('specialty')->nullable();

    $table->foreignId('sector_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('equipment_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('component_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('subcomponent_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

    $table->string('title');
    $table->text('description');
    $table->text('solution')->nullable();
    $table->string('customer_signature_path')->nullable();
    $table->string('supervisor_signature_path')->nullable();
    $table->boolean('is_stopped')->default(false);
    $table->timestamp('opened_at');
    $table->timestamp('first_response_at')->nullable();
    $table->timestamp('closed_at')->nullable();

    $table->foreignId('preventive_plan_id')->nullable()->constrained()->nullOnDelete();
    $table->boolean('automatic_created')->default(false);

    $table->timestamps();
    $table->softDeletes();

    $table->index('order_number');
    $table->index('status');
    $table->index('type');
    $table->index('priority');
    $table->index('opened_at');
    $table->index(['status', 'type']);
});
```

#### 3.2.10 Work Order Executions (Multi-Maintainer)

Supports multiple maintainers on the same WO, tracking individual time.

```php
Schema::create('work_order_executions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
    $table->foreignId('maintainer_id')->constrained('employees')->cascadeOnDelete();
    $table->string('specialty')->nullable();
    $table->timestamp('started_at');
    $table->timestamp('finished_at')->nullable();
    $table->integer('duration_minutes')->nullable();
    $table->text('observations')->nullable();
    $table->timestamps();
    $table->index(['work_order_id', 'maintainer_id']);
});
```

#### 3.2.11 Work Order Status Logs

```php
Schema::create('work_order_status_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $table->string('from_status')->nullable();
    $table->string('to_status');
    $table->text('notes')->nullable();
    $table->timestamps();
    $table->index('work_order_id');
});
```

#### 3.2.12 Satisfaction Ratings (Corrective Only)

Missing ratings default to `satisfied`.

```php
Schema::create('satisfaction_ratings', function (Blueprint $table) {
    $table->id();
    $table->foreignId('work_order_id')->unique()->constrained()->cascadeOnDelete();
    $table->string('rating')->default('satisfied');    // unsatisfied, regular, satisfied, very_satisfied
    $table->text('comment')->nullable();
    $table->timestamps();
});
```

#### 3.2.13 Preventive Plans

```php
Schema::create('preventive_plans', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->foreignId('equipment_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('sector_id')->nullable()->constrained()->nullOnDelete();
    $table->string('work_group')->nullable();
    $table->string('specialty')->nullable();
    $table->integer('frequency_days');
    $table->date('start_date');
    $table->date('end_date')->nullable();
    $table->boolean('generate_preventive')->default(true);
    $table->boolean('generate_schedule')->default(false);
    $table->json('active_months')->nullable();         // e.g. [1,2,3,4,5,6,7,8,9,10,11,12]
    $table->text('justification')->nullable();
    $table->timestamps();
    $table->softDeletes();
});
```

#### 3.2.14 Checklists & Items

```php
Schema::create('checklists', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->foreignId('preventive_plan_id')->nullable()->constrained()->nullOnDelete();
    $table->timestamps();
});

Schema::create('check_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('checklist_id')->constrained()->cascadeOnDelete();
    $table->string('description');
    $table->integer('order')->default(0);
    $table->timestamps();
});
```

#### 3.2.15 Preventive Schedules

```php
Schema::create('preventive_schedules', function (Blueprint $table) {
    $table->id();
    $table->foreignId('preventive_plan_id')->constrained()->cascadeOnDelete();
    $table->date('scheduled_date');
    $table->string('status')->default('planned');       // planned, generated, executed
    $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
    $table->timestamps();

    $table->unique(['preventive_plan_id', 'scheduled_date']);
    $table->index('scheduled_date');
});
```

### 3.3 Relationship Overview

```
User ──┬── Sector
       └── Role (spatie)

Sector ──┬── User
         ├── Equipment
         └── WorkOrder

Equipment ──┬── Component
            ├── WorkOrder
            └── PreventivePlan

WorkOrder ──┬── WorkOrderExecution (1:N)
            ├── WorkOrderStatusLog (1:N)
            ├── SatisfactionRating (1:1)
            ├── User (requester)
            ├── User (assignee)
            ├── Location
            ├── Equipment
            └── PreventiveSchedule

PreventivePlan ──┬── PreventiveSchedule (1:N)
                 ├── WorkOrder (1:N)
                 └── Checklist (1:N)
```

**Checkpoints**:
- [ ] `php artisan migrate` succeeds, all tables created
- [ ] `php artisan migrate:rollback` reverts cleanly
- [ ] All foreign keys have appropriate constraints

---

## 4. Authentication & Authorization

### 4.1 Scheme

**Decision**: use **Laravel Sanctum** for token-based authentication.

Rationale:
- API will be consumed by web and mobile — Sanctum is the official lightweight solution
- No complex OAuth2 flow needed at this stage (can migrate to Passport later)
- Supports multiple tokens with expiration/revocation

### 4.2 Sanctum Setup

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

**`config/sanctum.php`** (key settings):

```php
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'localhost,127.0.0.1')),
'expiration' => 60 * 24 * 7, // 7 days in minutes
```

### 4.3 Auth Controller

**`app/Modules/Maintenance/Controllers/AuthController.php`**:

```php
<?php

namespace App\Modules\Maintenance\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'device_name' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        $user->update(['last_login_at' => now()]);

        $token = $user->createToken($request->device_name)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->load('roles', 'sector'),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->load('roles', 'sector'));
    }
}
```

### 4.4 Role-Based Authorization

Using `spatie/laravel-permission`:

```bash
composer require spatie/laravel-permission
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
```

**Gates in `AuthServiceProvider`**:

```php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('manage-users', fn (User $user) => $user->hasRole('system_admin'));
    Gate::define('view-reports', fn (User $user) => $user->hasAnyRole(['planner', 'manager', 'system_admin']));
    Gate::define('triage-work-orders', fn (User $user) => $user->hasAnyRole(['aux_admin', 'planner', 'system_admin']));
    Gate::define('close-work-orders', fn (User $user) => $user->hasAnyRole(['aux_admin', 'planner', 'system_admin', 'maintainer']));
    Gate::define('manage-preventive-plans', fn (User $user) => $user->hasAnyRole(['planner', 'system_admin']));
}
```

**Route middleware usage**:

```php
Route::middleware(['auth:sanctum'])->group(function () {
    Route::middleware('can:view-reports')->get('/reports', [ReportController::class, 'index']);
    Route::middleware('can:triage-work-orders')->post('/work-orders/{id}/triage', [WorkOrderController::class, 'triage']);
});
```

**Checkpoints**:
- [ ] Login returns token; invalid token returns 401
- [ ] Users with different roles cannot access unauthorized endpoints
- [ ] Logout invalidates the old token

---

## 5. Eloquent Models & Relationships

### 5.1 PHP Native Enums

**`app/Shared/Enums/WorkOrderType.php`**:

```php
<?php

namespace App\Shared\Enums;

enum WorkOrderType: string
{
    case Corrective = 'corrective';
    case Preventive = 'preventive';
    case Predictive = 'predictive';
    case Inspection = 'inspection';
}
```

**`app/Shared/Enums/WorkOrderStatus.php`**:

```php
<?php

namespace App\Shared\Enums;

enum WorkOrderStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingMaterial = 'waiting_material';
    case WaitingApproval = 'waiting_approval';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
}
```

**`app/Shared/Enums/Priority.php`**:

```php
<?php

namespace App\Shared\Enums;

enum Priority: string
{
    case Emergency = 'emergency';   // 15 minutes
    case Urgency = 'urgency';       // 45 minutes
    case High = 'high';             // 3 days
    case Medium = 'medium';         // 10 days
    case Low = 'low';               // 30 days

    public function responseTimeMinutes(): int
    {
        return match($this) {
            self::Emergency => 15,
            self::Urgency => 45,
            self::High => 3 * 24 * 60,
            self::Medium => 10 * 24 * 60,
            self::Low => 30 * 24 * 60,
        };
    }
}
```

### 5.2 WorkOrder Model

```php
<?php

namespace App\Modules\Maintenance\Models;

use App\Shared\Enums\WorkOrderStatus;
use App\Shared\Enums\WorkOrderType;
use App\Shared\Enums\Priority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WorkOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_number', 'type', 'status', 'priority',
        'work_group', 'specialty', 'sector_id', 'location_id',
        'equipment_id', 'component_id', 'subcomponent_id',
        'user_id', 'assigned_to', 'title', 'description',
        'solution', 'is_stopped', 'opened_at', 'first_response_at',
        'closed_at', 'preventive_plan_id', 'automatic_created',
    ];

    protected function casts(): array
    {
        return [
            'type' => WorkOrderType::class,
            'status' => WorkOrderStatus::class,
            'priority' => Priority::class,
            'is_stopped' => 'boolean',
            'automatic_created' => 'boolean',
            'opened_at' => 'datetime',
            'first_response_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(WorkOrderExecution::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(WorkOrderStatusLog::class)->latest();
    }

    public function satisfaction(): HasOne
    {
        return $this->hasOne(SatisfactionRating::class);
    }

    public function preventivePlan(): BelongsTo
    {
        return $this->belongsTo(PreventivePlan::class);
    }

    // ─── Helper methods ───

    public function isCorrective(): bool
    {
        return $this->type === WorkOrderType::Corrective;
    }

    public function isClosed(): bool
    {
        return $this->status === WorkOrderStatus::Closed;
    }

    public function totalDurationMinutes(): int
    {
        return $this->executions->sum('duration_minutes');
    }

    public function responseTimeMinutes(): ?int
    {
        if (! $this->first_response_at) {
            return null;
        }
        return $this->opened_at->diffInMinutes($this->first_response_at);
    }

    public function isWithinSla(): bool
    {
        if (! $this->priority || ! $this->responseTimeMinutes()) {
            return false;
        }
        return $this->responseTimeMinutes() <= $this->priority->responseTimeMinutes();
    }
}
```

### 5.3 User Model (Extended)

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasRoles, SoftDeletes;

    protected $fillable = [
        'sector_id', 'name', 'email', 'username',
        'phone', 'password', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }
}
```

**Checkpoints**:
- [ ] `WorkOrder::with('executions.maintainer')->find(1)` returns full data
- [ ] Enum casts work: `$order->status` returns a `WorkOrderStatus` instance
- [ ] `$order->isWithinSla()` correctly returns compliance

---

## 6. Service Layer & Business Logic

### 6.1 WorkOrderService

**`app/Modules/Maintenance/Services/WorkOrderService.php`**:

```php
<?php

namespace App\Modules\Maintenance\Services;

use App\Modules\Maintenance\Models\WorkOrder;
use App\Modules\Maintenance\Models\WorkOrderStatusLog;
use App\Shared\Enums\WorkOrderStatus;
use App\Shared\Enums\WorkOrderType;
use Illuminate\Support\Facades\DB;

class WorkOrderService
{
    public function createCorrective(array $data, ?int $userId = null): WorkOrder
    {
        return DB::transaction(function () use ($data, $userId) {
            $order = WorkOrder::create([
                'order_number' => $this->generateOrderNumber(),
                'type' => WorkOrderType::Corrective,
                'status' => WorkOrderStatus::Open,
                'priority' => $data['priority'] ?? null,
                'sector_id' => $data['sector_id'],
                'location_id' => $data['location_id'],
                'equipment_id' => $data['equipment_id'] ?? null,
                'user_id' => $userId,
                'title' => $data['title'],
                'description' => $data['description'],
                'is_stopped' => $data['is_stopped'] ?? false,
                'opened_at' => now(),
            ]);

            $this->logStatus($order, null, WorkOrderStatus::Open, $userId);

            return $order;
        });
    }

    public function triage(WorkOrder $order, array $data, int $userId): WorkOrder
    {
        return DB::transaction(function () use ($order, $data, $userId) {
            $oldStatus = $order->status;

            $order->update([
                'assigned_to' => $data['assigned_to'] ?? null,
                'priority' => $data['priority'] ?? $order->priority,
                'specialty' => $data['specialty'] ?? $order->specialty,
                'work_group' => $data['work_group'] ?? $order->work_group,
                'first_response_at' => now(),
            ]);

            $newStatus = $data['status'] ?? WorkOrderStatus::InProgress;
            $order->update(['status' => $newStatus]);
            $this->logStatus($order, $oldStatus, $newStatus, $userId, $data['notes'] ?? null);

            return $order->fresh();
        });
    }

    public function addExecution(WorkOrder $order, array $data): void
    {
        $order->executions()->create([
            'maintainer_id' => $data['maintainer_id'],
            'specialty' => $data['specialty'] ?? null,
            'started_at' => $data['started_at'] ?? now(),
            'observations' => $data['observations'] ?? null,
        ]);
    }

    public function completeExecution(WorkOrder $order, int $executionId, ?string $observations = null): void
    {
        $execution = $order->executions()->findOrFail($executionId);

        $finishedAt = now();
        $duration = $execution->started_at->diffInMinutes($finishedAt);

        $execution->update([
            'finished_at' => $finishedAt,
            'duration_minutes' => $duration,
            'observations' => $observations ?? $execution->observations,
        ]);
    }

    public function close(WorkOrder $order, int $userId, ?string $solution = null): WorkOrder
    {
        return DB::transaction(function () use ($order, $userId, $solution) {
            $oldStatus = $order->status;

            $order->update([
                'status' => WorkOrderStatus::Closed,
                'solution' => $solution ?? $order->solution,
                'closed_at' => now(),
            ]);

            $this->logStatus($order, $oldStatus, WorkOrderStatus::Closed, $userId);

            if ($order->isCorrective() && ! $order->satisfaction) {
                $order->satisfaction()->create(['rating' => 'satisfied']);
            }

            return $order->fresh();
        });
    }

    private function generateOrderNumber(): string
    {
        $prefix = 'OS-' . date('Ymd') . '-';
        $lastOrder = WorkOrder::where('order_number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->first();

        $sequence = $lastOrder
            ? (int) substr($lastOrder->order_number, -4) + 1
            : 1;

        return $prefix . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    private function logStatus(
        WorkOrder $order,
        ?WorkOrderStatus $from,
        WorkOrderStatus $to,
        ?int $userId,
        ?string $notes = null
    ): void {
        WorkOrderStatusLog::create([
            'work_order_id' => $order->id,
            'user_id' => $userId,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'notes' => $notes,
        ]);
    }
}
```

### 6.2 Notification Service (Email on Closure)

**`app/Modules/Maintenance/Services/NotificationService.php`**:

```php
<?php

namespace App\Modules\Maintenance\Services;

use App\Modules\Maintenance\Models\WorkOrder;
use App\Modules\Maintenance\Mail\WorkOrderClosedMail;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    public function sendClosureNotification(WorkOrder $order): void
    {
        if (! $order->requester || ! $order->requester->email) {
            return;
        }

        Mail::to($order->requester->email)
            ->send(new WorkOrderClosedMail($order));
    }
}
```

**`app/Modules/Maintenance/Mail/WorkOrderClosedMail.php`**:

```php
<?php

namespace App\Modules\Maintenance\Mail;

use App\Modules\Maintenance\Models\WorkOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WorkOrderClosedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public WorkOrder $order,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "WO {$this->order->order_number} has been closed",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.work-order-closed',
            with: [
                'order' => $this->order,
                'ratingUrl' => route('satisfaction.rate', $this->order->id),
            ],
        );
    }
}
```

**Checkpoints**:
- [ ] Creating a WO returns `order_number` in `OS-YYYYMMDD-XXXX` format
- [ ] Completing an execution calculates `duration_minutes` correctly
- [ ] Closing a WO triggers the email (use `Mail::fake()` in tests)

---

## 7. API Layer & Response Standard

### 7.1 Unified Response Trait

**`app/Shared/Traits/ApiResponse.php`**:

```php
<?php

namespace App\Shared\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    protected function success(mixed $data = null, string $message = 'Success', int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    protected function error(string $message = 'Error', int $code = 400, mixed $errors = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $code);
    }

    protected function paginated($paginator, string $message = 'Success'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
```

### 7.2 Work Order Controller

```php
<?php

namespace App\Modules\Maintenance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Maintenance\Models\WorkOrder;
use App\Modules\Maintenance\Requests\StoreCorrectiveOrderRequest;
use App\Modules\Maintenance\Requests\TriageWorkOrderRequest;
use App\Modules\Maintenance\Resources\WorkOrderResource;
use App\Modules\Maintenance\Services\WorkOrderService;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\Request;

class WorkOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly WorkOrderService $service,
    ) {}

    public function index(Request $request)
    {
        $orders = WorkOrder::with(['requester', 'sector', 'equipment', 'executions.maintainer'])
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->type, fn ($q, $type) => $q->where('type', $type))
            ->when($request->priority, fn ($q, $priority) => $q->where('priority', $priority))
            ->when($request->sector_id, fn ($q, $sectorId) => $q->where('sector_id', $sectorId))
            ->when($request->search, fn ($q, $search) => $q->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            }))
            ->orderByDesc('opened_at')
            ->paginate($request->per_page ?? 20);

        return $this->paginated($orders);
    }

    public function store(StoreCorrectiveOrderRequest $request)
    {
        $order = $this->service->createCorrective(
            $request->validated(),
            $request->user()?->id
        );

        return $this->success(
            new WorkOrderResource($order->load('requester', 'sector')),
            'Work order created successfully',
            201
        );
    }

    public function show(WorkOrder $workOrder)
    {
        return $this->success(
            new WorkOrderResource($workOrder->load([
                'requester', 'assignee', 'sector', 'location',
                'equipment', 'executions.maintainer', 'statusLogs.user',
                'satisfaction',
            ]))
        );
    }

    public function triage(TriageWorkOrderRequest $request, WorkOrder $workOrder)
    {
        $order = $this->service->triage(
            $workOrder,
            $request->validated(),
            $request->user()->id
        );

        return $this->success(
            new WorkOrderResource($order),
            'Triage completed successfully'
        );
    }
}
```

### 7.3 Routes

**`routes/api.php`**:

```php
<?php

use App\Modules\Maintenance\Controllers\AuthController;
use App\Modules\Maintenance\Controllers\WorkOrderController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/auth/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/work-orders', [WorkOrderController::class, 'index']);
    Route::post('/work-orders', [WorkOrderController::class, 'store']);
    Route::get('/work-orders/{workOrder}', [WorkOrderController::class, 'show']);

    Route::middleware('can:triage-work-orders')->group(function () {
        Route::post('/work-orders/{workOrder}/triage', [WorkOrderController::class, 'triage']);
        Route::post('/work-orders/{workOrder}/close', [WorkOrderController::class, 'close']);
    });
});
```

**Checkpoints**:
- [ ] `GET /api/work-orders` returns paginated list
- [ ] `POST /api/work-orders` requires token + validation
- [ ] Unauthorized roles receive 403

---

## 8. Preventive Maintenance Scheduling

### 8.1 Core Logic

Use **Laravel Scheduler** for automatic generation of preventive work orders.

Flow:
1. System cron invokes `php artisan schedule:run` every minute
2. Laravel Scheduler decides whether to run tasks based on rules
3. Laravel 13 includes `schedule:work` for continuous background execution

### 8.2 Generation Job

**`app/Modules/Maintenance/Jobs/GeneratePreventiveOrdersJob.php`**:

```php
<?php

namespace App\Modules\Maintenance\Jobs;

use App\Modules\Maintenance\Models\PreventivePlan;
use App\Modules\Maintenance\Models\PreventiveSchedule;
use App\Modules\Maintenance\Models\WorkOrder;
use App\Shared\Enums\WorkOrderStatus;
use App\Shared\Enums\WorkOrderType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GeneratePreventiveOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $currentMonth = now()->month;

        $plans = PreventivePlan::where('generate_preventive', true)
            ->where('start_date', '<=', now())
            ->where(function ($q) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', now());
            })
            ->where(function ($q) use ($currentMonth) {
                $q->whereNull('active_months')
                  ->orWhereJsonContains('active_months', $currentMonth);
            })
            ->get();

        foreach ($plans as $plan) {
            $this->generateOrder($plan);
        }

        Log::info("Preventive generation completed: {$plans->count()} plans processed.");
    }

    private function generateOrder(PreventivePlan $plan): void
    {
        DB::transaction(function () use ($plan) {
            $scheduledDate = now()->startOfMonth()->addDay();

            $exists = PreventiveSchedule::where('preventive_plan_id', $plan->id)
                ->whereDate('scheduled_date', $scheduledDate)
                ->exists();

            if ($exists) {
                return;
            }

            $schedule = PreventiveSchedule::create([
                'preventive_plan_id' => $plan->id,
                'scheduled_date' => $scheduledDate,
                'status' => 'generated',
            ]);

            $order = WorkOrder::create([
                'order_number' => 'PM-' . date('Ymd') . '-' . str_pad($plan->id, 4, '0', STR_PAD_LEFT),
                'type' => WorkOrderType::Preventive,
                'status' => WorkOrderStatus::Open,
                'priority' => 'medium',
                'sector_id' => $plan->sector_id,
                'equipment_id' => $plan->equipment_id,
                'title' => $plan->title,
                'description' => "Scheduled preventive maintenance: {$plan->title}",
                'opened_at' => now(),
                'preventive_plan_id' => $plan->id,
                'automatic_created' => true,
            ]);

            $schedule->update(['work_order_id' => $order->id]);
        });
    }
}
```

### 8.3 Scheduler Configuration

**`routes/console.php`**:

```php
use Illuminate\Support\Facades\Schedule;
use App\Modules\Maintenance\Jobs\GeneratePreventiveOrdersJob;

Schedule::job(new GeneratePreventiveOrdersJob)
    ->monthlyOn(1, '00:05')
    ->withoutOverlapping()
    ->onSuccess(function () {
        \Log::info('Preventive generation job succeeded.');
    })
    ->onFailure(function () {
        \Log::error('Preventive generation job failed.');
    });
```

**Running the scheduler in production**:

```bash
# Option 1: system cron
* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1

# Option 2: continuous worker
php artisan schedule:work
```

**Checkpoints**:
- [ ] Manually running `php artisan schedule:run` creates a preventive WO
- [ ] Same schedule does not duplicate orders
- [ ] `withoutOverlapping()` prevents concurrent runs

---

## 9. Reports & KPIs

### 9.1 Key Indicators

| Indicator | Formula | Description |
|---|---|---|
| **MTBF** | Total operating time / Number of failures | Mean Time Between Failures |
| **MTTR** | Total repair time / Number of failures | Mean Time To Repair |
| **Availability** | MTBF / (MTBF + MTTR) | Equipment availability |
| **SLA Rate** | On-time WOs / Total WOs | Service level compliance |
| **Customer Satisfaction** | Sum of ratings / Total ratings | Customer satisfaction |

### 9.2 Report Controller

```php
<?php

namespace App\Modules\Maintenance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Maintenance\Models\WorkOrder;
use App\Shared\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    use ApiResponse;

    public function dashboard(Request $request)
    {
        $dateFrom = $request->date_from ?? now()->startOfMonth();
        $dateTo = $request->date_to ?? now()->endOfMonth();

        $query = WorkOrder::whereBetween('opened_at', [$dateFrom, $dateTo]);

        return $this->success([
            'total' => (clone $query)->count(),
            'by_status' => (clone $query)->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')->pluck('total', 'status'),
            'by_type' => (clone $query)->select('type', DB::raw('count(*) as total'))
                ->groupBy('type')->pluck('total', 'type'),
            'by_priority' => (clone $query)->select('priority', DB::raw('count(*) as total'))
                ->groupBy('priority')->pluck('total', 'priority'),
            'by_sector' => (clone $query)->select('sector_id', DB::raw('count(*) as total'))
                ->groupBy('sector_id')
                ->with('sector:id,name')
                ->get(),
            'sla_compliance' => $this->calculateSlaCompliance($query),
        ]);
    }

    private function calculateSlaCompliance($query): array
    {
        $orders = (clone $query)->whereNotNull('first_response_at')->get();

        $total = $orders->count();
        $withinSla = $orders->filter(fn ($order) => $order->isWithinSla())->count();

        return [
            'total_with_response' => $total,
            'within_sla' => $withinSla,
            'compliance_rate' => $total > 0 ? round(($withinSla / $total) * 100, 2) : 0,
        ];
    }
}
```

**Checkpoints**:
- [ ] `/api/reports/dashboard` returns aggregated stats
- [ ] SLA rate calculates correctly
- [ ] MTBF/MTTR requires additional operating-time data on equipment

---

## 10. Testing & QA

### 10.1 Pest Test Example

**`tests/Feature/WorkOrderTest.php`**:

```php
<?php

use App\Models\User;
use App\Modules\Maintenance\Models\WorkOrder;
use App\Shared\Enums\WorkOrderStatus;
use App\Shared\Enums\WorkOrderType;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->assignRole('client');
});

test('user can create corrective work order', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/work-orders', [
            'title' => 'Air conditioner not cooling',
            'description' => 'Room 302, AC is not cooling',
            'sector_id' => 1,
            'location_id' => 1,
            'priority' => 'high',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['id', 'order_number', 'status']]);

    $this->assertDatabaseHas('work_orders', [
        'title' => 'Air conditioner not cooling',
        'type' => WorkOrderType::Corrective->value,
        'status' => WorkOrderStatus::Open->value,
    ]);
});

test('aux_admin can triage a work order', function () {
    $admin = User::factory()->create();
    $admin->assignRole('aux_admin');

    $order = WorkOrder::factory()->create([
        'status' => WorkOrderStatus::Open,
    ]);

    $response = $this->actingAs($admin)
        ->postJson("/api/work-orders/{$order->id}/triage", [
            'status' => 'in_progress',
            'priority' => 'urgency',
        ]);

    $response->assertStatus(200);

    expect($order->fresh()->status)->toBe(WorkOrderStatus::InProgress);
});
```

### 10.2 Running Tests

```bash
docker compose exec app php artisan test
```

**Checkpoints**:
- [ ] All tests pass
- [ ] Coverage > 70% (`php artisan test --coverage`)

---

## 11. Production Deployment & Expansion

### 11.1 Deployment Checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] Configure PostgreSQL scheduled backups
- [ ] Configure HTTPS (SSL certificate)
- [ ] Set `SANCTUM_STATEFUL_DOMAINS` to production domains
- [ ] Configure Redis for cache and queue
- [ ] Configure log rotation
- [ ] Configure monitoring (Sentry or Bugsnag)

### 11.2 Expansion Path

| Module | Expansion Strategy |
|---|---|
| **Materials** | Add `app/Modules/Materials`, reuse auth/permissions |
| **Utilities** | Add `app/Modules/Utilities` for water, electricity, gas consumption |
| **Web Frontend** | Vue 3 / React SPA consuming the REST API |
| **Mobile App** | Flutter or React Native, same API |

---

## 12. Appendix: Command Cheat Sheet

| Step | Command | Description |
|---|---|---|
| Start environment | `docker compose up -d --build` | Start all containers |
| Install deps | `docker compose exec app composer install` | Install PHP packages |
| Generate key | `docker compose exec app php artisan key:generate` | Generate app key |
| Run migrations | `docker compose exec app php artisan migrate` | Create DB tables |
| Seed data | `docker compose exec app php artisan db:seed` | Create roles + admin |
| Run tests | `docker compose exec app php artisan test` | Execute test suite |
| Start scheduler | `docker compose exec app php artisan schedule:work` | Start background scheduler |
| Manual preventive run | `docker compose exec app php artisan tinker` → `dispatch(new GeneratePreventiveOrdersJob)` | Test preventive generation |

---

## Document Summary

This document defines the Mamut backend architecture: layering, directory structure, database schema, auth layer, service layer, and testing strategy. The AI coding agent can execute stages **2 through 11 sequentially**, verifying each checkpoint before proceeding. Each stage is self-contained to allow incremental testing and avoid overwhelming the agent context window.

**Language rule**: All code, table names, columns and file names are in **English**. All non-code documentation that must live inside the repo (e.g. markdown guides) is in **Portuguese (pt-BR)** when targeting end users; this architecture file itself is written in English for AI agents.

**Next deliverables (out of scope for this file)**:
- `docs/api-reference.md` (endpoint contract, in pt-BR)
- `docs/er-diagram.md` (final ER diagram, in pt-BR)
- `docs/deployment-runbook.md` (production runbook, in pt-BR)