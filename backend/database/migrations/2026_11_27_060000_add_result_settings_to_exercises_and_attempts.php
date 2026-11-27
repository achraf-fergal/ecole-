<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercises', function (Blueprint $table): void {
            $table->json('result_settings')->nullable()->after('show_correct_answers_after_submission');
        });

        Schema::table('exercise_attempts', function (Blueprint $table): void {
            $table->unsignedSmallInteger('max_attempts_snapshot')->nullable()->after('passing_score_snapshot');
            $table->json('result_settings_snapshot')->nullable()->after('show_correct_answers_after_submission_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('exercise_attempts', function (Blueprint $table): void {
            $table->dropColumn([
                'max_attempts_snapshot',
                'result_settings_snapshot',
            ]);
        });

        Schema::table('exercises', function (Blueprint $table): void {
            $table->dropColumn('result_settings');
        });
    }
};
