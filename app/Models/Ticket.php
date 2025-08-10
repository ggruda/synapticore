<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ticket extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'project_id',
        'external_key',
        'source',
        'title',
        'body',
        'acceptance_criteria',
        'labels',
        'status',
        'priority',
        'meta',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'source' => 'string',
        'acceptance_criteria' => 'array',
        'labels' => 'array',
        'meta' => 'array',
    ];

    /**
     * Get the project that owns the ticket.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the workflow for the ticket.
     */
    public function workflow(): HasOne
    {
        return $this->hasOne(Workflow::class);
    }

    /**
     * Get the plan for the ticket.
     */
    public function plan(): HasOne
    {
        return $this->hasOne(Plan::class);
    }

    /**
     * Get the patches for the ticket.
     */
    public function patches(): HasMany
    {
        return $this->hasMany(Patch::class);
    }

    /**
     * Get the runs for the ticket.
     */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /**
     * Get the pull request for the ticket.
     */
    public function pullRequest(): HasOne
    {
        return $this->hasOne(PullRequest::class);
    }

    /**
     * Get the worklogs for the ticket.
     */
    public function worklogs(): HasMany
    {
        return $this->hasMany(Worklog::class);
    }
}
