<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('auto_backup_interval')->default('never')->after('generator_power');
            $table->timestamp('last_auto_backup_at')->nullable()->after('auto_backup_interval');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['auto_backup_interval', 'last_auto_backup_at']);
        });
    }
};
