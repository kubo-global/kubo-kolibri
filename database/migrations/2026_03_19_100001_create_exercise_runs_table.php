<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercise_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_map_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active'); // active | completed | abandoned
            $table->unsignedSmallInteger('total_questions')->default(0);
            $table->unsignedSmallInteger('correct_answers')->default(0);
            $table->unsignedSmallInteger('wrong_answers')->default(0);
            $table->decimal('score', 5, 2)->nullable();
            $table->unsignedInteger('time_spent_seconds')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('kolibri_session_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'skill_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_runs');
    }
};
