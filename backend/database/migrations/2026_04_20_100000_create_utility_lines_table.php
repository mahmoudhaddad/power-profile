<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utility_lines', function (Blueprint $table) {
            $table->id();
            $table->morphs('lineable'); // lineable_type + lineable_id
            $table->string('name');
            $table->decimal('power', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utility_lines');
    }
};
