<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'repo_urls',
        'default_branch',
        'allowed_paths',
        'language_profile',
        'provider_overrides',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'repo_urls' => 'array',
        'allowed_paths' => 'array',
        'language_profile' => 'array',
        'provider_overrides' => 'array',
    ];

    /**
     * Get the repos for the project.
     */
    public function repos(): HasMany
    {
        return $this->hasMany(Repo::class);
    }

    /**
     * Get the tickets for the project.
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Get the secrets for the project.
     */
    public function secrets(): HasMany
    {
        return $this->hasMany(Secret::class);
    }

    /**
     * Get the invoices for the project.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
