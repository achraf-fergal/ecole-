<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(
            CenterUser::class,
            'center_user_role',
            'role_id',
            'center_user_id'
        )->withTimestamps();
    }
}
