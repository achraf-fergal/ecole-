<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Center;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformCenterController extends Controller
{
    /**
     * List all institutes for platform administrators.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Center::query()
            ->withCount([
                'courses',
                'memberships',
            ]);

        if (! empty($validated['search'])) {
            $search = trim($validated['search']);

            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $centers = $query
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        $centers->through(
            static function (Center $center): array {
                return [
                    'id' => $center->id,
                    'name' => $center->name,
                    'slug' => $center->slug,
                    'status' => $center->status,
                    'logo_url' => $center->logo_url,
                    'default_locale' => $center->default_locale,
                    'courses_count' => $center->courses_count,
                    'members_count' => $center->memberships_count,
                ];
            }
        );

        return response()->json($centers);
    }

    /**
     * List all courses belonging to one selected institute.
     *
     * Draft, published and archived courses are included.
     */
    public function courses(
        Request $request,
        Center $center
    ): JsonResponse {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => [
                'nullable',
                'string',
                'in:draft,published,archived',
            ],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // The relationship guarantees center-scoped course queries.
        $query = $center->courses()
            ->withCount('modules')
            ->select([
                'id',
                'center_id',
                'title',
                'slug',
                'short_description',
                'cover_image_url',
                'duration_hours',
                'status',
                'published_at',
                'sort_order',
            ])->withCount('modules');

        if (! empty($validated['search'])) {
            $search = trim($validated['search']);

            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $courses = $query
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        $courses->through(
            static function ($course): array {
                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'slug' => $course->slug,
                    'short_description' => $course->short_description,
                    'cover_image_url' => $course->cover_image_url,
                    'duration_hours' => $course->duration_hours,
                    'status' => $course->status,
                    'published_at' => $course->published_at?->toIso8601String(),
                    'modules_count' => $course->modules_count,
                ];
            }
        );

        return response()->json([
            'center' => [
                'id' => $center->id,
                'name' => $center->name,
                'slug' => $center->slug,
                'status' => $center->status,
                'logo_url' => $center->logo_url,
                'default_locale' => $center->default_locale,
            ],
            'courses' => $courses,
        ]);
    }
}