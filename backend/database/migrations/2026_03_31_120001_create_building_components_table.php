<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('building_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_type_id')->constrained()->cascadeOnDelete();
            $table->decimal('power', 10, 2);
            $table->unsignedInteger('quantity')->default(1);
            $table->enum('priority', ['critical', 'non_critical', 'normal'])->default('normal');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('building_components');
    }
};
