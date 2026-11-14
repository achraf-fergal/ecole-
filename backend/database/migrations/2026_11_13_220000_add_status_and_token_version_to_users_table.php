<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds status (for account enabling/disabling) and token_version
     * (for invalidating all issued JWT tokens across all devices upon password reset/logout-all).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('password');
            $table->unsignedInteger('token_version')->default(1)->after('status');

            $table->index('status', 'users_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_status_index');
            $table->dropColumn(['status', 'token_version']);
        });
    }
};
