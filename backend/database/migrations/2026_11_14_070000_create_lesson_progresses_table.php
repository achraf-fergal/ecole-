<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_progresses', function (Blueprint $table) {
            $table->id();

            // Tenant and student ownership.
            $table->unsignedBigInteger('center_id');
            $table->unsignedBigInteger('student_profile_id');
            $table->unsignedBigInteger('lesson_id');

            // A row is created when the student starts learning a lesson.
            $table->string('status', 20)->default('in_progress');
            $table->unsignedInteger('last_position_seconds')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();

            $table->timestamps();

            // One progress record per student and lesson within a center.
            $table->unique(
                ['center_id', 'student_profile_id', 'lesson_id'],
                'lesson_progresses_student_lesson_unique'
            );

            // Student course dashboard and completion summaries.
            $table->index(
                ['center_id', 'student_profile_id', 'status'],
                'lesson_progresses_student_status_index'
            );

            // Lesson-level progress lookup and reporting.
            $table->index(
                ['center_id', 'lesson_id'],
                'lesson_progresses_lesson_index'
            );

            // A student profile and lesson must belong to this same center.
            $table->foreign(
                ['center_id', 'student_profile_id'],
                'lesson_progresses_center_student_fk'
            )
                ->references(['center_id', 'id'])
                ->on('student_profiles')
                ->restrictOnDelete();

            $table->foreign(
                ['center_id', 'lesson_id'],
                'lesson_progresses_center_lesson_fk'
            )
                ->references(['center_id', 'id'])
                ->on('lessons')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_progresses');
    }
};
