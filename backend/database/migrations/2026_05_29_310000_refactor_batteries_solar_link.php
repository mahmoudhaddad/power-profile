<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batteries', function (Blueprint $table) {
            // Drop the simple kW field added earlier
            if (Schema::hasColumn('batteries', 'dedicated_solar_kw')) {
                $table->dropColumn('dedicated_solar_kw');
            }

            // FK to the named solar system that exclusively charges this bank.
            // Null = battery charges from the shared solar pool (no dedicated system).
            $table->foreignId('solar_system_id')
                ->nullable()
                ->after('notes')
                ->constrained('solar_systems')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('batteries', function (Blueprint $table) {
            $table->dropForeign(['solar_system_id']);
            $table->dropColumn('solar_system_id');
            $table->decimal('dedicated_solar_kw', 8, 2)->nullable()->default(null);
        });
    }
};
