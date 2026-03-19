<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('offering_id');
            $table->foreign('offering_id')->references('id')->on('offerings')->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('week_number');
            $table->unsignedInteger('assigned_by');
            $table->foreign('assigned_by')->references('id')->on('users');
            $table->string('kolibri_lesson_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['offering_id', 'skill_id', 'week_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_assignments');
    }
};
