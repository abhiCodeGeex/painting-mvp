<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Artwork;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ArtworkPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Artwork');
    }

    public function view(AuthUser $authUser, Artwork $artwork): bool
    {
        return $authUser->can('View:Artwork');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Artwork');
    }

    public function update(AuthUser $authUser, Artwork $artwork): bool
    {
        return $authUser->can('Update:Artwork');
    }

    public function delete(AuthUser $authUser, Artwork $artwork): bool
    {
        return $authUser->can('Delete:Artwork');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Artwork');
    }

    public function restore(AuthUser $authUser, Artwork $artwork): bool
    {
        return $authUser->can('Restore:Artwork');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Artwork');
    }
}
