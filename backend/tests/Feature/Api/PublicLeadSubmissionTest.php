<?php

namespace Tests\Feature\Api;

use App\Models\Center;
use App\Models\Course;
use App\Models\Language;
use App\Models\Lead;
use App\Models\Level;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicLeadSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private function createPublishedCourse(
        Center $center,
        array $attributes = []
    ): Course {
        $language = Language::factory()
            ->for($center)
            ->create();

        $level = Level::factory()
            ->for($center)
            ->create();

        return Course::factory()->create(array_merge([
            'center_id' => $center->id,
            'language_id' => $language->id,
            'level_id' => $level->id,
            'source_locale' => 'en',
            'title' => 'General English A1',
            'slug' => 'general-english-a1',
            'status' => 'published',
            'published_at' => now(),
        ], $attributes));
    }

    private function endpoint(
        Center $center,
        Course $course
    ): string {
        return "/api/public/centers/{$center->slug}/courses/{$course->slug}/leads";
    }

    public function test_valid_course_request_creates_a_lead_with_server_owned_values(): void
    {
        $center = Center::factory()->create();
        $course = $this->createPublishedCourse($center);

        $response = $this->postJson(
            $this->endpoint($center, $course),
            [
                'name' => 'John Student',
                'phone' => '+212600000001',
                'email' => 'john@example.com',
                'preferred_contact_method' => 'whatsapp',
                'message' => 'I would like to join this course.',
                'privacy_notice_accepted' => true,
                'center_id' => 999,
                'course_id' => 999,
                'source' => Lead::SOURCE_MANUAL,
                'source_detail' => 'visitor_override',
                'status' => Lead::STATUS_CONVERTED,
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', Lead::STATUS_NEW)
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.email')
            ->assertJsonMissingPath('data.phone');

        $lead = Lead::query()->firstOrFail();

        $this->assertSame($center->id, $lead->center_id);
        $this->assertSame($course->id, $lead->course_id);
        $this->assertSame('John Student', $lead->name);
        $this->assertSame('john@example.com', $lead->email);
        $this->assertSame('+212600000001', $lead->phone);
        $this->assertSame('whatsapp', $lead->preferred_contact_method);
        $this->assertSame(Lead::SOURCE_WEBSITE, $lead->source);
        $this->assertSame('course_details_page', $lead->source_detail);
        $this->assertSame(Lead::STATUS_NEW, $lead->status);
        $this->assertNotNull($lead->privacy_notice_accepted_at);

        $this->assertDatabaseCount('leads', 1);
    }

    public function test_request_requires_phone_or_email(): void
    {
        $center = Center::factory()->create();
        $course = $this->createPublishedCourse($center);

        $this->postJson($this->endpoint($center, $course), [
            'name' => 'John Student',
            'phone' => null,
            'email' => null,
            'privacy_notice_accepted' => true,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_request_requires_privacy_acceptance(): void
    {
        $center = Center::factory()->create();
        $course = $this->createPublishedCourse($center);

        $this->postJson($this->endpoint($center, $course), [
            'name' => 'John Student',
            'phone' => '+212600000001',
            'privacy_notice_accepted' => false,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_inactive_center_cannot_receive_public_leads(): void
    {
        $center = Center::factory()->inactive()->create();
        $course = $this->createPublishedCourse($center);

        $this->postJson($this->endpoint($center, $course), [
            'name' => 'John Student',
            'phone' => '+212600000001',
            'privacy_notice_accepted' => true,
        ])->assertNotFound();

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_unpublished_course_cannot_receive_public_leads(): void
    {
        $center = Center::factory()->create();
        $course = $this->createPublishedCourse($center, [
            'status' => 'draft',
            'published_at' => null,
        ]);

        $this->postJson($this->endpoint($center, $course), [
            'name' => 'John Student',
            'phone' => '+212600000001',
            'privacy_notice_accepted' => true,
        ])->assertNotFound();

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_course_with_inactive_language_cannot_receive_public_leads(): void
    {
        $center = Center::factory()->create();
        $course = $this->createPublishedCourse($center);
        $course->language()->update(['is_active' => false]);

        $this->postJson($this->endpoint($center, $course), [
            'name' => 'John Student',
            'phone' => '+212600000001',
            'privacy_notice_accepted' => true,
        ])->assertNotFound();

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_course_with_inactive_level_cannot_receive_public_leads(): void
    {
        $center = Center::factory()->create();
        $course = $this->createPublishedCourse($center);
        $course->level()->update(['is_active' => false]);

        $this->postJson($this->endpoint($center, $course), [
            'name' => 'John Student',
            'phone' => '+212600000001',
            'privacy_notice_accepted' => true,
        ])->assertNotFound();

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_course_from_another_center_cannot_receive_leads(): void
    {
        $centerA = Center::factory()->create();
        $centerB = Center::factory()->create();
        $courseB = $this->createPublishedCourse($centerB, [
            'slug' => 'course-from-center-b',
        ]);

        $this->postJson($this->endpoint($centerA, $courseB), [
            'name' => 'John Student',
            'phone' => '+212600000001',
            'privacy_notice_accepted' => true,
        ])->assertNotFound();

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_honeypot_submission_is_not_saved(): void
    {
        $center = Center::factory()->create();
        $course = $this->createPublishedCourse($center);

        $this->postJson($this->endpoint($center, $course), [
            'website' => 'https://spam.example.com',
        ])->assertAccepted();

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_invalid_preferred_contact_method_is_rejected(): void
    {
        $center = Center::factory()->create();
        $course = $this->createPublishedCourse($center);

        $this->postJson($this->endpoint($center, $course), [
            'name' => 'John Student',
            'phone' => '+212600000001',
            'preferred_contact_method' => 'telegram',
            'privacy_notice_accepted' => true,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('leads', 0);
    }
}
