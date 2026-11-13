<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CenterUser extends Model

{use HasFactory;
    protected $table = 'center_user';

    protected $fillable = [
        'center_id',
        'user_id',
        'status',
    ];

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'center_user_role',
            'center_user_id',
            'role_id'
        )->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
