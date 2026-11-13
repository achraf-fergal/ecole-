<?php

namespace Tests\Feature\Database;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Course;
use App\Models\Lead;
use App\Models\StudentProfile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LeadDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private function createProfile(
        Center $center,
        string $studentCode
    ): StudentProfile {
        $membership = CenterUser::factory()
            ->for($center)
            ->create();

        return StudentProfile::factory()
            ->forMembership($membership)
            ->create([
                'student_code' => $studentCode,
            ]);
    }

    public function test_leads_table_has_expected_columns(): void
    {
        $this->assertTrue(
            Schema::hasColumns('leads', [
                'id',
                'center_id',
                'course_id',
                'assigned_to_user_id',
                'converted_student_profile_id',
                'converted_by_user_id',
                'duplicate_of_lead_id',
                'name',
                'phone',
                'email',
                'preferred_contact_method',
                'message',
                'source',
                'source_detail',
                'status',
                'lost_reason',
                'next_follow_up_at',
                'privacy_notice_accepted_at',
                'converted_at',
                'created_at',
                'updated_at',
            ])
        );
    }

    public function test_lead_has_correct_relationships(): void
    {
        $center = Center::factory()->create();

        $course = Course::factory()
            ->forCenter($center)
            ->create();

        $assignedMembership = CenterUser::factory()
            ->for($center)
            ->create();

        $convertedMembership = CenterUser::factory()
            ->for($center)
            ->create();

        $profile = StudentProfile::factory()
            ->forMembership($convertedMembership)
            ->create([
                'student_code' => 'STU-LEAD-001',
            ]);

        $lead = Lead::factory()
            ->forCourse($course)
            ->assignedTo($assignedMembership)
            ->convertedTo($profile, $convertedMembership)
            ->create();

        $this->assertTrue($lead->center->is($center));

        $this->assertTrue($lead->course->is($course));

        $this->assertTrue(
            $lead->assignedTo->is($assignedMembership->user)
        );

        $this->assertTrue(
            $lead->convertedStudent->is($profile)
        );

        $this->assertTrue(
            $lead->convertedBy->is($convertedMembership->user)
        );

        $this->assertTrue($lead->isConverted());

        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'center_id' => $center->id,
            'status' => Lead::STATUS_CONVERTED,
        ]);
    }

    public function test_lead_cannot_reference_course_from_another_center(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $courseB = Course::factory()
            ->forCenter($centerB)
            ->create();

        $this->expectException(QueryException::class);

        Lead::factory()->create([
            'center_id' => $centerA->id,
            'course_id' => $courseB->id,
        ]);
    }

    public function test_assigned_staff_must_belong_to_same_center(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $membershipB = CenterUser::factory()
            ->for($centerB)
            ->create();

        $this->expectException(QueryException::class);

        Lead::factory()->create([
            'center_id' => $centerA->id,
            'assigned_to_user_id' => $membershipB->user_id,
        ]);
    }

    public function test_converted_student_profile_must_belong_to_same_center(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $profileB = $this->createProfile(
            $centerB,
            'STU-CROSS-001'
        );

        $this->expectException(QueryException::class);

        Lead::factory()->create([
            'center_id' => $centerA->id,
            'converted_student_profile_id' => $profileB->id,
            'status' => Lead::STATUS_CONVERTED,
            'converted_at' => now(),
        ]);
    }

    public function test_duplicate_lead_must_belong_to_same_center(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $original = Lead::factory()
            ->create([
                'center_id' => $centerA->id,
            ]);

        $this->expectException(QueryException::class);

        Lead::factory()
            ->create([
                'center_id' => $centerB->id,
                'duplicate_of_lead_id' => $original->id,
                'status' => Lead::STATUS_DUPLICATE,
            ]);
    }

    public function test_general_inquiry_can_exist_without_course(): void
    {
        $center = Center::factory()->create();

        $lead = Lead::factory()
            ->create([
                'center_id' => $center->id,
                'course_id' => null,
            ]);

        $this->assertNull($lead->course_id);

        $this->assertTrue($lead->center->is($center));

        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'course_id' => null,
        ]);
    }

    public function test_duplicate_requests_are_preserved_for_staff_review(): void
    {
        $center = Center::factory()->create();

        $course = Course::factory()
            ->forCenter($center)
            ->create();

        $first = Lead::factory()
            ->forCourse($course)
            ->create([
                'phone' => '+212600000001',
                'email' => 'student@example.com',
            ]);

        $second = Lead::factory()
            ->forCourse($course)
            ->create([
                'phone' => '+212600000001',
                'email' => 'student@example.com',
            ]);

        $this->assertNotSame($first->id, $second->id);

        $this->assertDatabaseCount('leads', 2);

        $this->assertSame(
            $first->phone,
            $second->phone
        );

        $this->assertSame(
            $first->email,
            $second->email
        );
    }

    public function test_lead_queries_are_scoped_by_center_and_status(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        Lead::factory()
            ->create([
                'center_id' => $centerA->id,
                'status' => Lead::STATUS_NEW,
            ]);

        Lead::factory()
            ->create([
                'center_id' => $centerA->id,
                'status' => Lead::STATUS_FOLLOW_UP,
            ]);

        Lead::factory()
            ->create([
                'center_id' => $centerB->id,
                'status' => Lead::STATUS_NEW,
            ]);

        $results = Lead::query()
            ->forCenter($centerA->id)
            ->withStatus(Lead::STATUS_NEW)
            ->get();

        $this->assertCount(1, $results);

        $this->assertSame(
            $centerA->id,
            $results->first()->center_id
        );

        $this->assertSame(
            Lead::STATUS_NEW,
            $results->first()->status
        );
    }
}
