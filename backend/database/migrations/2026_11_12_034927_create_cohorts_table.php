
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cohorts', function (Blueprint $table) {
            $table->id();

            // Tenant and parent course ownership.
            $table->unsignedBigInteger('center_id');
            $table->unsignedBigInteger('course_id');

            // Assigned teacher from the same center membership.
            $table->unsignedBigInteger('teacher_user_id')->nullable();

            // Group identification.
            $table->string('name', 150);
            $table->string('code', 50)->nullable();

            // Group duration. Draft groups may be incomplete.
            // These timestamps are stored as UTC.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // Maximum number of students.
            $table->unsignedSmallInteger('capacity');

            // Physical location or branch label.
            $table->string('location_label', 150)->nullable();

            // Enrollment and group lifecycle.
            $table->string('status', 20)->default('draft');

            $table->timestamps();

            // Required for future tenant-safe child relationships.
            $table->unique(
                ['center_id', 'id'],
                'cohorts_center_id_unique'
            );

            // Cohort codes are unique within each center.
            $table->unique(
                ['center_id', 'code'],
                'cohorts_center_code_unique'
            );

            // Efficient course cohort listing.
            $table->index(
                ['center_id', 'course_id', 'status', 'starts_at'],
                'cohorts_course_status_start_index'
            );

            // Efficient teacher group listing.
            $table->index(
                ['center_id', 'teacher_user_id', 'status'],
                'cohorts_teacher_status_index'
            );

            // A cohort must belong to a course owned by the same center.
            $table->foreign(
                ['center_id', 'course_id'],
                'cohorts_center_course_fk'
            )
                ->references(['center_id', 'id'])
                ->on('courses')
                ->restrictOnDelete();

            /*
             * If a teacher is assigned, their membership
             * must belong to the same center.
             *
             * NULL teacher_user_id is allowed for draft cohorts.
             */
            $table->foreign(
                ['center_id', 'teacher_user_id'],
                'cohorts_center_teacher_membership_fk'
            )
                ->references(['center_id', 'user_id'])
                ->on('center_user')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cohorts');
    }
};
