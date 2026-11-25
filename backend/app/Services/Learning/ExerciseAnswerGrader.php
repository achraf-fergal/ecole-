<?php

namespace App\Services\Learning;

use App\Models\ExerciseAttemptAnswer;
use App\Models\Question;
use Normalizer;

/**
 * Central exact-match grading and text normalization for exercise answers.
 *
 * NFC normalization uses the mandatory PHP intl extension so results match
 * JavaScript's Unicode normalization in authoring previews.
 */
class ExerciseAnswerGrader
{
    public const MAX_ACCEPTED_ANSWERS = 20;

    public const MAX_ACCEPTED_ANSWER_LENGTH = 500;

    public const MAX_STUDENT_ANSWER_LENGTH = 5000;

    public const MAX_BLANKS = 20;

    public function defaultNormalization(): array
    {
        return [
            'case_sensitive' => false,
            'trim_whitespace' => true,
            'collapse_internal_whitespace' => true,
            'punctuation_sensitive' => true,
            'diacritic_sensitive' => true,
        ];
    }

    /** Persist explicit defaults so grading never depends on missing JSON keys. */
    public function normalizeConfiguration(array $configuration): array
    {
        $acceptedAnswers = $configuration['accepted_answers'] ?? [];
        $acceptedAnswers = is_array($acceptedAnswers)
            ? array_map(static fn ($answer): string => is_string($answer) ? $answer : '', array_values($acceptedAnswers))
            : [];
        $normalization = $configuration['normalization'] ?? [];

        return [
            'accepted_answers' => $acceptedAnswers,
            'normalization' => array_merge(
                $this->defaultNormalization(),
                is_array($normalization) ? $normalization : []
            ),
        ];
    }

    /**
     * Return configuration errors without requiring a complete draft.
     *
     * @return array<string, list<string>>
     */
    public function configurationErrors(
        mixed $configuration,
        bool $requireComplete = true
    ): array {
        $errors = [];

        if (! is_array($configuration)) {
            return ['answer_config' => ['Answer configuration must be an object.']];
        }

        $unknownKeys = array_diff(
            array_keys($configuration),
            ['accepted_answers', 'normalization']
        );
        if ($unknownKeys !== []) {
            $errors['answer_config'][] = 'Answer configuration contains unsupported fields.';
        }

        $acceptedAnswers = $configuration['accepted_answers'] ?? [];
        if (! is_array($acceptedAnswers) || ! array_is_list($acceptedAnswers)) {
            $errors['answer_config.accepted_answers'][] = 'Accepted answers must be a list.';
            $acceptedAnswers = [];
        }

        if (count($acceptedAnswers) > self::MAX_ACCEPTED_ANSWERS) {
            $errors['answer_config.accepted_answers'][] = 'A maximum of 20 accepted answers is allowed.';
        }

        if ($requireComplete && $acceptedAnswers === []) {
            $errors['answer_config.accepted_answers'][] = 'At least one accepted answer is required.';
        }

        $normalization = $configuration['normalization'] ?? [];
        if (! is_array($normalization)) {
            $errors['answer_config.normalization'][] = 'Normalization settings must be an object.';
            $normalization = [];
        }

        $defaults = $this->defaultNormalization();
        $unknownNormalizationKeys = array_diff(
            array_keys($normalization),
            array_keys($defaults)
        );
        if ($unknownNormalizationKeys !== []) {
            $errors['answer_config.normalization'][] = 'Normalization settings contain unsupported fields.';
        }

        foreach ($defaults as $key => $default) {
            if (array_key_exists($key, $normalization) && ! is_bool($normalization[$key])) {
                $errors["answer_config.normalization.{$key}"][] = 'This setting must be true or false.';
            }
        }

        $effectiveNormalization = array_merge($defaults, $normalization);
        if (
            ($effectiveNormalization['diacritic_sensitive'] ?? true) === false
            && ! class_exists(Normalizer::class)
        ) {
            $errors['answer_config.normalization.diacritic_sensitive'][] =
                'Diacritic-insensitive matching requires the PHP intl extension.';
        }

        $normalizedAccepted = [];
        foreach ($acceptedAnswers as $index => $acceptedAnswer) {
            if ($acceptedAnswer === null && ! $requireComplete) {
                continue;
            }

            if (! is_string($acceptedAnswer)) {
                $errors["answer_config.accepted_answers.{$index}"][] = 'Accepted answers must be text.';

                continue;
            }

            if (mb_strlen($acceptedAnswer, 'UTF-8') > self::MAX_ACCEPTED_ANSWER_LENGTH) {
                $errors["answer_config.accepted_answers.{$index}"][] = 'Accepted answers may not exceed 500 characters.';

                continue;
            }

            if ($this->trimWhitespace($acceptedAnswer) === '') {
                $errors["answer_config.accepted_answers.{$index}"][] = 'Accepted answers cannot be empty.';

                continue;
            }

            try {
                $normalized = $this->normalizeText($acceptedAnswer, $effectiveNormalization);
            } catch (\InvalidArgumentException $exception) {
                $errors["answer_config.accepted_answers.{$index}"][] = $exception->getMessage();

                continue;
            }

            if (in_array($normalized, $normalizedAccepted, true)) {
                $errors["answer_config.accepted_answers.{$index}"][] =
                    'Accepted answers must be unique after applying the configured matching rules.';
            }

            $normalizedAccepted[] = $normalized;
        }

        return $errors;
    }

    /** Persist explicit defaults for fill-in-the-blanks configuration. */
    public function normalizeBlankConfiguration(array $configuration): array
    {
        $blanks = $configuration['blanks'] ?? [];
        $normalizedBlanks = [];

        if (is_array($blanks)) {
            foreach ($blanks as $index => $blank) {
                if (! is_array($blank)) {
                    continue;
                }
                $accepted = $blank['accepted_answers'] ?? [];
                $accepted = is_array($accepted)
                    ? array_map(static fn ($a): string => is_string($a) ? $a : '', array_values($accepted))
                    : [];
                $normalization = $blank['normalization'] ?? [];

                $pointsMinorUnits = isset($blank['points']) && is_numeric($blank['points'])
                    ? $this->decimalToMinorUnits((string) $blank['points'])
                    : 100;

                $normalizedBlanks[] = [
                    'id' => isset($blank['id']) && is_string($blank['id']) ? trim($blank['id']) : 'blank_'.($index + 1),
                    'points' => (float) $this->minorUnitsToDecimalString($pointsMinorUnits),
                    'accepted_answers' => $accepted,
                    'normalization' => array_merge(
                        $this->defaultNormalization(),
                        is_array($normalization) ? $normalization : []
                    ),
                ];
            }
        }

        return ['blanks' => $normalizedBlanks];
    }

    /**
     * Return configuration errors for fill-in-the-blanks questions.
     *
     * @return array<string, list<string>>
     */
    public function blankConfigurationErrors(
        mixed $configuration,
        bool $requireComplete = true
    ): array {
        $errors = [];

        if (! is_array($configuration)) {
            return ['answer_config' => ['Answer configuration must be an object.']];
        }

        $unknownKeys = array_diff(
            array_keys($configuration),
            ['blanks']
        );
        if ($unknownKeys !== []) {
            $errors['answer_config'][] = 'Answer configuration contains unsupported fields.';
        }

        $blanks = $configuration['blanks'] ?? [];
        if (! is_array($blanks) || ! array_is_list($blanks)) {
            $errors['answer_config.blanks'][] = 'Blanks must be a list.';

            return $errors;
        }

        if (count($blanks) > self::MAX_BLANKS) {
            $errors['answer_config.blanks'][] = 'A maximum of 20 blanks is allowed.';
        }

        if ($requireComplete && $blanks === []) {
            $errors['answer_config.blanks'][] = 'At least one blank is required.';
        }

        $seenIds = [];
        $defaults = $this->defaultNormalization();

        foreach ($blanks as $bIndex => $blank) {
            if (! is_array($blank)) {
                $errors["answer_config.blanks.{$bIndex}"][] = 'Blank configuration must be an object.';

                continue;
            }

            $id = $blank['id'] ?? null;
            if ($id === null || ! is_string($id) || trim($id) === '') {
                $errors["answer_config.blanks.{$bIndex}.id"][] = 'Blank ID is required.';
            } elseif (! preg_match('/^[a-zA-Z0-9_\-]+$/', $id)) {
                $errors["answer_config.blanks.{$bIndex}.id"][] = 'Blank ID must contain only alphanumeric characters, underscores, and hyphens.';
            } elseif (in_array($id, $seenIds, true)) {
                $errors["answer_config.blanks.{$bIndex}.id"][] = "Blank ID '{$id}' is duplicated.";
            } else {
                $seenIds[] = $id;
            }

            $points = $blank['points'] ?? null;
            if ($points === null || ! is_numeric($points) || (float) $points <= 0) {
                $errors["answer_config.blanks.{$bIndex}.points"][] = 'Blank points must be greater than zero.';
            }

            $accepted = $blank['accepted_answers'] ?? [];
            if (! is_array($accepted) || ! array_is_list($accepted)) {
                $errors["answer_config.blanks.{$bIndex}.accepted_answers"][] = 'Accepted answers must be a list.';
                $accepted = [];
            }

            if ($requireComplete && $accepted === []) {
                $errors["answer_config.blanks.{$bIndex}.accepted_answers"][] = 'At least one accepted answer is required.';
            } elseif (count($accepted) > self::MAX_ACCEPTED_ANSWERS) {
                $errors["answer_config.blanks.{$bIndex}.accepted_answers"][] = 'A maximum of 20 accepted answers is allowed per blank.';
            }

            $normalization = $blank['normalization'] ?? [];
            if (! is_array($normalization)) {
                $errors["answer_config.blanks.{$bIndex}.normalization"][] = 'Normalization settings must be an object.';
                $normalization = [];
            }

            $effectiveNormalization = array_merge($defaults, $normalization);
            $normalizedAccepted = [];

            foreach ($accepted as $aIndex => $acceptedAnswer) {
                if ($acceptedAnswer === null && ! $requireComplete) {
                    continue;
                }
                if (! is_string($acceptedAnswer)) {
                    $errors["answer_config.blanks.{$bIndex}.accepted_answers.{$aIndex}"][] = 'Accepted answers must be text.';

                    continue;
                }
                if (mb_strlen($acceptedAnswer, 'UTF-8') > self::MAX_ACCEPTED_ANSWER_LENGTH) {
                    $errors["answer_config.blanks.{$bIndex}.accepted_answers.{$aIndex}"][] = 'Accepted answers may not exceed 500 characters.';

                    continue;
                }
                if ($this->trimWhitespace($acceptedAnswer) === '') {
                    $errors["answer_config.blanks.{$bIndex}.accepted_answers.{$aIndex}"][] = 'Accepted answers cannot be empty.';

                    continue;
                }

                try {
                    $normalized = $this->normalizeText($acceptedAnswer, $effectiveNormalization);
                } catch (\InvalidArgumentException $exception) {
                    $errors["answer_config.blanks.{$bIndex}.accepted_answers.{$aIndex}"][] = $exception->getMessage();

                    continue;
                }

                if (in_array($normalized, $normalizedAccepted, true)) {
                    $errors["answer_config.blanks.{$bIndex}.accepted_answers.{$aIndex}"][] =
                        'Accepted answers must be unique after applying the configured matching rules.';
                }

                $normalizedAccepted[] = $normalized;
            }
        }

        return $errors;
    }

    /**
     * Normalize student and accepted text according to the persisted rules.
     *
     * @param  array<string, bool>  $normalization
     */
    public function normalizeText(string $value, array $normalization): string
    {
        $value = $this->normalizeNfc($value);

        if (($normalization['trim_whitespace'] ?? true) === true) {
            $value = $this->trimWhitespace($value);
        }

        if (($normalization['collapse_internal_whitespace'] ?? true) === true) {
            $value = preg_replace('/[\p{Z}\s]+/u', ' ', $value) ?? $value;
        }

        if (($normalization['case_sensitive'] ?? false) === false) {
            $value = mb_strtolower($value, 'UTF-8');
        }

        if (($normalization['punctuation_sensitive'] ?? true) === false) {
            $value = preg_replace('/\p{P}+/u', '', $value) ?? $value;
        }

        if (($normalization['diacritic_sensitive'] ?? true) === false) {
            if (! class_exists(Normalizer::class)) {
                throw new \InvalidArgumentException(
                    'Diacritic-insensitive matching requires the PHP intl extension.'
                );
            }

            $decomposed = Normalizer::normalize($value, Normalizer::FORM_D);
            if ($decomposed === false) {
                throw new \InvalidArgumentException('Answer text could not be normalized as valid Unicode.');
            }

            $value = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $decomposed;
        }

        return $this->normalizeNfc($value);
    }

    /** Normalize option labels for duplicate detection without folding case or accents. */
    public function normalizeOptionLabel(string $value): string
    {
        return $this->normalizeNfc(
            preg_replace('/[\p{Z}\s]+/u', ' ', $this->trimWhitespace($value)) ?? $value
        );
    }

    public function isCorrect(ExerciseAttemptAnswer $answer): bool
    {
        return match ($answer->question_type_snapshot) {
            Question::TYPE_SINGLE_CHOICE,
            Question::TYPE_TRUE_FALSE => $answer->selected_option_id !== null
                && (int) $answer->selected_option_id === (int) $answer->correct_option_id_snapshot,
            Question::TYPE_MULTIPLE_SELECT => $this->sameIdSet(
                $answer->selected_option_ids ?? [],
                $answer->correct_option_ids_snapshot ?? []
            ),
            Question::TYPE_SHORT_ANSWER => $this->shortAnswerMatches($answer),
            Question::TYPE_FILL_IN_THE_BLANKS => $this->gradeFillInTheBlanks($answer)['is_correct'],
            default => false,
        };
    }

    /**
     * Grade fill-in-the-blanks answer with individual blank evaluation and partial credit.
     *
     * @return array{is_correct: bool, points_awarded_minor_units: int, points_possible_minor_units: int, blanks_feedback: list<array>}
     */
    public function gradeFillInTheBlanks(ExerciseAttemptAnswer $answer): array
    {
        $config = $answer->answer_config_snapshot ?? [];
        $blanks = $config['blanks'] ?? [];
        if (! is_array($blanks) || $blanks === []) {
            return [
                'is_correct' => false,
                'points_awarded_minor_units' => 0,
                'points_possible_minor_units' => 0,
                'blanks_feedback' => [],
            ];
        }

        $studentBlankAnswers = [];
        if ($answer->text_answer !== null && $answer->text_answer !== '') {
            $decoded = json_decode($answer->text_answer, true);
            if (is_array($decoded)) {
                $studentBlankAnswers = $decoded;
            }
        }

        $totalPossibleMinorUnits = 0;
        $totalAwardedMinorUnits = 0;
        $blanksFeedback = [];
        $allCorrect = true;

        foreach ($blanks as $blank) {
            $blankId = (string) ($blank['id'] ?? '');
            $blankPossibleMinorUnits = isset($blank['points']) && is_numeric($blank['points'])
                ? $this->decimalToMinorUnits((string) $blank['points'])
                : 100;
            $totalPossibleMinorUnits += $blankPossibleMinorUnits;

            $normalization = array_merge(
                $this->defaultNormalization(),
                is_array($blank['normalization'] ?? null) ? $blank['normalization'] : []
            );

            $rawStudentAnswer = isset($studentBlankAnswers[$blankId]) && is_string($studentBlankAnswers[$blankId])
                ? $studentBlankAnswers[$blankId]
                : '';

            $normalizedStudent = $this->normalizeText($rawStudentAnswer, $normalization);

            $isMatch = false;
            foreach ($blank['accepted_answers'] ?? [] as $accepted) {
                if (is_string($accepted) && $normalizedStudent === $this->normalizeText($accepted, $normalization)) {
                    $isMatch = true;
                    break;
                }
            }

            if ($rawStudentAnswer === '' && empty($blank['accepted_answers'])) {
                $isMatch = false;
            }

            if ($isMatch) {
                $blankAwardedMinorUnits = $blankPossibleMinorUnits;
                $totalAwardedMinorUnits += $blankAwardedMinorUnits;
            } else {
                $blankAwardedMinorUnits = 0;
                $allCorrect = false;
            }

            $blanksFeedback[] = [
                'id' => $blankId,
                'is_correct' => $isMatch,
                'student_answer' => $rawStudentAnswer,
                'accepted_answers' => $blank['accepted_answers'] ?? [],
                'points_awarded' => (float) $this->minorUnitsToDecimalString($blankAwardedMinorUnits),
                'points_possible' => (float) $this->minorUnitsToDecimalString($blankPossibleMinorUnits),
            ];
        }

        return [
            'is_correct' => $allCorrect && $totalPossibleMinorUnits > 0,
            'points_awarded_minor_units' => $totalAwardedMinorUnits,
            'points_possible_minor_units' => $totalPossibleMinorUnits,
            'blanks_feedback' => $blanksFeedback,
        ];
    }

    private function shortAnswerMatches(ExerciseAttemptAnswer $answer): bool
    {
        if ($answer->text_answer === null) {
            return false;
        }

        $configuration = $answer->answer_config_snapshot ?? [];
        $normalization = array_merge(
            $this->defaultNormalization(),
            is_array($configuration['normalization'] ?? null)
                ? $configuration['normalization']
                : []
        );

        $studentAnswer = $this->normalizeText($answer->text_answer, $normalization);

        foreach ($configuration['accepted_answers'] ?? [] as $acceptedAnswer) {
            if (
                is_string($acceptedAnswer)
                && $studentAnswer === $this->normalizeText($acceptedAnswer, $normalization)
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, int|string> $left @param array<int, int|string> $right */
    private function sameIdSet(array $left, array $right): bool
    {
        $left = array_values(array_unique(array_map('intval', $left)));
        $right = array_values(array_unique(array_map('intval', $right)));
        sort($left, SORT_NUMERIC);
        sort($right, SORT_NUMERIC);

        return $left !== [] && $left === $right;
    }

    private function normalizeNfc(string $value): string
    {
        if (! class_exists(Normalizer::class)) {
            throw new \RuntimeException(
                'PHP intl is required for assessment Unicode normalization. Install or enable ext-intl.'
            );
        }

        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);

        return $normalized === false ? $value : $normalized;
    }

    /**
     * Sum all configured blank points in integer minor units (hundredths).
     */
    public function sumBlankPointsMinorUnits(mixed $configuration): int
    {
        if (! is_array($configuration) || ! is_array($configuration['blanks'] ?? null)) {
            return 0;
        }

        $total = 0;
        foreach ($configuration['blanks'] as $blank) {
            if (! is_array($blank)) {
                continue;
            }
            $points = $blank['points'] ?? null;
            if (is_numeric($points)) {
                $total += $this->decimalToMinorUnits((string) $points);
            }
        }

        return $total;
    }

    /**
     * Validate that prompt `[blank_id]` placeholders map 1-to-1 to configured blank IDs.
     *
     * @return array<string, list<string>>
     */
    public function promptPlaceholderErrors(string $prompt, mixed $configuration): array
    {
        $errors = [];

        $definedIds = [];
        if (is_array($configuration) && is_array($configuration['blanks'] ?? null)) {
            foreach ($configuration['blanks'] as $blank) {
                if (is_array($blank) && is_string($blank['id'] ?? null) && trim($blank['id']) !== '') {
                    $definedIds[] = trim($blank['id']);
                }
            }
        }

        preg_match_all('/\[([^\[\]]*)\]/', $prompt, $matches);
        $rawTokens = $matches[1] ?? [];

        $placeholderCounts = [];
        foreach ($rawTokens as $token) {
            if (! preg_match('/^[a-zA-Z0-9_\-]+$/', $token)) {
                $errors['prompt'][] = "Invalid placeholder syntax '[{$token}]'. Use alphanumeric characters, underscores, or hyphens.";

                continue;
            }
            $placeholderCounts[$token] = ($placeholderCounts[$token] ?? 0) + 1;
        }

        foreach ($placeholderCounts as $id => $count) {
            if ($count > 1) {
                $errors['prompt'][] = "Placeholder '[{$id}]' appears {$count} times in the prompt. Each placeholder may appear only once.";
            }
            if (! in_array($id, $definedIds, true)) {
                $errors['prompt'][] = "Placeholder '[{$id}]' has no matching blank definition.";
            }
        }

        foreach ($definedIds as $definedId) {
            if (! isset($placeholderCounts[$definedId])) {
                $errors['answer_config.blanks'][] = "Blank '{$definedId}' is defined but not referenced as '[{$definedId}]' in the prompt.";
            }
        }

        return $errors;
    }

    /** Convert a DECIMAL(..., 2) value to integer hundredths without float arithmetic. */
    public function decimalToMinorUnits(string|int|float $value): int
    {
        $normalized = trim((string) $value);
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $normalized, $matches)) {
            throw new \InvalidArgumentException("Invalid decimal score value: {$normalized}");
        }

        $whole = (int) $matches[2];
        $fractionDigits = substr(str_pad($matches[3] ?? '', 2, '0'), 0, 2);
        $fraction = (int) $fractionDigits;
        $minorUnits = ($whole * 100) + $fraction;

        return ($matches[1] ?? '') === '-' ? -$minorUnits : $minorUnits;
    }

    public function minorUnitsToDecimalString(int $minorUnits): string
    {
        $sign = $minorUnits < 0 ? '-' : '';
        $absolute = abs($minorUnits);
        $whole = intdiv($absolute, 100);
        $fraction = $absolute % 100;

        return sprintf('%s%d.%02d', $sign, $whole, $fraction);
    }

    private function trimWhitespace(string $value): string
    {
        return preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $value) ?? trim($value);
    }
}
