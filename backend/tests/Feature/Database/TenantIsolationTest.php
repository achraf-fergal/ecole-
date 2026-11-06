<?php

namespace Tests\Feature\Database;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Course;
use App\Models\Language;
use App\Models\Level;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_center_scope_returns_only_its_courses(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $courseA = Course::factory()
            ->forCenter($centerA)
            ->create();

        $courseB = Course::factory()
            ->forCenter($centerB)
            ->create();

        $centerACourses = Course::query()
            ->forCenter($centerA->id)
            ->pluck('id')
            ->all();

        $this->assertContains($courseA->id, $centerACourses);

        $this->assertNotContains($courseB->id, $centerACourses);
    }

    public function test_course_cannot_use_language_from_another_center(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $foreignLanguage = Language::factory()
            ->for($centerB)
            ->french()
            ->create();

        $this->expectException(QueryException::class);

        Course::factory()
            ->forCenter($centerA)
            ->withLanguage($foreignLanguage)
            ->create();
    }

    public function test_course_cannot_use_level_from_another_center(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $foreignLevel = Level::factory()
            ->for($centerB)
            ->create();

        $this->expectException(QueryException::class);

        Course::factory()
            ->forCenter($centerA)
            ->withLevel($foreignLevel)
            ->create();
    }

    public function test_user_can_belong_to_two_centers(): void
    {
        $centerA = Center::factory()->create();

        $centerB = Center::factory()->create();

        $user = User::factory()->create();

        CenterUser::factory()
            ->for($centerA)
            ->for($user)
            ->create();

        CenterUser::factory()
            ->for($centerB)
            ->for($user)
            ->create();

        $this->assertCount(
            2,
            $user->centers()->get()
        );
    }

    public function test_duplicate_membership_in_same_center_is_rejected(): void
    {
        $center = Center::factory()->create();

        $user = User::factory()->create();

        CenterUser::factory()
            ->for($center)
            ->for($user)
            ->create();

        $this->expectException(QueryException::class);

        CenterUser::factory()
            ->for($center)
            ->for($user)
            ->create();
    }
}
