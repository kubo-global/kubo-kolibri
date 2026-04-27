<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_skills', function (Blueprint $table) {
            $table->unsignedSmallInteger('next_review_week')->nullable()->after('mastered_at');
            $table->unsignedTinyInteger('review_interval_index')->default(0)->after('next_review_week');
        });
    }

    public function down(): void
    {
        Schema::table('student_skills', function (Blueprint $table) {
            $table->dropColumn(['next_review_week', 'review_interval_index']);
        });
    }
};
