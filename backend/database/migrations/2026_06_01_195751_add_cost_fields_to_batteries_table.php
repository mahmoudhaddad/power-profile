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
        Schema::table('batteries', function (Blueprint $table) {
            $table->decimal('purchase_cost', 15, 2)->nullable()->after('notes');
            $table->decimal('replacement_cost', 15, 2)->nullable()->after('purchase_cost');
        });
    }

    public function down(): void
    {
        Schema::table('batteries', function (Blueprint $table) {
            $table->dropColumn(['purchase_cost', 'replacement_cost']);
        });
    }
};
