<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\QualityTier;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class QualityTierPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:QualityTier');
    }

    public function view(AuthUser $authUser, QualityTier $qualityTier): bool
    {
        return $authUser->can('View:QualityTier');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:QualityTier');
    }

    public function update(AuthUser $authUser, QualityTier $qualityTier): bool
    {
        return $authUser->can('Update:QualityTier');
    }

    public function delete(AuthUser $authUser, QualityTier $qualityTier): bool
    {
        return $authUser->can('Delete:QualityTier');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:QualityTier');
    }

    public function restore(AuthUser $authUser, QualityTier $qualityTier): bool
    {
        return $authUser->can('Restore:QualityTier');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:QualityTier');
    }
}
