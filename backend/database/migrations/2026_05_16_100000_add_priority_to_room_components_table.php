<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_components', function (Blueprint $table) {
            $table->string('priority', 20)->default('normal')->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('room_components', function (Blueprint $table) {
            $table->dropColumn('priority');
        });
    }
};
