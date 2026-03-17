<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curriculum_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('subject_id');
            $table->foreign('subject_id')->references('id')->on('subjects');
            $table->unsignedInteger('topic_id')->nullable();
            $table->foreign('topic_id')->references('id')->on('topics');
            $table->uuid('kolibri_channel_id');
            $table->uuid('kolibri_node_id');
            $table->string('content_kind');
            $table->unsignedInteger('display_order')->default(0);
            $table->unsignedInteger('mapped_by')->nullable();
            $table->foreign('mapped_by')->references('id')->on('users');
            $table->timestamps();

            $table->index(['subject_id', 'topic_id']);
            $table->index('kolibri_node_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_maps');
    }
};
