<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('subject_id');
            $table->foreign('subject_id')->references('id')->on('subjects');
            $table->unsignedInteger('grade_id')->nullable();
            $table->foreign('grade_id')->references('id')->on('grades');
            $table->string('name');
            $table->string('description')->nullable();
            $table->unsignedInteger('level')->default(0);
            $table->timestamps();

            $table->index(['subject_id', 'grade_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skills');
    }
};
