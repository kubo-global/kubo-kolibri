<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Links skills to Kolibri content that teaches/assesses them
        Schema::create('skill_content', function (Blueprint $table) {
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_map_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('practice'); // practice | teach | assess

            $table->primary(['skill_id', 'curriculum_map_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_content');
    }
};
