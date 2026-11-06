<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('center_user_role', function (Blueprint $table) {
            $table->foreignId('center_user_id')
                ->constrained('center_user')
                ->cascadeOnDelete();

            $table->foreignId('role_id')
                ->constrained('roles')
                ->restrictOnDelete();

            $table->timestamps();

            $table->primary(
                ['center_user_id', 'role_id'],
                'center_user_role_primary'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('center_user_role');
    }
};
