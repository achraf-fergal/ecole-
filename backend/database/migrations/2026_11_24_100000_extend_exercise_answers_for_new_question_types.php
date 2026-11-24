<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('questions', function (Blueprint $table): void {
            $table->json('answer_config')->nullable();
        });

        Schema::table('exercises', function (Blueprint $table): void {
            $table->decimal('passing_score', 5, 2)->default(70.00)->change();
        });

        Schema::table('exercise_attempt_answers', function (Blueprint $table) use ($driver): void {
            if ($driver !== 'sqlite') {
                $table->dropForeign('exercise_attempt_answers_correct_option_fk');
            }
            $table->unsignedBigInteger('correct_option_id_snapshot')->nullable()->change();
            $table->longText('text_answer')->nullable();
            $table->json('selected_option_ids')->nullable();
            $table->json('correct_option_ids_snapshot')->nullable();
            $table->json('answer_config_snapshot')->nullable();
        });

        if ($driver !== 'sqlite') {
            Schema::table('exercise_attempt_answers', function (Blueprint $table): void {
                $table->foreign(
                    ['center_id', 'question_id', 'correct_option_id_snapshot'],
                    'exercise_attempt_answers_correct_option_fk'
                )
                    ->references(['center_id', 'question_id', 'id'])
                    ->on('question_options')
                    ->restrictOnDelete();
            });
        }

        Schema::table('exercise_attempts', function (Blueprint $table): void {
            $table->char('submission_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $hasNewAnswerData = DB::table('exercise_attempt_answers')
            ->whereNotNull('text_answer')
            ->orWhereNotNull('selected_option_ids')
            ->orWhereNotNull('correct_option_ids_snapshot')
            ->orWhereNotNull('answer_config_snapshot')
            ->orWhereNull('correct_option_id_snapshot')
            ->exists();

        $hasNewQuestions = DB::table('questions')
            ->whereIn('question_type', ['multiple_select', 'short_answer'])
            ->exists();

        if ($hasNewAnswerData || $hasNewQuestions) {
            throw new RuntimeException(
                'Cannot roll back exercise question support without discarding new answer snapshots or question types.'
            );
        }

        Schema::table('exercise_attempts', function (Blueprint $table): void {
            $table->dropColumn('submission_fingerprint');
        });

        Schema::table('exercise_attempt_answers', function (Blueprint $table) use ($driver): void {
            if ($driver !== 'sqlite') {
                $table->dropForeign('exercise_attempt_answers_correct_option_fk');
            }
            $table->dropColumn([
                'text_answer',
                'selected_option_ids',
                'correct_option_ids_snapshot',
                'answer_config_snapshot',
            ]);
            $table->unsignedBigInteger('correct_option_id_snapshot')->nullable(false)->change();
        });

        if ($driver !== 'sqlite') {
            Schema::table('exercise_attempt_answers', function (Blueprint $table): void {
                $table->foreign(
                    ['center_id', 'question_id', 'correct_option_id_snapshot'],
                    'exercise_attempt_answers_correct_option_fk'
                )
                    ->references(['center_id', 'question_id', 'id'])
                    ->on('question_options')
                    ->restrictOnDelete();
            });
        }

        Schema::table('questions', function (Blueprint $table): void {
            $table->dropColumn('answer_config');
        });

        Schema::table('exercises', function (Blueprint $table): void {
            $table->decimal('passing_score', 5, 2)->default(60.00)->change();
        });
    }
};
