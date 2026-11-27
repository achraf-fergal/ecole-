<?php

namespace Tests\Unit;

use App\Models\Exercise;
use App\Models\ExerciseAttempt;
use App\Models\ExerciseAttemptAnswer;
use App\Models\Question;
use App\Services\Learning\ExerciseAttemptService;
use Tests\TestCase;

class ExerciseResultVisibilityCharacterizationTest extends TestCase
{
    public function test_legacy_result_visibility_flags_are_preserved_for_both_release_timings(): void
    {
        $service = app(ExerciseAttemptService::class);
        $flagNames = [
            'show_score',
            'show_percentage',
            'show_pass_fail',
            'show_question_results',
            'show_student_answers',
            'show_correct_answers',
            'show_explanations',
            'show_points_per_question',
        ];

        foreach (Exercise::RELEASE_TIMINGS as $releaseTiming) {
            foreach ($flagNames as $changedFlag) {
                foreach ([false, true] as $flagValue) {
                    $configuredSettings = [
                        ...Exercise::defaultResultSettings(true),
                        'release_timing' => $releaseTiming,
                        $changedFlag => $flagValue,
                    ];
                    $expectedSettings = Exercise::normalizeResultSettings(
                        $configuredSettings,
                        true
                    );
                    $payload = $service->formatAttemptPayload(
                        $this->completedAttempt($expectedSettings)
                    );
                    $context = sprintf(
                        'release_timing=%s, %s=%s',
                        $releaseTiming,
                        $changedFlag,
                        $flagValue ? 'true' : 'false'
                    );

                    self::assertSame(true, $payload['result_visibility']['results_released'], $context);
                    self::assertSame($releaseTiming, $payload['result_visibility']['release_timing'], $context);

                    foreach ($flagNames as $flagName) {
                        self::assertSame(
                            $expectedSettings[$flagName],
                            $payload['result_visibility'][$flagName],
                            $context.'; '.$flagName
                        );
                    }

                    self::assertSame(
                        $expectedSettings['show_score'] ? 7.0 : null,
                        $payload['score'],
                        $context.'; score'
                    );
                    self::assertSame(
                        $expectedSettings['show_score'] ? 10.0 : null,
                        $payload['max_score'],
                        $context.'; max_score'
                    );
                    self::assertSame(
                        $expectedSettings['show_percentage'] ? 70.0 : null,
                        $payload['percentage'],
                        $context.'; percentage'
                    );
                    self::assertSame(
                        $expectedSettings['show_pass_fail'] ? true : null,
                        $payload['passed'],
                        $context.'; passed'
                    );

                    $question = $payload['questions'][0];
                    self::assertSame(
                        $expectedSettings['show_student_answers'] ? 12 : null,
                        $question['selected_option_id'],
                        $context.'; selected_option_id'
                    );
                    self::assertSame(
                        $expectedSettings['show_question_results'] ? false : null,
                        $question['is_correct'] ?? null,
                        $context.'; is_correct'
                    );
                    self::assertSame(
                        $expectedSettings['show_question_results']
                            && $expectedSettings['show_correct_answers'],
                        array_key_exists('correct_option_id', $question),
                        $context.'; correct_option_id visibility'
                    );
                    self::assertSame(
                        $expectedSettings['show_question_results']
                            && $expectedSettings['show_explanations'],
                        array_key_exists('explanation', $question),
                        $context.'; explanation visibility'
                    );
                    self::assertSame(
                        $expectedSettings['show_question_results']
                            && $expectedSettings['show_points_per_question'],
                        array_key_exists('points_awarded', $question),
                        $context.'; points_awarded visibility'
                    );
                }
            }
        }
    }

    private function completedAttempt(array $settings): ExerciseAttempt
    {
        $attempt = new ExerciseAttempt([
            'center_id' => 1,
            'exercise_id' => 2,
            'student_profile_id' => 3,
            'attempt_number' => 1,
            'status' => ExerciseAttempt::STATUS_GRADED,
            'passing_score_snapshot' => 60,
            'max_attempts_snapshot' => 1,
            'show_correct_answers_after_submission_snapshot' => true,
            'result_settings_snapshot' => $settings,
            'score' => 7,
            'max_score' => 10,
            'percentage' => 70,
            'passed' => true,
            'started_at' => now()->subMinutes(5),
            'submitted_at' => now(),
            'graded_at' => now(),
        ]);

        $answer = new ExerciseAttemptAnswer([
            'center_id' => 1,
            'exercise_id' => 2,
            'exercise_attempt_id' => 4,
            'question_id' => 5,
            'selected_option_id' => 12,
            'selected_option_ids' => [],
            'text_answer' => null,
            'correct_option_id_snapshot' => 11,
            'correct_option_ids_snapshot' => [11],
            'answer_config_snapshot' => [],
            'question_type_snapshot' => Question::TYPE_SINGLE_CHOICE,
            'prompt_snapshot' => 'Which option is correct?',
            'explanation_snapshot' => 'This explanation is visible only when allowed.',
            'options_snapshot' => [
                ['id' => 11, 'option_text' => 'Correct'],
                ['id' => 12, 'option_text' => 'Incorrect'],
            ],
            'points_possible' => 10,
            'is_correct' => false,
            'points_awarded' => 0,
        ]);

        $attempt->setRelation('answers', collect([$answer]));

        return $attempt;
    }
}
