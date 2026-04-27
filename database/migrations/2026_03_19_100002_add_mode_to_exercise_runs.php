<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercise_runs', function (Blueprint $table) {
            $table->string('mode', 20)->default('free')->after('status');
            $table->foreignId('lesson_assignment_id')->nullable()->after('mode')
                ->constrained('lesson_assignments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exercise_runs', function (Blueprint $table) {
            $table->dropForeign(['lesson_assignment_id']);
            $table->dropColumn(['mode', 'lesson_assignment_id']);
        });
    }
};
