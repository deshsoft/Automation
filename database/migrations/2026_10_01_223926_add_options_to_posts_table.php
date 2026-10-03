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
        Schema::table('posts', function (Blueprint $table) {
            $table->json('options')->nullable()->after('media_mime');
            $table->foreignId('share_from_account_id')->nullable()->after('options')
                ->constrained('social_accounts')->nullOnDelete();
        });

        Schema::table('post_targets', function (Blueprint $table) {
            $table->string('permalink', 500)->nullable()->after('platform_post_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropColumn('permalink');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('share_from_account_id');
            $table->dropColumn('options');
        });
    }
};
