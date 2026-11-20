<?php

namespace App\Http\Controllers\Api\Learning;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\Course;
use App\Models\Exercise;
use App\Models\ExerciseAttempt;
use App\Models\Lesson;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Learning\ExerciseAttemptService;
use App\Services\Learning\LearningAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ExerciseAttemptController extends Controller
{
    public function __construct(
        protected ExerciseAttemptService $attemptService,
        protected LearningAccessService $learningAccess
    ) {}

    /**
     * Retrieve the current attempt state and metadata for the student.
     */
    public function current(Request $request): JsonResponse
    {
        [$center, $course, $lesson, $exercise, $studentProfile] = $this->resolveContext($request);

        $activeAttempt = ExerciseAttempt::query()
            ->where('center_id', $center->id)
            ->where('student_profile_id', $studentProfile->id)
            ->where('exercise_id', $exercise->id)
            ->where('status', ExerciseAttempt::STATUS_IN_PROGRESS)
            ->with('answers')
            ->first();

        $completedAttempts = ExerciseAttempt::query()
            ->where('center_id', $center->id)
            ->where('student_profile_id', $studentProfile->id)
            ->where('exercise_id', $exercise->id)
            ->whereIn('status', [
                ExerciseAttempt::STATUS_SUBMITTED,
                ExerciseAttempt::STATUS_GRADED,
            ])
            ->orderByDesc('attempt_number')
            ->get();

        $completedCount = $completedAttempts->count();
        $latestCompleted = $completedAttempts->first();

        $canStartNew = $exercise->max_attempts === null || $completedCount < (int) $exercise->max_attempts;
        $attemptsRemaining = $exercise->max_attempts !== null
            ? max(0, (int) $exercise->max_attempts - $completedCount)
            : null;

        return response()->json([
            'data' => [
                'exercise' => [
                    'id' => $exercise->id,
                    'title' => $exercise->title,
                    'instructions' => $exercise->instructions,
                    'passing_score' => (float) $exercise->passing_score,
                    'max_attempts' => $exercise->max_attempts,
                    'attempts_count' => $completedCount,
                    'attempts_remaining' => $attemptsRemaining,
                    'can_start_new_attempt' => $canStartNew,
                    'has_active_attempt' => $activeAttempt !== null,
                ],
                'active_attempt' => $activeAttempt
                    ? $this->attemptService->formatAttemptPayload($activeAttempt)
                    : null,
                'latest_completed_attempt' => $latestCompleted
                    ? $this->attemptService->formatAttemptPayload($latestCompleted)
                    : null,
            ],
        ]);
    }

    /**
     * Start a new attempt or resume an active in-progress attempt.
     */
    public function start(Request $request): JsonResponse
    {
        [$center, $course, $lesson, $exercise, $studentProfile] = $this->resolveContext($request);

        $attempt = $this->attemptService->startOrResumeAttempt(
            $exercise,
            $studentProfile,
            $center
        );

        return response()->json([
            'message' => 'Attempt ready.',
            'data' => $this->attemptService->formatAttemptPayload($attempt),
        ], 201);
    }

    /**
     * Save answers for an in-progress attempt.
     */
    public function save(Request $request): JsonResponse
    {
        [$center, $course, $lesson, $exercise, $studentProfile] = $this->resolveContext($request);

        $validated = $request->validate([
            'answers' => ['required', 'array'],
            'answers.*.question_id' => ['required', 'integer', 'min:1'],
            'answers.*.selected_option_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $activeAttempt = ExerciseAttempt::query()
            ->where('center_id', $center->id)
            ->where('student_profile_id', $studentProfile->id)
            ->where('exercise_id', $exercise->id)
            ->where('status', ExerciseAttempt::STATUS_IN_PROGRESS)
            ->firstOrFail();

        $updated = $this->attemptService->saveAnswers(
            $activeAttempt,
            $validated['answers']
        );

        return response()->json([
            'message' => 'Answers saved successfully.',
            'data' => $this->attemptService->formatAttemptPayload($updated),
        ]);
    }

    /**
     * Submit an attempt for grading.
     */
    public function submit(Request $request): JsonResponse
    {
        [$center, $course, $lesson, $exercise, $studentProfile] = $this->resolveContext($request);

        $validated = $request->validate([
            'answers' => ['sometimes', 'array'],
            'answers.*.question_id' => ['required', 'integer', 'min:1'],
            'answers.*.selected_option_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $activeAttempt = ExerciseAttempt::query()
            ->where('center_id', $center->id)
            ->where('student_profile_id', $studentProfile->id)
            ->where('exercise_id', $exercise->id)
            ->where('status', ExerciseAttempt::STATUS_IN_PROGRESS)
            ->firstOrFail();

        $graded = $this->attemptService->submitAttempt(
            $activeAttempt,
            $validated['answers'] ?? []
        );

        return response()->json([
            'message' => 'Attempt submitted and graded successfully.',
            'data' => $this->attemptService->formatAttemptPayload($graded),
        ]);
    }

    /**
     * View history of attempts for this student on this exercise.
     */
    public function history(Request $request): JsonResponse
    {
        [$center, $course, $lesson, $exercise, $studentProfile] = $this->resolveContext($request);

        $attempts = ExerciseAttempt::query()
            ->where('center_id', $center->id)
            ->where('student_profile_id', $studentProfile->id)
            ->where('exercise_id', $exercise->id)
            ->with('answers')
            ->orderByDesc('attempt_number')
            ->get();

        return response()->json([
            'data' => $attempts->map(
                fn (ExerciseAttempt $attempt) => $this->attemptService->formatAttemptPayload($attempt)
            )->values(),
        ]);
    }

    /**
     * Resolve the verified student assessment context.
     *
     * @return array{0: Center, 1: Course, 2: Lesson, 3: Exercise, 4: StudentProfile}
     */
    private function resolveContext(Request $request): array
    {
        $center = $request->attributes->get('current_center');
        abort_unless($center instanceof Center, 404, 'Center not found.');

        $user = $request->user();
        abort_unless($user instanceof User, 401, 'Unauthenticated.');

        // Prevent preview modes from acting as students
        abort_if(
            $this->learningAccess->isAdministrativeViewer($user, $center) && ! $user->hasRoleInCenter($center, 'student'),
            403,
            'Student assessment requires a valid student account.'
        );

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
            ->firstOrFail();

        $exerciseParam = (string) $request->route('exercise');
        abort_unless(ctype_digit($exerciseParam), 404, 'Exercise not found.');

        [$exercise, $studentProfile] = $this->attemptService->resolveAuthorizedExercise(
            $user,
            $center,
            $course,
            $lesson,
            (int) $exerciseParam
        );

        return [$center, $course, $lesson, $exercise, $studentProfile];
    }
}
