<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('center_user', function (Blueprint $table) {
            $table->id();

            $table->foreignId('center_id')
                ->constrained('centers')
                ->restrictOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('status', 20)->default('active');

            $table->timestamps();

            // A user has one membership per center.
            $table->unique(
                ['center_id', 'user_id'],
                'center_user_membership_unique'
            );

            $table->index(
                ['user_id', 'status'],
                'center_user_user_status_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('center_user');
    }
};
