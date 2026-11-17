<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Frame;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class FramePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Frame');
    }

    public function view(AuthUser $authUser, Frame $frame): bool
    {
        return $authUser->can('View:Frame');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Frame');
    }

    public function update(AuthUser $authUser, Frame $frame): bool
    {
        return $authUser->can('Update:Frame');
    }

    public function delete(AuthUser $authUser, Frame $frame): bool
    {
        return $authUser->can('Delete:Frame');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Frame');
    }

    public function restore(AuthUser $authUser, Frame $frame): bool
    {
        return $authUser->can('Restore:Frame');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Frame');
    }
}
