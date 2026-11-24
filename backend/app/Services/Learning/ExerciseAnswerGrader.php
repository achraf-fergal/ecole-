<?php

namespace App\Services\Learning;

use App\Models\ExerciseAttemptAnswer;
use App\Models\Question;
use Normalizer;

/**
 * Central exact-match grading and text normalization for exercise answers.
 *
 * NFC normalization is used when PHP's optional intl extension is available.
 * Without intl, Unicode code-point sequences are preserved; whitespace and
 * case normalization still use PCRE UTF-8 and mbstring. Diacritic-insensitive
 * matching requires intl and is rejected when it is unavailable.
 */
class ExerciseAnswerGrader
{
    public const MAX_ACCEPTED_ANSWERS = 20;

    public const MAX_ACCEPTED_ANSWER_LENGTH = 500;

    public const MAX_STUDENT_ANSWER_LENGTH = 5000;

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
            default => false,
        };
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
            return $value;
        }

        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);

        return $normalized === false ? $value : $normalized;
    }

    private function trimWhitespace(string $value): string
    {
        return preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $value) ?? trim($value);
    }
}
