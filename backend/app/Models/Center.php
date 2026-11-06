<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Center extends Model
{
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

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
