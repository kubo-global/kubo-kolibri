<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authored_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('authored_exercise_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('question_text');
            $table->string('question_type')->default('radio');
            $table->json('choices')->nullable();
            $table->string('correct_answer')->nullable();
            $table->json('hints')->nullable();
            $table->char('assessment_item_id', 32)->nullable();
            $table->timestamps();

            $table->index('authored_exercise_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authored_questions');
    }
};
