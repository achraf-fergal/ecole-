<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('center_id');
            $table->unsignedBigInteger('user_id');
            $table->string('student_code', 50);
            $table->string('phone', 30)->nullable();
            $table->string('status', 20)->default('active');

            $table->timestamps();

            $table->unique(
                ['center_id', 'id'],
                'student_profiles_center_id_unique'
            );

            $table->unique(
                ['center_id', 'user_id'],
                'student_profiles_center_user_unique'
            );

            $table->unique(
                ['center_id', 'student_code'],
                'student_profiles_center_code_unique'
            );

            $table->index(
                ['center_id', 'status'],
                'student_profiles_status_index'
            );

            $table->foreign(
                ['center_id', 'user_id'],
                'student_profiles_center_user_fk'
            )
                ->references(['center_id', 'user_id'])
                ->on('center_user')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_profiles');
    }
};
