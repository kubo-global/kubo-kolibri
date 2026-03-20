<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum_maps', function (Blueprint $table) {
            $table->uuid('kolibri_content_id')->nullable()->after('kolibri_node_id');
        });
    }

    public function down(): void
    {
        Schema::table('curriculum_maps', function (Blueprint $table) {
            $table->dropColumn('kolibri_content_id');
        });
    }
};
