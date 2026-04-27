<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authored_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('subject_id');
            $table->foreign('subject_id')->references('id')->on('subjects');
            $table->foreignId('skill_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('mastery_m')->default(3);
            $table->unsignedTinyInteger('mastery_n')->default(5);
            $table->boolean('randomize')->default(true);
            $table->unsignedInteger('created_by');
            $table->foreign('created_by')->references('id')->on('users');
            $table->timestamp('channel_synced_at')->nullable();
            $table->char('kolibri_node_id', 32)->nullable();
            $table->char('kolibri_content_id', 32)->nullable();
            $table->timestamps();

            $table->index(['school_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authored_exercises');
    }
};
