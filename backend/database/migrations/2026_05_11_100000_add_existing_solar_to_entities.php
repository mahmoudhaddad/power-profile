<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['projects', 'buildings', 'floors', 'rooms'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->decimal('existing_solar_power', 12, 2)->default(0)->nullable()->after('solar_power');
                $table->string('solar_source', 20)->default('max')->after('existing_solar_power');
            });
        }
    }

    public function down(): void
    {
        foreach (['projects', 'buildings', 'floors', 'rooms'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['existing_solar_power', 'solar_source']);
            });
        }
    }
};
