<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Policy for admin access control.
 */
class AdminPolicy
{
    /**
     * Determine if the user can access admin features.
     */
    public function admin(User $user): bool
    {
        // Check if user has admin or super-admin role
        return $user->hasAnyRole(['admin', 'super-admin']);
    }

    /**
     * Determine if the user can manage projects.
     */
    public function manageProjects(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super-admin']);
    }

    /**
     * Determine if the user can manage tickets.
     */
    public function manageTickets(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super-admin']);
    }

    /**
     * Determine if the user can manage workflows.
     */
    public function manageWorkflows(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super-admin']);
    }

    /**
     * Determine if the user can view artifacts.
     */
    public function viewArtifacts(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super-admin', 'developer']);
    }

    /**
     * Determine if the user can download artifacts.
     */
    public function downloadArtifacts(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super-admin', 'developer']);
    }
}
