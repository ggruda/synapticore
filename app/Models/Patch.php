<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Patch extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ticket_id',
        'files_touched',
        'diff_stats',
        'risk_score',
        'summary',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'files_touched' => 'array',
        'diff_stats' => 'array',
        'risk_score' => 'integer',
        'summary' => 'array',
    ];

    /**
     * Get the ticket that owns the patch.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Convert to PatchSummaryJson DTO.
     */
    public function toPatchSummaryJson(): \App\DTO\PatchSummaryJson
    {
        return new \App\DTO\PatchSummaryJson(
            filesTouched: $this->files_touched ?? [],
            diffStats: $this->diff_stats ?? ['additions' => 0, 'deletions' => 0],
            riskScore: $this->risk_score,
            summary: is_array($this->summary) ? ($this->summary['summary'] ?? '') : $this->summary,
            breakingChanges: $this->summary['breaking_changes'] ?? false,
            requiresMigration: $this->summary['requires_migration'] ?? false,
            testCoverage: $this->summary['test_coverage'] ?? null,
            metadata: [
                'patch_id' => $this->id,
                'created_at' => $this->created_at->toIso8601String(),
            ],
        );
    }
}
