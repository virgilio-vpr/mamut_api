<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sectors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('hierarchical')->default('operation');
            $table->string('site')->nullable();
            $table->string('block')->nullable();
            $table->integer('cost_center')->nullable();
            $table->string('logo')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('floor')->nullable();
            $table->string('wing')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sector_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('username')->nullable()->unique();
            $table->string('phone')->nullable();
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('sector_id');
        });

        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sector_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('local')->nullable();
            $table->string('type')->nullable();
            $table->string('tag_equip')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('tag_comp')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('subcomponents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('tag_subcomp')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('drt_number')->nullable()->unique();
            $table->string('name');
            $table->string('specialty')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subcomponents');
        Schema::dropIfExists('components');
        Schema::dropIfExists('equipment');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('users');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('sectors');
    }
};
