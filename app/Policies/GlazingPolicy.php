<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Glazing;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class GlazingPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Glazing');
    }

    public function view(AuthUser $authUser, Glazing $glazing): bool
    {
        return $authUser->can('View:Glazing');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Glazing');
    }

    public function update(AuthUser $authUser, Glazing $glazing): bool
    {
        return $authUser->can('Update:Glazing');
    }

    public function delete(AuthUser $authUser, Glazing $glazing): bool
    {
        return $authUser->can('Delete:Glazing');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Glazing');
    }

    public function restore(AuthUser $authUser, Glazing $glazing): bool
    {
        return $authUser->can('Restore:Glazing');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Glazing');
    }
}
