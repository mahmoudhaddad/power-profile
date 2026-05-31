<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batteries', function (Blueprint $table) {
            // kW of project solar capacity dedicated to charging this bank.
            // null / 0 means the battery draws from the shared solar pool.
            $table->decimal('dedicated_solar_kw', 8, 2)->nullable()->default(null)->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('batteries', function (Blueprint $table) {
            $table->dropColumn('dedicated_solar_kw');
        });
    }
};
