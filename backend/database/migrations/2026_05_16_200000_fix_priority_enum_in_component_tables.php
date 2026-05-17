<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = ['building_components', 'floor_components', 'project_components'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('priority_tmp', 20)->default('normal')->after('quantity');
            });

            DB::statement("UPDATE \"{$table}\" SET priority_tmp = priority");

            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('priority');
            });

            Schema::table($table, function (Blueprint $t) {
                $t->renameColumn('priority_tmp', 'priority');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('priority_old', 20)->default('normal')->after('quantity');
            });

            DB::statement("UPDATE \"{$table}\" SET priority_old = priority");

            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('priority');
            });

            Schema::table($table, function (Blueprint $t) {
                $t->enum('priority', ['critical', 'non_critical', 'normal'])->default('normal')->after('quantity');
            });

            DB::statement("UPDATE \"{$table}\" SET priority = CASE WHEN priority_old IN ('critical','non_critical','normal') THEN priority_old ELSE 'normal' END");

            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('priority_old');
            });
        }
    }
};
