<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAsset;
use App\Services\Learning\LearningAccessService;
use App\Services\Learning\LessonAssetAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class LessonAssetAccessController extends Controller
{
    public function __construct(
        protected LessonAssetAccessService $assetAccess,
        protected LearningAccessService $learningAccess
    ) {}

    /**
     * Issue authorized preview access for a lesson asset.
     */
    public function show(Request $request): JsonResponse
    {
        $asset = $this->resolveAsset($request);

        return response()->json([
            'data' => $this->assetAccess->createPreviewAccess($asset),
        ]);
    }

    /**
     * Download an asset when its download permission is enabled.
     */
    public function download(Request $request): Response
    {
        $asset = $this->resolveAsset($request);

        return $this->assetAccess->download($asset);
    }

    /**
     * Resolve the asset inside an authorized course and lesson.
     */
    private function resolveAsset(Request $request): LessonAsset
    {
        $center = $request->attributes->get('current_center');

        abort_unless(
            $center instanceof Center,
            404,
            'Center not found.'
        );

        $courseParam = (string) $request->route('course');

        $courseQuery = Course::query()
            ->forCenter($center->id);

        if (ctype_digit($courseParam)) {
            $courseQuery->whereKey((int) $courseParam);
        } else {
            $courseQuery->where('slug', $courseParam);
        }

        $course = $courseQuery->firstOrFail();

        Gate::authorize(
            'viewLearning',
            [$course, $center]
        );

        $isAdminPreview = $this->learningAccess
            ->isAdministrativeViewer(
                $request->user(),
                $center
            );

        $lessonParam = (string) $request->route('lesson');

        abort_unless(
            ctype_digit($lessonParam),
            404,
            'Lesson not found.'
        );

        $lessonQuery = Lesson::query()
            ->forCenter($center->id)
            ->whereKey((int) $lessonParam);

        if (! $isAdminPreview) {
            $lessonQuery
                ->published()
                ->whereHas(
                    'courseModule',
                    function (Builder $query) use ($center, $course): void {
                        $query
                            ->forCenter($center->id)
                            ->where('course_id', $course->id)
                            ->published();
                    }
                );
        } else {
            // Administrators may preview assets in unpublished lessons.
            $lessonQuery->whereHas(
                'courseModule',
                function (Builder $query) use ($center, $course): void {
                    $query
                        ->forCenter($center->id)
                        ->where('course_id', $course->id);
                }
            );
        }

        $lesson = $lessonQuery->firstOrFail();

        Gate::authorize(
            'viewLearning',
            [$lesson, $course, $center]
        );

        $assetParam = (string) $request->route('asset');

        abort_unless(
            ctype_digit($assetParam),
            404,
            'Asset not found.'
        );

        // Archived assets remain unavailable until explicitly restored.
        return LessonAsset::query()
            ->forCenter($center->id)
            ->where('lesson_id', $lesson->id)
            ->active()
            ->whereKey((int) $assetParam)
            ->firstOrFail();
    }
}