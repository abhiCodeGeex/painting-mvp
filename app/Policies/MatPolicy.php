<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Mat;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class MatPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Mat');
    }

    public function view(AuthUser $authUser, Mat $mat): bool
    {
        return $authUser->can('View:Mat');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Mat');
    }

    public function update(AuthUser $authUser, Mat $mat): bool
    {
        return $authUser->can('Update:Mat');
    }

    public function delete(AuthUser $authUser, Mat $mat): bool
    {
        return $authUser->can('Delete:Mat');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Mat');
    }

    public function restore(AuthUser $authUser, Mat $mat): bool
    {
        return $authUser->can('Restore:Mat');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Mat');
    }
}
