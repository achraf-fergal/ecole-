
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_assets', function (Blueprint $table) {
            $table->unique(
                ['center_id', 'lesson_id', 'id'],
                'lesson_assets_center_lesson_identity_unique'
            );
        });

        Schema::table('exercises', function (Blueprint $table) {
            $table->unique(
                ['center_id', 'lesson_id', 'id'],
                'exercises_center_lesson_identity_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->dropUnique(
                'exercises_center_lesson_identity_unique'
            );
        });

        Schema::table('lesson_assets', function (Blueprint $table) {
            $table->dropUnique(
                'lesson_assets_center_lesson_identity_unique'
            );
        });
    }
};
