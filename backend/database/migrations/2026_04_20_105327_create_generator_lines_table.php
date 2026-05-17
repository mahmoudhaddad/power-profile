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
        Schema::create('generator_lines', function (Blueprint $table) {
            $table->id();
            $table->morphs('generable'); // generable_type + generable_id
            $table->string('name');
            $table->decimal('power', 12, 2);
            $table->string('phases')->default('1phase'); // '1phase' or '3phase'
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generator_lines');
    }
};
