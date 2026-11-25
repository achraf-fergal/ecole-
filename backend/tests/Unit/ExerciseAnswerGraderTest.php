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

    public function test_php_normalization_matches_shared_cross_runtime_fixtures(): void
    {
        $this->assertTrue(class_exists(\Normalizer::class), 'PHP intl is mandatory for assessment normalization.');

        $fixturePath = dirname(__DIR__).'/fixtures/exercise-normalization.json';
        $fixtures = json_decode(
            file_get_contents($fixturePath),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $grader = new ExerciseAnswerGrader;

        foreach ($fixtures as $fixture) {
            $actual = ($fixture['type'] ?? 'answer') === 'option'
                ? $grader->normalizeOptionLabel($fixture['input'])
                : $grader->normalizeText($fixture['input'], $fixture['settings'] ?? []);

            $this->assertSame($fixture['expected'], $actual, $fixture['name']);
        }
    }

    public function test_fill_in_the_blanks_grades_all_correct_all_incorrect_and_partial_credit(): void
    {
        $grader = new ExerciseAnswerGrader;

        $answer = new ExerciseAttemptAnswer([
            'question_type_snapshot' => Question::TYPE_FILL_IN_THE_BLANKS,
            'answer_config_snapshot' => [
                'blanks' => [
                    [
                        'id' => 'blank_1',
                        'points' => 0.50,
                        'accepted_answers' => ['am', 'AM'],
                        'normalization' => $grader->defaultNormalization(),
                    ],
                    [
                        'id' => 'blank_2',
                        'points' => 1.00,
                        'accepted_answers' => ['learning'],
                        'normalization' => $grader->defaultNormalization(),
                    ],
                    [
                        'id' => 'blank_3',
                        'points' => 1.50,
                        'accepted_answers' => ['café'],
                        'normalization' => $grader->defaultNormalization(),
                    ],
                ],
            ],
            'text_answer' => json_encode([
                'blank_1' => '  AM ',
                'blank_2' => 'studying',
                'blank_3' => 'CAFÉ',
            ], JSON_THROW_ON_ERROR),
        ]);

        // 1st and 3rd correct (0.50 + 1.50 = 2.00 out of 3.00)
        $result = $grader->gradeFillInTheBlanks($answer);
        $this->assertFalse($result['is_correct']);
        $this->assertSame(200, $result['points_awarded_minor_units']);
        $this->assertSame(300, $result['points_possible_minor_units']);
        $this->assertCount(3, $result['blanks_feedback']);
        $this->assertTrue($result['blanks_feedback'][0]['is_correct']);
        $this->assertFalse($result['blanks_feedback'][1]['is_correct']);
        $this->assertTrue($result['blanks_feedback'][2]['is_correct']);

        // All correct
        $answer->text_answer = json_encode([
            'blank_1' => 'am',
            'blank_2' => '  Learning  ',
            'blank_3' => 'café',
        ], JSON_THROW_ON_ERROR);
        $allCorrect = $grader->gradeFillInTheBlanks($answer);
        $this->assertTrue($allCorrect['is_correct']);
        $this->assertSame(300, $allCorrect['points_awarded_minor_units']);

        // All incorrect / missing
        $answer->text_answer = null;
        $allIncorrect = $grader->gradeFillInTheBlanks($answer);
        $this->assertFalse($allIncorrect['is_correct']);
        $this->assertSame(0, $allIncorrect['points_awarded_minor_units']);
    }

    public function test_fill_in_the_blanks_respects_punctuation_and_diacritic_settings(): void
    {
        $grader = new ExerciseAnswerGrader;

        $answer = new ExerciseAttemptAnswer([
            'question_type_snapshot' => Question::TYPE_FILL_IN_THE_BLANKS,
            'answer_config_snapshot' => [
                'blanks' => [
                    [
                        'id' => 'blank_1',
                        'points' => 1.00,
                        'accepted_answers' => ['café!'],
                        'normalization' => array_merge($grader->defaultNormalization(), [
                            'punctuation_sensitive' => true,
                            'diacritic_sensitive' => true,
                        ]),
                    ],
                ],
            ],
            'text_answer' => json_encode(['blank_1' => 'cafe'], JSON_THROW_ON_ERROR),
        ]);

        $this->assertFalse($grader->gradeFillInTheBlanks($answer)['is_correct']);

        $snapshot = $answer->answer_config_snapshot;
        $snapshot['blanks'][0]['normalization']['punctuation_sensitive'] = false;
        $snapshot['blanks'][0]['normalization']['diacritic_sensitive'] = false;
        $answer->answer_config_snapshot = $snapshot;

        $this->assertTrue($grader->gradeFillInTheBlanks($answer)['is_correct']);
    }

    public function test_fill_in_the_blanks_empty_config_and_placeholder_validation(): void
    {
        $grader = new ExerciseAnswerGrader;

        $emptyResult = $grader->gradeFillInTheBlanks(new ExerciseAttemptAnswer([
            'question_type_snapshot' => Question::TYPE_FILL_IN_THE_BLANKS,
            'answer_config_snapshot' => [],
            'text_answer' => null,
        ]));
        $this->assertFalse($emptyResult['is_correct']);
        $this->assertSame(0, $emptyResult['points_possible_minor_units']);

        $configErrors = $grader->blankConfigurationErrors([
            'blanks' => [
                ['id' => 'blank_1', 'points' => 1, 'accepted_answers' => ['a']],
                ['id' => 'blank_1', 'points' => 0, 'accepted_answers' => []],
            ],
        ]);
        $this->assertArrayHasKey('answer_config.blanks.1.id', $configErrors);
        $this->assertArrayHasKey('answer_config.blanks.1.points', $configErrors);
        $this->assertArrayHasKey('answer_config.blanks.1.accepted_answers', $configErrors);

        // Undefined placeholder, unused blank, duplicate placeholder, invalid syntax
        $placeholderErrors = $grader->promptPlaceholderErrors(
            'I [blank_3] and [blank_1] and [blank_1] with [bad space].',
            ['blanks' => [['id' => 'blank_1'], ['id' => 'blank_2']]]
        );
        $this->assertArrayHasKey('prompt', $placeholderErrors);
        $this->assertArrayHasKey('answer_config.blanks', $placeholderErrors);
    }
}
