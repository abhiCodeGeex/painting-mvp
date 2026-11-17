<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ComplexityLevel;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ComplexityLevelPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ComplexityLevel');
    }

    public function view(AuthUser $authUser, ComplexityLevel $complexityLevel): bool
    {
        return $authUser->can('View:ComplexityLevel');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ComplexityLevel');
    }

    public function update(AuthUser $authUser, ComplexityLevel $complexityLevel): bool
    {
        return $authUser->can('Update:ComplexityLevel');
    }

    public function delete(AuthUser $authUser, ComplexityLevel $complexityLevel): bool
    {
        return $authUser->can('Delete:ComplexityLevel');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ComplexityLevel');
    }

    public function restore(AuthUser $authUser, ComplexityLevel $complexityLevel): bool
    {
        return $authUser->can('Restore:ComplexityLevel');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ComplexityLevel');
    }
}
