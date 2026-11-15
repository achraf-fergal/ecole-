<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercise_attempt_answers', function (Blueprint $table) {
            $table->id();

            // Tenant and parent attempt ownership.
            $table->unsignedBigInteger('center_id');
            $table->unsignedBigInteger('exercise_id');
            $table->unsignedBigInteger('exercise_attempt_id');
            $table->unsignedBigInteger('question_id');

            // Student-selected option. Null means unanswered.
            $table->unsignedBigInteger('selected_option_id')->nullable();

            // Internal answer key snapshot. Never expose to students.
            $table->unsignedBigInteger('correct_option_id_snapshot');

            // Question content snapshot at attempt creation.
            $table->string('question_type_snapshot', 20);
            $table->longText('prompt_snapshot');
            $table->longText('explanation_snapshot')->nullable();

            // Options as displayed when the attempt started.
            $table->json('options_snapshot');

            // Points available for this question at attempt creation.
            $table->decimal('points_possible', 8, 2);

            // Grading fields. Null until submission.
            $table->boolean('is_correct')->nullable();
            $table->decimal('points_awarded', 8, 2)->nullable();

            $table->timestamps();

            // A question can appear only once in an attempt.
            $table->unique(
                [
                    'center_id',
                    'exercise_attempt_id',
                    'question_id',
                ],
                'exercise_attempt_answers_attempt_question_unique'
            );

            // Tenant and exercise integrity for attempts.
            $table->foreign(
                [
                    'center_id',
                    'exercise_id',
                    'exercise_attempt_id',
                ],
                'exercise_attempt_answers_attempt_fk'
            )
                ->references([
                    'center_id',
                    'exercise_id',
                    'id',
                ])
                ->on('exercise_attempts')
                ->restrictOnDelete();

            // Question must belong to the same exercise.
            $table->foreign(
                [
                    'center_id',
                    'exercise_id',
                    'question_id',
                ],
                'exercise_attempt_answers_question_fk'
            )
                ->references([
                    'center_id',
                    'exercise_id',
                    'id',
                ])
                ->on('questions')
                ->restrictOnDelete();

            // Selected option must belong to the exact question.
            // Null is allowed for unanswered questions.
            $table->foreign(
                [
                    'center_id',
                    'question_id',
                    'selected_option_id',
                ],
                'exercise_attempt_answers_selected_option_fk'
            )
                ->references([
                    'center_id',
                    'question_id',
                    'id',
                ])
                ->on('question_options')
                ->restrictOnDelete();

            // The correct option snapshot must belong to the exact question.
            $table->foreign(
                [
                    'center_id',
                    'question_id',
                    'correct_option_id_snapshot',
                ],
                'exercise_attempt_answers_correct_option_fk'
            )
                ->references([
                    'center_id',
                    'question_id',
                    'id',
                ])
                ->on('question_options')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_attempt_answers');
    }
};
