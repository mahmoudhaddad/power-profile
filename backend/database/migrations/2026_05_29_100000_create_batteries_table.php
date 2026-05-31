<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batteries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('chemistry', 50);

            // Physical configuration
            $table->decimal('nominal_voltage_v', 8, 2);
            $table->decimal('capacity_ah_per_unit', 10, 2);
            $table->integer('quantity');
            $table->integer('series_count');
            $table->integer('parallel_count');
            $table->date('installation_date');

            // Operating parameters (auto-filled from chemistry, user can override)
            $table->decimal('depth_of_discharge', 4, 3);
            $table->decimal('round_trip_efficiency', 4, 3);
            $table->decimal('c_rate_charge', 4, 2);
            $table->decimal('c_rate_discharge', 4, 2);
            $table->integer('rated_cycle_life');

            // Live status
            $table->decimal('current_soc', 4, 3)->default(0.500);
            $table->boolean('is_active')->default(true);

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('project_id');
            $table->index(['project_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batteries');
    }
};
