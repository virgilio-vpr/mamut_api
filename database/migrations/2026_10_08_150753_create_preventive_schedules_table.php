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
        Schema::create('preventive_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preventive_plan_id')->constrained()->cascadeOnDelete();
            $table->date('scheduled_date');
            $table->string('status')->default('planned');
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['preventive_plan_id', 'scheduled_date']);
            $table->index('scheduled_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('preventive_schedules');
    }
};
