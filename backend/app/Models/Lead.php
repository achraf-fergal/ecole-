<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    use HasFactory;

    /*
    |--------------------------------------------------------------------------
    | Lead Sources
    |--------------------------------------------------------------------------
    */

    public const SOURCE_WEBSITE = 'website';
    public const SOURCE_EXISTING_SITE_LINK = 'existing_site_link';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_SOCIAL_CAMPAIGN = 'social_campaign';

    public const SOURCES = [
        self::SOURCE_WEBSITE,
        self::SOURCE_EXISTING_SITE_LINK,
        self::SOURCE_MANUAL,
        self::SOURCE_SOCIAL_CAMPAIGN,
    ];

    /*
    |--------------------------------------------------------------------------
    | CRM Pipeline Statuses
    |--------------------------------------------------------------------------
    */

    public const STATUS_NEW = 'new';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_INTERESTED = 'interested';
    public const STATUS_FOLLOW_UP = 'follow_up';
    public const STATUS_OFFER_SENT = 'offer_sent';
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_LOST = 'lost';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_CONTACTED,
        self::STATUS_INTERESTED,
        self::STATUS_FOLLOW_UP,
        self::STATUS_OFFER_SENT,
        self::STATUS_AWAITING_PAYMENT,
        self::STATUS_CONVERTED,
        self::STATUS_LOST,
        self::STATUS_INVALID,
        self::STATUS_DUPLICATE,
    ];

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    */

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

    /*
    |--------------------------------------------------------------------------
    | Attribute Casting
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'next_follow_up_at' => 'datetime',
            'privacy_notice_accepted_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Center owning this lead.
     */
    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    /**
     * Requested course.
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Staff member assigned to this lead.
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'assigned_to_user_id'
        );
    }

    /**
     * Student profile created after conversion.
     */
    public function convertedStudent(): BelongsTo
    {
        return $this->belongsTo(
            StudentProfile::class,
            'converted_student_profile_id'
        );
    }

    /**
     * User responsible for converting the lead.
     */
    public function convertedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'converted_by_user_id'
        );
    }

    /**
     * Original lead when this record is a duplicate.
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(
            self::class,
            'duplicate_of_lead_id'
        );
    }

    /**
     * Leads marked as duplicates of this record.
     */
    public function duplicates(): HasMany
    {
        return $this->hasMany(
            self::class,
            'duplicate_of_lead_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Filter leads belonging to one center.
     */
    public function scopeForCenter(
        Builder $query,
        int $centerId
    ): Builder {
        return $query->where('center_id', $centerId);
    }

    /**
     * Filter by CRM status.
     */
    public function scopeWithStatus(
        Builder $query,
        string $status
    ): Builder {
        return $query->where('status', $status);
    }

    /**
     * Filter leads assigned to a staff member.
     */
    public function scopeAssignedToUser(
        Builder $query,
        int $userId
    ): Builder {
        return $query->where(
            'assigned_to_user_id',
            $userId
        );
    }

    /*
    |--------------------------------------------------------------------------
    | State Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Check whether conversion was completed.
     */
    public function isConverted(): bool
    {
        return $this->status === self::STATUS_CONVERTED
            && $this->converted_student_profile_id !== null
            && $this->converted_at !== null;
    }

    /**
     * Check whether this lead is in a closed state.
     */
    public function isClosed(): bool
    {
        return in_array($this->status, [
            self::STATUS_CONVERTED,
            self::STATUS_LOST,
            self::STATUS_INVALID,
            self::STATUS_DUPLICATE,
        ], true);
    }

    /**
     * Check whether this record is a duplicate.
     */
    public function isDuplicate(): bool
    {
        return $this->status === self::STATUS_DUPLICATE;
    }
}
