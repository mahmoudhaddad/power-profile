<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->decimal('location_lat', 9, 6)->nullable()->after('generator_power');
            $table->decimal('location_lng', 9, 6)->nullable()->after('location_lat');
            $table->string('location_name', 255)->nullable()->after('location_lng');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['location_lat', 'location_lng', 'location_name']);
        });
    }
};
