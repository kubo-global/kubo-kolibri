<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('kolibri_facility_id')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('kolibri_user_id')->nullable();
        });

        Schema::table('offerings', function (Blueprint $table) {
            $table->string('kolibri_classroom_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('kolibri_facility_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('kolibri_user_id');
        });

        Schema::table('offerings', function (Blueprint $table) {
            $table->dropColumn('kolibri_classroom_id');
        });
    }
};
