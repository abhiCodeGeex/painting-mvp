<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RejectionReason;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class RejectionReasonPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:RejectionReason');
    }

    public function view(AuthUser $authUser, RejectionReason $rejectionReason): bool
    {
        return $authUser->can('View:RejectionReason');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:RejectionReason');
    }

    public function update(AuthUser $authUser, RejectionReason $rejectionReason): bool
    {
        return $authUser->can('Update:RejectionReason');
    }

    public function delete(AuthUser $authUser, RejectionReason $rejectionReason): bool
    {
        return $authUser->can('Delete:RejectionReason');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:RejectionReason');
    }

    public function restore(AuthUser $authUser, RejectionReason $rejectionReason): bool
    {
        return $authUser->can('Restore:RejectionReason');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:RejectionReason');
    }
}
