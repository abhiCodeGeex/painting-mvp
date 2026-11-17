<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Medium;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class MediumPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Medium');
    }

    public function view(AuthUser $authUser, Medium $medium): bool
    {
        return $authUser->can('View:Medium');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Medium');
    }

    public function update(AuthUser $authUser, Medium $medium): bool
    {
        return $authUser->can('Update:Medium');
    }

    public function delete(AuthUser $authUser, Medium $medium): bool
    {
        return $authUser->can('Delete:Medium');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Medium');
    }

    public function restore(AuthUser $authUser, Medium $medium): bool
    {
        return $authUser->can('Restore:Medium');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Medium');
    }
}
