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
        Schema::table('generator_lines', function (Blueprint $table) {
            $table->decimal('fuel_cost_per_liter', 10, 4)->nullable()->after('phases');
            $table->decimal('fuel_consumption_lph', 10, 4)->nullable()->after('fuel_cost_per_liter');
        });
    }

    public function down(): void
    {
        Schema::table('generator_lines', function (Blueprint $table) {
            $table->dropColumn(['fuel_cost_per_liter', 'fuel_consumption_lph']);
        });
    }
};
