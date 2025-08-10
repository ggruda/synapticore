<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Workflow extends Model
{
    use HasFactory;

    public const STATE_INGESTED = 'INGESTED';

    public const STATE_CONTEXT_READY = 'CONTEXT_READY';

    public const STATE_PLANNED = 'PLANNED';

    public const STATE_IMPLEMENTING = 'IMPLEMENTING';

    public const STATE_TESTING = 'TESTING';

    public const STATE_REVIEWING = 'REVIEWING';

    public const STATE_FIXING = 'FIXING';

    public const STATE_PR_CREATED = 'PR_CREATED';

    public const STATE_DONE = 'DONE';

    public const STATE_FAILED = 'FAILED';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ticket_id',
        'state',
        'retries',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'state' => 'string',
        'retries' => 'integer',
    ];

    /**
     * Get the ticket that owns the workflow.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
