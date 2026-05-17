<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type'); // project, building, floor, room
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_name');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->longText('data');
            $table->timestamps();
            $table->index(['project_id', 'entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_backups');
    }
};
