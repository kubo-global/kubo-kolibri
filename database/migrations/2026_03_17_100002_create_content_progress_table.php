<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreignId('curriculum_map_id')->constrained()->cascadeOnDelete();
            $table->string('kolibri_log_id')->nullable();
            $table->decimal('score', 5, 2)->nullable();
            $table->boolean('completed')->default(false);
            $table->unsignedInteger('time_spent')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'curriculum_map_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_progress');
    }
};
