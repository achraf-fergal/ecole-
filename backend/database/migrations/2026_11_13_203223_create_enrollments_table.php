
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();

            // Tenant ownership.
            $table->unsignedBigInteger('center_id');

            // Student and teaching group.
            $table->unsignedBigInteger('student_profile_id');
            $table->unsignedBigInteger('cohort_id');

            // Center member who granted the enrollment.
            // Nullable only for controlled system imports.
            $table->unsignedBigInteger('granted_by_user_id')->nullable();

            // Enrollment lifecycle.
            $table->string('status', 20)->default('active');

            // Access validity period (stored in UTC).
            $table->timestamp('start_at')->useCurrent();
            $table->timestamp('end_at')->nullable();

            $table->timestamps();

            // Required for tenant-safe child relationships.
            $table->unique(
                ['center_id', 'id'],
                'enrollments_center_id_unique'
            );

            // Prevent duplicate enrollment in the same cohort.
            $table->unique(
                ['center_id', 'student_profile_id', 'cohort_id'],
                'enrollments_student_cohort_unique'
            );

            // Student dashboard: active enrollments within validity period.
            $table->index(
                ['center_id', 'student_profile_id', 'status', 'start_at'],
                'enrollments_student_status_index'
            );

            // Cohort student listing.
            $table->index(
                ['center_id', 'cohort_id', 'status'],
                'enrollments_cohort_status_index'
            );

            // Enrollment grantor lookup.
            $table->index(
                ['center_id', 'granted_by_user_id'],
                'enrollments_grantor_index'
            );

            // Center ownership.
            $table->foreign('center_id', 'enrollments_center_fk')
                ->references('id')
                ->on('centers')
                ->restrictOnDelete();

            // Student profile must belong to the same center.
            $table->foreign(
                ['center_id', 'student_profile_id'],
                'enrollments_student_fk'
            )
                ->references(['center_id', 'id'])
                ->on('student_profiles')
                ->restrictOnDelete();

            // Cohort must belong to the same center.
            $table->foreign(
                ['center_id', 'cohort_id'],
                'enrollments_cohort_fk'
            )
                ->references(['center_id', 'id'])
                ->on('cohorts')
                ->restrictOnDelete();

            // Grantor must be a member of the same center.
            $table->foreign(
                ['center_id', 'granted_by_user_id'],
                'enrollments_granted_by_fk'
            )
                ->references(['center_id', 'user_id'])
                ->on('center_user')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
