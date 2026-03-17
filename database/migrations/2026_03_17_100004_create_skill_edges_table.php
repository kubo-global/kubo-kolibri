<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Directed edge: skill requires prerequisite
        Schema::create('skill_edges', function (Blueprint $table) {
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prerequisite_id')->constrained('skills')->cascadeOnDelete();

            $table->primary(['skill_id', 'prerequisite_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_edges');
    }
};
