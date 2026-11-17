<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\State;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class StatePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:State');
    }

    public function view(AuthUser $authUser, State $state): bool
    {
        return $authUser->can('View:State');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:State');
    }

    public function update(AuthUser $authUser, State $state): bool
    {
        return $authUser->can('Update:State');
    }

    public function delete(AuthUser $authUser, State $state): bool
    {
        return $authUser->can('Delete:State');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:State');
    }

    public function restore(AuthUser $authUser, State $state): bool
    {
        return $authUser->can('Restore:State');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:State');
    }
}
