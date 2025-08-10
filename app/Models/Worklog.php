<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Worklog extends Model
{
    use HasFactory;

    public const PHASE_PLAN = 'plan';

    public const PHASE_IMPLEMENT = 'implement';

    public const PHASE_TEST = 'test';

    public const PHASE_REVIEW = 'review';

    public const PHASE_PR = 'pr';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ticket_id',
        'user_id',
        'phase',
        'seconds',
        'started_at',
        'ended_at',
        'notes',
        'status',
        'synced_at',
        'sync_status',
        'sync_error',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'phase' => 'string',
        'seconds' => 'integer',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    /**
     * Get the ticket that owns the worklog.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Get the user that owns the worklog.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
