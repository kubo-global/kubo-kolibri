<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pivot table for assigning lessons to specific students
        // If no rows exist for a lesson_assignment_id, assignment is for the whole class
        Schema::create('lesson_assignment_students', function (Blueprint $table) {
            $table->foreignId('lesson_assignment_id')->constrained('lesson_assignments')->cascadeOnDelete();
            $table->unsignedInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->primary(['lesson_assignment_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_assignment_students');
    }
};
