<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Secret extends Model
{
    use HasFactory;

    public const KIND_JIRA = 'jira';

    public const KIND_GITHUB = 'github';

    public const KIND_GITLAB = 'gitlab';

    public const KIND_BITBUCKET = 'bitbucket';

    public const KIND_LINEAR = 'linear';

    public const KIND_AZURE = 'azure';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'project_id',
        'kind',
        'key_id',
        'meta',
        'payload',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'kind' => 'string',
        'meta' => 'array',
        'payload' => 'encrypted',
    ];

    /**
     * Get the project that owns the secret.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
