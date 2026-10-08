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
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->string('type');
            $table->string('status')->default('open');
            $table->string('priority')->nullable();
            $table->string('work_group')->nullable();
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

            $table->index('status');
            $table->index('type');
            $table->index('priority');
            $table->index('opened_at');
            $table->index('sector_id');
            $table->index('assigned_to');
            $table->index(['status', 'type']);
        });

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

        Schema::create('satisfaction_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('rating')->default('satisfied');
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('satisfaction_ratings');
        Schema::dropIfExists('work_order_status_logs');
        Schema::dropIfExists('work_order_executions');
        Schema::dropIfExists('work_orders');
    }
};
