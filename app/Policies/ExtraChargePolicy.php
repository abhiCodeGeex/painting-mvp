<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ExtraCharge;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ExtraChargePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ExtraCharge');
    }

    public function view(AuthUser $authUser, ExtraCharge $extraCharge): bool
    {
        return $authUser->can('View:ExtraCharge');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ExtraCharge');
    }

    public function update(AuthUser $authUser, ExtraCharge $extraCharge): bool
    {
        return $authUser->can('Update:ExtraCharge');
    }

    public function delete(AuthUser $authUser, ExtraCharge $extraCharge): bool
    {
        return $authUser->can('Delete:ExtraCharge');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ExtraCharge');
    }

    public function restore(AuthUser $authUser, ExtraCharge $extraCharge): bool
    {
        return $authUser->can('Restore:ExtraCharge');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ExtraCharge');
    }
}
