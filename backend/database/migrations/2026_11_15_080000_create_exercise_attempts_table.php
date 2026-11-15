<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercise_attempts', function (Blueprint $table) {
            $table->id();

            // Tenant and ownership.
            $table->unsignedBigInteger('center_id');
            $table->unsignedBigInteger('exercise_id');
            $table->unsignedBigInteger('student_profile_id');

            // Sequential attempt number per student and exercise.
            $table->unsignedInteger('attempt_number');

            // Attempt lifecycle.
            $table->string('status', 20)->default('in_progress');

            // Assessment configuration snapshots.
            $table->decimal('passing_score_snapshot', 5, 2);

            $table->boolean(
                'show_correct_answers_after_submission_snapshot'
            )->default(false);

            // Grading results. Null before submission.
            $table->decimal('score', 10, 2)->nullable();

            $table->decimal('max_score', 10, 2)->default(0);

            $table->decimal('percentage', 5, 2)->nullable();

            $table->boolean('passed')->nullable();

            // Attempt timestamps.
            $table->timestamp('started_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('graded_at')->nullable();

            $table->timestamps();

            // Required for tenant-safe composite relationships.
            $table->unique(
                ['center_id', 'id'],
                'exercise_attempts_center_id_unique'
            );

            // Parent identity used by answer foreign keys.
            $table->unique(
                ['center_id', 'exercise_id', 'id'],
                'exercise_attempts_center_exercise_id_unique'
            );

            // Attempt numbers cannot repeat for a student and exercise.
            $table->unique(
                [
                    'center_id',
                    'student_profile_id',
                    'exercise_id',
                    'attempt_number',
                ],
                'exercise_attempts_student_exercise_attempt_unique'
            );

            // Student attempt history and active attempt lookup.
            $table->index(
                [
                    'center_id',
                    'student_profile_id',
                    'exercise_id',
                    'status',
                ],
                'exercise_attempts_student_status_index'
            );

            // Exercise reporting.
            $table->index(
                ['center_id', 'exercise_id', 'status', 'started_at'],
                'exercise_attempts_exercise_status_index'
            );

            // Exercise must belong to the same center.
            $table->foreign(
                ['center_id', 'exercise_id'],
                'exercise_attempts_center_exercise_fk'
            )
                ->references(['center_id', 'id'])
                ->on('exercises')
                ->restrictOnDelete();

            // Student profile must belong to the same center.
            $table->foreign(
                ['center_id', 'student_profile_id'],
                'exercise_attempts_center_student_fk'
            )
                ->references(['center_id', 'id'])
                ->on('student_profiles')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_attempts');
    }
};
