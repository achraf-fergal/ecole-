<?php

namespace Tests\Unit;

use App\Models\ExerciseAttemptAnswer;
use App\Models\Question;
use App\Services\Learning\ExerciseAnswerGrader;
use PHPUnit\Framework\TestCase;

class ExerciseAnswerGraderTest extends TestCase
{
    public function test_multiple_select_grades_as_an_exact_order_independent_set(): void
    {
        $grader = new ExerciseAnswerGrader;
        $answer = new ExerciseAttemptAnswer;
        $answer->question_type_snapshot = Question::TYPE_MULTIPLE_SELECT;
        $answer->correct_option_ids_snapshot = [2, 4];

        $answer->selected_option_ids = [4, 2];
        $this->assertTrue($grader->isCorrect($answer));

        $answer->selected_option_ids = [2];
        $this->assertFalse($grader->isCorrect($answer));

        $answer->selected_option_ids = [2, 4, 5];
        $this->assertFalse($grader->isCorrect($answer));

        $answer->selected_option_ids = [];
        $this->assertFalse($grader->isCorrect($answer));
    }

    public function test_short_answer_uses_exact_configured_normalization(): void
    {
        $grader = new ExerciseAnswerGrader;
        $answer = new ExerciseAttemptAnswer;
        $answer->question_type_snapshot = Question::TYPE_SHORT_ANSWER;
        $answer->answer_config_snapshot = [
            'accepted_answers' => ['I am', "I'm"],
            'normalization' => $grader->defaultNormalization(),
        ];

        $answer->text_answer = '  i   am  ';
        $this->assertTrue($grader->isCorrect($answer));

        $answer->text_answer = 'I am.';
        $this->assertFalse($grader->isCorrect($answer));

        $configuration = $answer->answer_config_snapshot;
        $configuration['normalization']['punctuation_sensitive'] = false;
        $answer->answer_config_snapshot = $configuration;
        $this->assertTrue($grader->isCorrect($answer));

        $answer->text_answer = 'cafe';
        $configuration = $answer->answer_config_snapshot;
        $configuration['accepted_answers'] = ['caf'."\u{00E9}"];
        $configuration['normalization']['punctuation_sensitive'] = true;
        $answer->answer_config_snapshot = $configuration;
        $this->assertFalse($grader->isCorrect($answer));

        $configuration = $answer->answer_config_snapshot;
        $configuration['normalization']['diacritic_sensitive'] = false;
        $answer->answer_config_snapshot = $configuration;
        $this->assertTrue($grader->isCorrect($answer));
    }

    public function test_accepted_answers_must_be_unique_after_configured_normalization(): void
    {
        $grader = new ExerciseAnswerGrader;

        $errors = $grader->configurationErrors([
            'accepted_answers' => ['I am', 'i   am'],
            'normalization' => $grader->defaultNormalization(),
        ]);

        $this->assertArrayHasKey('answer_config.accepted_answers.1', $errors);
    }
}
