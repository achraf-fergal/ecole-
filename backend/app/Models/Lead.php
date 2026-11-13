<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lead extends Model
{
    use HasFactory;

    public const SOURCE_WEBSITE = 'website';

    public const SOURCE_MANUAL = 'manual';

    public const STATUS_NEW = 'new';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_LOST = 'lost';

    protected $fillable = [
        'center_id',
        'course_id',
        'assigned_to_user_id',
        'converted_student_profile_id',
        'converted_by_user_id',
        'duplicate_of_lead_id',
        'name',
        'phone',
        'email',
        'preferred_contact_method',
        'message',
        'source',
        'source_detail',
        'status',
        'lost_reason',
        'next_follow_up_at',
        'privacy_notice_accepted_at',
        'converted_at',
    ];

    protected function casts(): array
    {
        return [
            'next_follow_up_at' => 'datetime',
            'privacy_notice_accepted_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
