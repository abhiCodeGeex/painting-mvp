<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Substrate;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SubstratePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Substrate');
    }

    public function view(AuthUser $authUser, Substrate $substrate): bool
    {
        return $authUser->can('View:Substrate');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Substrate');
    }

    public function update(AuthUser $authUser, Substrate $substrate): bool
    {
        return $authUser->can('Update:Substrate');
    }

    public function delete(AuthUser $authUser, Substrate $substrate): bool
    {
        return $authUser->can('Delete:Substrate');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Substrate');
    }

    public function restore(AuthUser $authUser, Substrate $substrate): bool
    {
        return $authUser->can('Restore:Substrate');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Substrate');
    }
}
