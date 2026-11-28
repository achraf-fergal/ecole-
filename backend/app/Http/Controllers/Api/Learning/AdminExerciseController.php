<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\Course;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\User;
use App\Services\Learning\AdminExerciseService;
use App\Services\Learning\LessonRevisionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminExerciseController extends Controller
{
    public function __construct(
        protected AdminExerciseService $exerciseService,
        protected LessonRevisionService $revisionService
    ) {}

    /**
     * List all exercises for a lesson.
     */
    public function index(Request $request): JsonResponse
    {
        [$center, $course, $lesson] = $this->resolveLessonContext($request);

        $exercises = Exercise::query()
            ->forCenter($center->id)
            ->where('lesson_id', $lesson->id)
            ->with(['questions.options'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $exercises->map(fn (Exercise $e) => $this->exercisePayload($e))->values(),
        ]);
    }

    /**
     * Show a single exercise.
     */
    public function show(Request $request): JsonResponse
    {
        [$center, $course, $lesson] = $this->resolveLessonContext($request);
        $exercise = $this->resolveExercise($lesson, (string) $request->route('exercise'));
        $exercise->load(['questions.options']);

        return response()->json([
            'data' => $this->exercisePayload($exercise),
        ]);
    }

    /**
     * Store a new exercise with optional questions & options.
     */
    public function store(Request $request): JsonResponse
    {
        [$center, $course, $lesson] = $this->resolveLessonContext($request);
        $actor = $request->user();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string'],
            'max_attempts' => ['nullable', 'integer', 'min:1'],
            'passing_score' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'show_correct_answers_after_submission' => ['sometimes', 'boolean'],
            'result_settings' => ['sometimes', 'nullable', 'array:show_score,show_percentage,show_pass_fail,show_question_results,show_student_answers,show_correct_answers,show_explanations,show_points_per_question,release_timing'],
            'result_settings.show_score' => ['sometimes', 'boolean'],
            'result_settings.show_percentage' => ['sometimes', 'boolean'],
            'result_settings.show_pass_fail' => ['sometimes', 'boolean'],
            'result_settings.show_question_results' => ['sometimes', 'boolean'],
            'result_settings.show_student_answers' => ['sometimes', 'boolean'],
            'result_settings.show_correct_answers' => ['sometimes', 'boolean'],
            'result_settings.show_explanations' => ['sometimes', 'boolean'],
            'result_settings.show_points_per_question' => ['sometimes', 'boolean'],
            'result_settings.release_timing' => ['sometimes', 'string', Rule::in(Exercise::RELEASE_TIMINGS)],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'questions' => ['sometimes', 'array'],
            'questions.*' => ['array:id,prompt,question_type,answer_config,explanation,points,sort_order,options'],
            'questions.*.id' => ['prohibited'],
            'questions.*.prompt' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'questions.*.question_type' => ['sometimes', 'string', Rule::in(Question::TYPES)],
            'questions.*.answer_config' => ['sometimes', 'nullable', 'array:accepted_answers,normalization,blanks'],
            'questions.*.answer_config.accepted_answers' => ['sometimes', 'array', 'max:20'],
            'questions.*.answer_config.accepted_answers.*' => ['nullable', 'string', 'max:500'],
            'questions.*.answer_config.normalization' => ['sometimes', 'array:case_sensitive,trim_whitespace,collapse_internal_whitespace,punctuation_sensitive,diacritic_sensitive'],
            'questions.*.answer_config.normalization.case_sensitive' => ['sometimes', 'boolean'],
            'questions.*.answer_config.normalization.trim_whitespace' => ['sometimes', 'boolean'],
            'questions.*.answer_config.normalization.collapse_internal_whitespace' => ['sometimes', 'boolean'],
            'questions.*.answer_config.normalization.punctuation_sensitive' => ['sometimes', 'boolean'],
            'questions.*.answer_config.normalization.diacritic_sensitive' => ['sometimes', 'boolean'],
            'questions.*.answer_config.blanks' => ['sometimes', 'array', 'max:20'],
            'questions.*.answer_config.blanks.*' => ['array:id,points,accepted_answers,normalization'],
            'questions.*.answer_config.blanks.*.id' => ['sometimes', 'string', 'max:50'],
            'questions.*.answer_config.blanks.*.points' => ['sometimes', 'numeric', 'min:0.01'],
            'questions.*.answer_config.blanks.*.accepted_answers' => ['sometimes', 'array', 'max:20'],
            'questions.*.answer_config.blanks.*.accepted_answers.*' => ['nullable', 'string', 'max:500'],
            'questions.*.answer_config.blanks.*.normalization' => ['sometimes', 'array:case_sensitive,trim_whitespace,collapse_internal_whitespace,punctuation_sensitive,diacritic_sensitive'],
            'questions.*.answer_config.blanks.*.normalization.case_sensitive' => ['sometimes', 'boolean'],
            'questions.*.answer_config.blanks.*.normalization.trim_whitespace' => ['sometimes', 'boolean'],
            'questions.*.answer_config.blanks.*.normalization.collapse_internal_whitespace' => ['sometimes', 'boolean'],
            'questions.*.answer_config.blanks.*.normalization.punctuation_sensitive' => ['sometimes', 'boolean'],
            'questions.*.answer_config.blanks.*.normalization.diacritic_sensitive' => ['sometimes', 'boolean'],
            'questions.*.explanation' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'questions.*.points' => ['sometimes', 'numeric', 'min:0.01'],
            'questions.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'questions.*.options' => ['sometimes', 'array', 'max:100'],
            'questions.*.options.*' => ['array:id,option_text,is_correct,sort_order'],
            'questions.*.options.*.id' => ['prohibited'],
            'questions.*.options.*.option_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'questions.*.options.*.is_correct' => ['sometimes', 'boolean'],
            'questions.*.options.*.sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $exercise = $this->exerciseService->saveAggregateExercise(
            $lesson,
            $validated,
            $actor
        );

        return response()->json([
            'message' => 'Exercise created successfully.',
            'data' => $this->exercisePayload($exercise),
        ], 201);
    }

    /**
     * Update an existing exercise.
     */
    public function update(Request $request): JsonResponse
    {
        [$center, $course, $lesson] = $this->resolveLessonContext($request);
        $exercise = $this->resolveExercise($lesson, (string) $request->route('exercise'));
        $actor = $request->user();

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'instructions' => ['sometimes', 'nullable', 'string'],
            'max_attempts' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'passing_score' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'show_correct_answers_after_submission' => ['sometimes', 'boolean'],
            'result_settings' => ['sometimes', 'nullable', 'array:show_score,show_percentage,show_pass_fail,show_question_results,show_student_answers,show_correct_answers,show_explanations,show_points_per_question,release_timing'],
            'result_settings.show_score' => ['sometimes', 'boolean'],
            'result_settings.show_percentage' => ['sometimes', 'boolean'],
            'result_settings.show_pass_fail' => ['sometimes', 'boolean'],
            'result_settings.show_question_results' => ['sometimes', 'boolean'],
            'result_settings.show_student_answers' => ['sometimes', 'boolean'],
            'result_settings.show_correct_answers' => ['sometimes', 'boolean'],
            'result_settings.show_explanations' => ['sometimes', 'boolean'],
            'result_settings.show_points_per_question' => ['sometimes', 'boolean'],
            'result_settings.release_timing' => ['sometimes', 'string', Rule::in(Exercise::RELEASE_TIMINGS)],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'questions' => ['sometimes', 'array'],
            'questions.*' => ['array:id,prompt,question_type,answer_config,explanation,points,sort_order,options'],
            'questions.*.id' => ['sometimes', 'nullable', 'integer'],
            'questions.*.prompt' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'questions.*.question_type' => ['sometimes', 'string', Rule::in(Question::TYPES)],
            'questions.*.answer_config' => ['sometimes', 'nullable', 'array:accepted_answers,normalization,blanks'],
            'questions.*.answer_config.accepted_answers' => ['sometimes', 'array', 'max:20'],
            'questions.*.answer_config.accepted_answers.*' => ['nullable', 'string', 'max:500'],
            'questions.*.answer_config.normalization' => ['sometimes', 'array:case_sensitive,trim_whitespace,collapse_internal_whitespace,punctuation_sensitive,diacritic_sensitive'],
            'questions.*.answer_config.normalization.case_sensitive' => ['sometimes', 'boolean'],
            'questions.*.answer_config.normalization.trim_whitespace' => ['sometimes', 'boolean'],
            'questions.*.answer_config.normalization.collapse_internal_whitespace' => ['sometimes', 'boolean'],
            'questions.*.answer_config.normalization.punctuation_sensitive' => ['sometimes', 'boolean'],
            'questions.*.answer_config.normalization.diacritic_sensitive' => ['sometimes', 'boolean'],
            'questions.*.answer_config.blanks' => ['sometimes', 'array', 'max:20'],
            'questions.*.answer_config.blanks.*' => ['array:id,points,accepted_answers,normalization'],
            'questions.*.answer_config.blanks.*.id' => ['sometimes', 'string', 'max:50'],
            'questions.*.answer_config.blanks.*.points' => ['sometimes', 'numeric', 'min:0.01'],
            'questions.*.answer_config.blanks.*.accepted_answers' => ['sometimes', 'array', 'max:20'],
            'questions.*.answer_config.blanks.*.accepted_answers.*' => ['nullable', 'string', 'max:500'],
            'questions.*.answer_config.blanks.*.normalization' => ['sometimes', 'array:case_sensitive,trim_whitespace,collapse_internal_whitespace,punctuation_sensitive,diacritic_sensitive'],
            'questions.*.answer_config.blanks.*.normalization.case_sensitive' => ['sometimes', 'boolean'],
            'questions.*.answer_config.blanks.*.normalization.trim_whitespace' => ['sometimes', 'boolean'],
            'questions.*.answer_config.blanks.*.normalization.collapse_internal_whitespace' => ['sometimes', 'boolean'],
            'questions.*.answer_config.blanks.*.normalization.punctuation_sensitive' => ['sometimes', 'boolean'],
            'questions.*.answer_config.blanks.*.normalization.diacritic_sensitive' => ['sometimes', 'boolean'],
            'questions.*.explanation' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'questions.*.points' => ['sometimes', 'numeric', 'min:0.01'],
            'questions.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'questions.*.options' => ['sometimes', 'array', 'max:100'],
            'questions.*.options.*' => ['array:id,option_text,is_correct,sort_order'],
            'questions.*.options.*.id' => ['sometimes', 'nullable', 'integer'],
            'questions.*.options.*.option_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'questions.*.options.*.is_correct' => ['sometimes', 'boolean'],
            'questions.*.options.*.sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $exercise = $this->exerciseService->saveAggregateExercise(
            $lesson,
            $validated,
            $actor,
            $exercise
        );

        return response()->json([
            'message' => 'Exercise updated successfully.',
            'data' => $this->exercisePayload($exercise),
        ]);
    }

    /**
     * Publish an exercise.
     */
    public function publish(Request $request): JsonResponse
    {
        [$center, $course, $lesson] = $this->resolveLessonContext($request);
        $exercise = $this->resolveExercise($lesson, (string) $request->route('exercise'));
        $actor = $request->user();

        $published = $this->exerciseService->publishExercise(
            $exercise,
            $actor
        );

        return response()->json([
            'message' => 'Exercise published successfully.',
            'data' => $this->exercisePayload($published),
        ]);
    }

    /**
     * Delete an exercise.
     */
    public function destroy(Request $request): JsonResponse
    {
        [$center, $course, $lesson] = $this->resolveLessonContext($request);
        $exercise = $this->resolveExercise($lesson, (string) $request->route('exercise'));

        $this->exerciseService->deleteExercise($exercise);

        return response()->json([
            'message' => 'Exercise deleted successfully.',
        ]);
    }

    /**
     * Grade a simulated student attempt in memory without persisting any records.
     */
    public function simulateGrade(Request $request): JsonResponse
    {
        [$center, $course, $lesson] = $this->resolveLessonContext($request);
        $exercise = $this->resolveExercise($lesson, (string) $request->route('exercise'));

        $validated = $request->validate([
            'attempt_scenario' => ['nullable', 'string', 'in:first,final'],
            'answers' => ['present', 'array'],
            'answers.*' => ['array:question_id,selected_option_id,selected_option_ids,text_answer,blank_answers'],
            'answers.*.question_id' => ['required', 'integer', 'min:1'],
            'answers.*.selected_option_id' => ['nullable', 'integer'],
            'answers.*.selected_option_ids' => ['nullable', 'array'],
            'answers.*.selected_option_ids.*' => ['integer'],
            'answers.*.text_answer' => ['nullable', 'string', 'max:5000'],
            'answers.*.blank_answers' => ['nullable', 'array'],
            'answers.*.blank_answers.*' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->exerciseService->simulateGrade(
            $exercise,
            $validated['answers'],
            (string) ($validated['attempt_scenario'] ?? 'first')
        );

        return response()->json([
            'data' => $result,
        ]);
    }

    /**
     * Resolve the authorized administrative lesson context.
     *
     * @return array{0: Center, 1: Course, 2: Lesson}
     */
    private function resolveLessonContext(Request $request): array
    {
        $center = $request->attributes->get('current_center');
        abort_unless($center instanceof Center, 404, 'Center not found.');

        $user = $request->user();
        abort_unless($user instanceof User, 401, 'Unauthenticated.');

        $hasAdminAccess = $user->isPlatformAdmin()
            || $user->hasAnyRoleInCenter($center, ['center_admin']);

        abort_unless($hasAdminAccess, 403, 'Administrative learning access is required.');

        $courseParam = (string) $request->route('course');
        $courseQuery = Course::query()->forCenter($center->id);
        if (ctype_digit($courseParam)) {
            $courseQuery->whereKey((int) $courseParam);
        } else {
            $courseQuery->where('slug', $courseParam);
        }
        $course = $courseQuery->firstOrFail();

        $lessonParam = (string) $request->route('lesson');
        abort_unless(ctype_digit($lessonParam), 404, 'Lesson not found.');

        $lesson = Lesson::query()
            ->forCenter($center->id)
            ->whereKey((int) $lessonParam)
            ->whereHas('courseModule', function (Builder $query) use ($center, $course): void {
                $query->forCenter($center->id)->where('course_id', $course->id);
            })
            ->firstOrFail();

        return [$center, $course, $lesson];
    }

    /**
     * Resolve exercise strictly within the lesson and center.
     */
    private function resolveExercise(Lesson $lesson, string $exerciseParam): Exercise
    {
        abort_unless(ctype_digit($exerciseParam), 404, 'Exercise not found.');

        return Exercise::query()
            ->forCenter($lesson->center_id)
            ->where('lesson_id', $lesson->id)
            ->whereKey((int) $exerciseParam)
            ->firstOrFail();
    }

    /**
     * Format exercise for administration.
     */
    private function exercisePayload(Exercise $exercise): array
    {
        $resolvedResultSettings = $exercise->resolvedResultSettings();
        $isIncompatible = $this->exerciseService->isIncompatibleReleaseConfiguration(
            $exercise->max_attempts,
            $resolvedResultSettings
        );
        $affectedAttempts = $isIncompatible
            ? $exercise->attempts()->count()
            : 0;

        return [
            'id' => $exercise->id,
            'title' => $exercise->title,
            'instructions' => $exercise->instructions,
            'max_attempts' => $exercise->max_attempts,
            'passing_score' => (float) $exercise->passing_score,
            'show_correct_answers_after_submission' => (bool) $resolvedResultSettings['show_correct_answers'],
            'result_settings' => $resolvedResultSettings,
            'has_incompatible_release_configuration' => $isIncompatible,
            'incompatible_release_affected_attempts' => $affectedAttempts,
            'sort_order' => $exercise->sort_order,
            'status' => $exercise->status,
            'published_at' => $exercise->published_at?->toIso8601String(),
            'questions' => $exercise->questions->map(function ($q): array {
                return [
                    'id' => $q->id,
                    'question_type' => $q->question_type,
                    'prompt' => $q->prompt,
                    'answer_config' => in_array($q->question_type, [Question::TYPE_SHORT_ANSWER, Question::TYPE_FILL_IN_THE_BLANKS], true)
                        ? $q->answer_config
                        : null,
                    'explanation' => $q->explanation,
                    'points' => (float) $q->points,
                    'sort_order' => $q->sort_order,
                    'options' => $q->options->map(function ($o): array {
                        return [
                            'id' => $o->id,
                            'option_text' => $o->option_text,
                            'is_correct' => (bool) $o->is_correct,
                            'sort_order' => $o->sort_order,
                        ];
                    })->values(),
                ];
            })->values(),
        ];
    }
}
