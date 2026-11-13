<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Level extends Model
{use HasFactory;
    protected $fillable = [
        'center_id',
        'level_template_id',
        'code',
        'name',
        'description',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(
            LevelTemplate::class,
            'level_template_id'
        );
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function isCustom(): bool
    {
        return $this->level_template_id === null;
    }
}
