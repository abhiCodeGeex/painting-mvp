<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Finishing;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class FinishingPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Finishing');
    }

    public function view(AuthUser $authUser, Finishing $finishing): bool
    {
        return $authUser->can('View:Finishing');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Finishing');
    }

    public function update(AuthUser $authUser, Finishing $finishing): bool
    {
        return $authUser->can('Update:Finishing');
    }

    public function delete(AuthUser $authUser, Finishing $finishing): bool
    {
        return $authUser->can('Delete:Finishing');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Finishing');
    }

    public function restore(AuthUser $authUser, Finishing $finishing): bool
    {
        return $authUser->can('Restore:Finishing');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Finishing');
    }
}
