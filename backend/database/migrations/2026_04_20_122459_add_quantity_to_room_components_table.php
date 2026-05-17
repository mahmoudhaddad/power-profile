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
        Schema::table('room_components', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->default(1)->after('power_factor');
        });
    }

    public function down(): void
    {
        Schema::table('room_components', function (Blueprint $table) {
            $table->dropColumn('quantity');
        });
    }
};
