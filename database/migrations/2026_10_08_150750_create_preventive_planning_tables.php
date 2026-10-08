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
            $table->json('active_months')->nullable();
            $table->text('justification')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

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
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('check_items');
        Schema::dropIfExists('checklists');
        Schema::dropIfExists('preventive_plans');
    }
};
