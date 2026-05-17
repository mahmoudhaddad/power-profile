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
        Schema::table('utility_lines', function (Blueprint $table) {
            $table->string('phases')->default('1phase')->after('power'); // '1phase' or '3phase'
        });
    }

    public function down(): void
    {
        Schema::table('utility_lines', function (Blueprint $table) {
            $table->dropColumn('phases');
        });
    }
};
