<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('component_types', function (Blueprint $table) {
            $table->decimal('default_power', 10, 2)->nullable()->after('is_preset');
            $table->string('default_phases')->nullable()->after('default_power');
            $table->decimal('default_power_factor', 4, 2)->nullable()->after('default_phases');
        });
    }

    public function down(): void
    {
        Schema::table('component_types', function (Blueprint $table) {
            $table->dropColumn(['default_power', 'default_phases', 'default_power_factor']);
        });
    }
};
