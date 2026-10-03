<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('live_stream_targets', function (Blueprint $table) {
            $table->string('role', 10)->default('live')->after('social_account_id');
        });

        Schema::table('live_streams', function (Blueprint $table) {
            $table->text('share_message')->nullable()->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('live_streams', function (Blueprint $table) {
            $table->dropColumn('share_message');
        });

        Schema::table('live_stream_targets', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
