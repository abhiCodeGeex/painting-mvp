<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\WorkCharge;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class WorkChargePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:WorkCharge');
    }

    public function view(AuthUser $authUser, WorkCharge $workCharge): bool
    {
        return $authUser->can('View:WorkCharge');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:WorkCharge');
    }

    public function update(AuthUser $authUser, WorkCharge $workCharge): bool
    {
        return $authUser->can('Update:WorkCharge');
    }

    public function delete(AuthUser $authUser, WorkCharge $workCharge): bool
    {
        return $authUser->can('Delete:WorkCharge');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:WorkCharge');
    }

    public function restore(AuthUser $authUser, WorkCharge $workCharge): bool
    {
        return $authUser->can('Restore:WorkCharge');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:WorkCharge');
    }
}
