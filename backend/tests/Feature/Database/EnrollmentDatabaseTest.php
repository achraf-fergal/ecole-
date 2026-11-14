<?php

namespace Tests\Feature\Database;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EnrollmentDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a valid enrollment test environment.
     *
     * Every related record belongs to the same center.
     */
    private function createEnrollmentContext(): array
    {
        $center = Center::factory()->create();

        $studentMembership = CenterUser::factory()
            ->for($center)
            ->create();

        $student = StudentProfile::factory()
            ->forMembership($studentMembership)
            ->create();

        $grantor = CenterUser::factory()
            ->for($center)
            ->create();

        $course = Course::factory()
            ->forCenter($center)
            ->create();

        $cohort = Cohort::factory()
            ->forCourse($course)
            ->create();

        $enrollment = Enrollment::factory()
            ->forStudentProfile($student)
            ->forCohort($cohort)
            ->grantedBy($grantor)
            ->create();

        return compact(
            'center',
            'studentMembership',
            'student',
            'grantor',
            'course',
            'cohort',
            'enrollment'
        );
    }

    public function test_enrollments_table_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('enrollments', [
                'id',
                'center_id',
                'student_profile_id',
                'cohort_id',
                'granted_by_user_id',
                'status',
                'start_at',
                'end_at',
                'created_at',
                'updated_at',
            ])
        );
    }

    public function test_enrollment_has_correct_relationships(): void
    {
        [
            'center' => $center,
            'student' => $student,
            'grantor' => $grantor,
            'cohort' => $cohort,
            'enrollment' => $enrollment,
        ] = $this->createEnrollmentContext();

        $this->assertTrue(
            $enrollment->center->is($center)
        );

        $this->assertTrue(
            $enrollment->studentProfile->is($student)
        );

        $this->assertTrue(
            $enrollment->cohort->is($cohort)
        );

        $this->assertTrue(
            $enrollment->grantedBy->is($grantor->user)
        );

        $this->assertTrue(
            $student->enrollments()
                ->whereKey($enrollment->id)
                ->exists()
        );

        $this->assertTrue(
            $cohort->enrollments()
                ->whereKey($enrollment->id)
                ->exists()
        );

        $this->assertTrue(
            $center->enrollments()
                ->whereKey($enrollment->id)
                ->exists()
        );
    }

    public function test_student_can_have_multiple_enrollments(): void
    {
        [
            'student' => $student,
            'course' => $course,
            'grantor' => $grantor,
            'enrollment' => $firstEnrollment,
        ] = $this->createEnrollmentContext();

        $secondCohort = Cohort::factory()
            ->forCourse($course)
            ->create();

        $secondEnrollment = Enrollment::factory()
            ->forStudentProfile($student)
            ->forCohort($secondCohort)
            ->grantedBy($grantor)
            ->create();

        $this->assertCount(
            2,
            $student->fresh()->enrollments
        );

        $this->assertNotSame(
            $firstEnrollment->cohort_id,
            $secondEnrollment->cohort_id
        );
    }

    public function test_enrollment_cannot_reference_student_from_another_center(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $studentMembershipB = CenterUser::factory()
            ->for($centerB)
            ->create();

        $studentB = StudentProfile::factory()
            ->forMembership($studentMembershipB)
            ->create();

        $courseA = Course::factory()
            ->forCenter($centerA)
            ->create();

        $cohortA = Cohort::factory()
            ->forCourse($courseA)
            ->create();

        $grantorA = CenterUser::factory()
            ->for($centerA)
            ->create();

        $this->expectException(QueryException::class);

        Enrollment::query()->create([
            'center_id' => $centerA->id,
            'student_profile_id' => $studentB->id,
            'cohort_id' => $cohortA->id,
            'granted_by_user_id' => $grantorA->user_id,
            'status' => Enrollment::STATUS_ACTIVE,
            'start_at' => now(),
        ]);
    }

    public function test_enrollment_cannot_reference_cohort_from_another_center(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $studentMembershipA = CenterUser::factory()
            ->for($centerA)
            ->create();

        $studentA = StudentProfile::factory()
            ->forMembership($studentMembershipA)
            ->create();

        $courseB = Course::factory()
            ->forCenter($centerB)
            ->create();

        $cohortB = Cohort::factory()
            ->forCourse($courseB)
            ->create();

        $grantorA = CenterUser::factory()
            ->for($centerA)
            ->create();

        $this->expectException(QueryException::class);

        Enrollment::query()->create([
            'center_id' => $centerA->id,
            'student_profile_id' => $studentA->id,
            'cohort_id' => $cohortB->id,
            'granted_by_user_id' => $grantorA->user_id,
            'status' => Enrollment::STATUS_ACTIVE,
            'start_at' => now(),
        ]);
    }

    public function test_grantor_must_belong_to_same_center(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $studentMembershipA = CenterUser::factory()
            ->for($centerA)
            ->create();

        $studentA = StudentProfile::factory()
            ->forMembership($studentMembershipA)
            ->create();

        $courseA = Course::factory()
            ->forCenter($centerA)
            ->create();

        $cohortA = Cohort::factory()
            ->forCourse($courseA)
            ->create();

        $grantorB = CenterUser::factory()
            ->for($centerB)
            ->create();

        $this->expectException(QueryException::class);

        Enrollment::query()->create([
            'center_id' => $centerA->id,
            'student_profile_id' => $studentA->id,
            'cohort_id' => $cohortA->id,
            'granted_by_user_id' => $grantorB->user_id,
            'status' => Enrollment::STATUS_ACTIVE,
            'start_at' => now(),
        ]);
    }

    public function test_duplicate_enrollment_for_same_cohort_is_rejected(): void
    {
        [
            'student' => $student,
            'cohort' => $cohort,
            'grantor' => $grantor,
        ] = $this->createEnrollmentContext();

        $this->expectException(QueryException::class);

        Enrollment::factory()
            ->forStudentProfile($student)
            ->forCohort($cohort)
            ->grantedBy($grantor)
            ->create();
    }

    public function test_active_scope_returns_only_valid_enrollments(): void
    {
        [
            'center' => $center,
            'student' => $student,
            'course' => $course,
            'grantor' => $grantor,
            'enrollment' => $currentEnrollment,
        ] = $this->createEnrollmentContext();

        $currentEnrollment->update([
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'status' => Enrollment::STATUS_ACTIVE,
        ]);

        // Future enrollment.
        $futureCohort = Cohort::factory()
            ->forCourse($course)
            ->create();

        Enrollment::factory()
            ->forStudentProfile($student)
            ->forCohort($futureCohort)
            ->grantedBy($grantor)
            ->create([
                'start_at' => now()->addDay(),
            ]);

        // Expired enrollment.
        $expiredCohort = Cohort::factory()
            ->forCourse($course)
            ->create();

        Enrollment::factory()
            ->forStudentProfile($student)
            ->forCohort($expiredCohort)
            ->grantedBy($grantor)
            ->create([
                'start_at' => now()->subDays(5),
                'end_at' => now()->subDay(),
            ]);

        // Suspended enrollment.
        $suspendedCohort = Cohort::factory()
            ->forCourse($course)
            ->create();

        Enrollment::factory()
            ->forStudentProfile($student)
            ->forCohort($suspendedCohort)
            ->grantedBy($grantor)
            ->suspended()
            ->create();

        // Completed enrollment.
        $completedCohort = Cohort::factory()
            ->forCourse($course)
            ->create();

        Enrollment::factory()
            ->forStudentProfile($student)
            ->forCohort($completedCohort)
            ->grantedBy($grantor)
            ->completed()
            ->create();

        $activeEnrollments = Enrollment::query()
            ->forCenter($center->id)
            ->forStudentProfile($student->id)
            ->activeAt(now())
            ->get();

        $this->assertCount(1, $activeEnrollments);

        $this->assertSame(
            $currentEnrollment->id,
            $activeEnrollments->first()->id
        );
    }

    public function test_enrollment_access_depends_on_status_and_dates(): void
    {
        ['enrollment' => $enrollment] =
            $this->createEnrollmentContext();

        $this->assertTrue(
            $enrollment->isActiveAt()
        );

        $enrollment->update([
            'status' => Enrollment::STATUS_SUSPENDED,
        ]);

        $this->assertFalse(
            $enrollment->fresh()->isActiveAt()
        );
    }
}
