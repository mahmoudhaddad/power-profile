<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sockets', function (Blueprint $table) {
            $table->id();
            $table->morphs('socketable');
            $table->string('phase_type')->default('1phase');
            $table->decimal('power', 10, 2);
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sockets');
    }
};
