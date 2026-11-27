import type { ExerciseAttemptData, ResultVisibilityPolicy } from "@/lib/learning";

export function resolveAttemptVisibility(
  attempt: ExerciseAttemptData
): ResultVisibilityPolicy {
  if (attempt.result_visibility) {
    return attempt.result_visibility;
  }
  const showCorrect = Boolean(attempt.show_correct_answers);
  return {
    results_released: true,
    release_timing: "immediately",
    show_score: attempt.score !== null,
    show_percentage: attempt.percentage !== null,
    show_pass_fail: attempt.passed !== null,
    show_question_results: showCorrect,
    show_student_answers: true,
    show_correct_answers: showCorrect,
    show_explanations: showCorrect,
    show_points_per_question: showCorrect,
  };
}
