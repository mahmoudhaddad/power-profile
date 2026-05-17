<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite does not support altering enum constraints in place.
        // Recreate the column as a plain string; validation is enforced in the controller.
        Schema::table('project_users', function (Blueprint $table) {
            $table->string('role')->default('normal')->change();
        });
    }

    public function down(): void
    {
        Schema::table('project_users', function (Blueprint $table) {
            $table->enum('role', ['main', 'normal'])->default('normal')->change();
        });
    }
};
