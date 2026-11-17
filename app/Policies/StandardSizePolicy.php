<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StandardSize;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class StandardSizePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:StandardSize');
    }

    public function view(AuthUser $authUser, StandardSize $standardSize): bool
    {
        return $authUser->can('View:StandardSize');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:StandardSize');
    }

    public function update(AuthUser $authUser, StandardSize $standardSize): bool
    {
        return $authUser->can('Update:StandardSize');
    }

    public function delete(AuthUser $authUser, StandardSize $standardSize): bool
    {
        return $authUser->can('Delete:StandardSize');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:StandardSize');
    }

    public function restore(AuthUser $authUser, StandardSize $standardSize): bool
    {
        return $authUser->can('Restore:StandardSize');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:StandardSize');
    }
}
