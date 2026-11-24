<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExerciseAttemptAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'center_id',
        'exercise_id',
        'exercise_attempt_id',
        'question_id',
        'selected_option_id',
        'selected_option_ids',
        'text_answer',
        'correct_option_id_snapshot',
        'correct_option_ids_snapshot',
        'answer_config_snapshot',
        'question_type_snapshot',
        'prompt_snapshot',
        'explanation_snapshot',
        'options_snapshot',
        'points_possible',
        'is_correct',
        'points_awarded',
    ];

    /**
     * Internal answer keys and grading data must never be exposed by default.
     */
    protected $hidden = [
        'correct_option_id_snapshot',
        'correct_option_ids_snapshot',
        'answer_config_snapshot',
        'explanation_snapshot',
        'is_correct',
        'points_awarded',
    ];

    protected function casts(): array
    {
        return [
            'options_snapshot' => 'array',
            'selected_option_ids' => 'array',
            'correct_option_ids_snapshot' => 'array',
            'answer_config_snapshot' => 'array',
            'points_possible' => 'decimal:2',
            'is_correct' => 'boolean',
            'points_awarded' => 'decimal:2',
        ];
    }

    /**
     * Center that owns this attempt answer.
     */
    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    /**
     * Parent attempt.
     */
    public function exerciseAttempt(): BelongsTo
    {
        return $this->belongsTo(
            ExerciseAttempt::class,
            'exercise_attempt_id'
        );
    }

    /**
     * Exercise associated with this answer.
     */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /**
     * Question associated with this answer.
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * Selected option (if answered).
     */
    public function selectedOption(): BelongsTo
    {
        return $this->belongsTo(
            QuestionOption::class,
            'selected_option_id'
        );
    }

    /**
     * Filter by tenant.
     */
    public function scopeForCenter(
        Builder $query,
        int $centerId
    ): Builder {
        return $query->where('center_id', $centerId);
    }
}
