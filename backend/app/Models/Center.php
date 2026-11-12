<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Center extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'email',
        'phone',
        'logo_url',
        'status',
        'timezone',
        'default_locale',
    ];

    public function memberships(): HasMany
    {
        return $this->hasMany(CenterUser::class);
    }

    /**
     * Student profiles belonging to this center.
     */
    public function studentProfiles(): HasMany
    {
        return $this->hasMany(StudentProfile::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'center_user',
            'center_id',
            'user_id'
        )
            ->withPivot(['id', 'status'])
            ->withTimestamps();
    }

    /**
     * Educational modules belonging to this center.
     */
    public function courseModules(): HasMany
    {
        return $this->hasMany(CourseModule::class);
    }

    public function languages(): HasMany
    {
        return $this->hasMany(Language::class);
    }

    public function levels(): HasMany
    {
        return $this->hasMany(Level::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    /**
     * All educational assets belonging to this center.
     */
    public function lessonAssets(): HasMany
    {
        return $this->hasMany(LessonAsset::class);
    }

    /**
     * Questions belonging to this center.
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    /**
     * Question options belonging to this center.
     */
    public function questionOptions(): HasMany
    {
        return $this->hasMany(QuestionOption::class);
    }

    /**
     * Exercises owned by this center.
     */
    public function exercises(): HasMany
    {
        return $this->hasMany(Exercise::class);
    }
    /**
     * Course cohorts owned by this center.
     */
    public function cohorts(): HasMany
    {
        return $this->hasMany(Cohort::class)
            ->orderBy('starts_at')
            ->orderBy('id');
    }
    /**
 * Weekly schedules belonging to this center.
 */
public function cohortSchedules(): HasMany
{
    return $this->hasMany(CohortSchedule::class);
}
    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
