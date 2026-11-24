<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    use HasFactory;

    public const TYPE_SINGLE_CHOICE = 'single_choice';

    public const TYPE_TRUE_FALSE = 'true_false';

    public const TYPE_MULTIPLE_SELECT = 'multiple_select';

    public const TYPE_SHORT_ANSWER = 'short_answer';

    public const TYPES = [
        self::TYPE_SINGLE_CHOICE,
        self::TYPE_TRUE_FALSE,
        self::TYPE_MULTIPLE_SELECT,
        self::TYPE_SHORT_ANSWER,
    ];

    protected $fillable = [
        'center_id',
        'exercise_id',
        'question_type',
        'prompt',
        'explanation',
        'answer_config',
        'points',
        'sort_order',
    ];

    /**
     * Do not expose explanations in default student-facing serialization.
     */
    protected $hidden = [
        'explanation',
        'answer_config',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'decimal:2',
            'sort_order' => 'integer',
            'answer_config' => 'array',
        ];
    }

    /**
     * Center that owns this question.
     */
    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    /**
     * Exercise containing this question.
     */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /**
     * Options belonging to this question.
     */
    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Scope records to one tenant.
     */
    public function scopeForCenter(
        Builder $query,
        int $centerId
    ): Builder {
        return $query->where('center_id', $centerId);
    }

    /**
     * Validate the question's option configuration.
     *
     * This method must be called before publishing
     * the parent exercise.
     */
    public function optionConfigurationErrors(): array
    {
        $options = $this->options()
            ->get(['id', 'is_correct', 'option_text']);

        $optionCount = $options->count();

        $correctCount = $options
            ->filter(
                fn (QuestionOption $option): bool => $option->is_correct
            )
            ->count();

        $hasEmptyOption = $options->contains(
            fn (QuestionOption $option): bool => preg_match(
                '/^[\p{Z}\s]*$/u',
                (string) $option->option_text
            ) === 1
        );

        $errors = [];

        if ($hasEmptyOption && $this->question_type !== self::TYPE_SHORT_ANSWER) {
            $errors[] = 'Question options cannot be empty.';
        }

        if ($this->question_type === self::TYPE_SINGLE_CHOICE) {
            if ($optionCount < 2) {
                $errors[] = 'A single-choice question requires at least two options.';
            }

            if ($correctCount !== 1) {
                $errors[] = 'A single-choice question must have exactly one correct option.';
            }

            return $errors;
        }

        if ($this->question_type === self::TYPE_TRUE_FALSE) {
            if ($optionCount !== 2) {
                $errors[] = 'A true/false question requires exactly two options.';
            }

            if ($correctCount !== 1) {
                $errors[] = 'A true/false question must have exactly one correct option.';
            }

            return $errors;
        }

        if ($this->question_type === self::TYPE_MULTIPLE_SELECT) {
            if ($optionCount < 3) {
                $errors[] = 'A multiple-select question requires at least three options.';
            }

            if ($correctCount < 2) {
                $errors[] = 'A multiple-select question requires at least two correct options.';
            }

            if ($correctCount >= $optionCount) {
                $errors[] = 'A multiple-select question requires at least one incorrect option.';
            }

            return $errors;
        }

        if ($this->question_type === self::TYPE_SHORT_ANSWER) {
            return [];
        }

        return [
            'Unsupported question type.',
        ];
    }

    /**
     * Check whether the question can be published.
     */
    public function hasValidOptionConfiguration(): bool
    {
        return $this->optionConfigurationErrors() === [];
    }
}
