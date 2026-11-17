<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EnquirySource;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class EnquirySourcePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:EnquirySource');
    }

    public function view(AuthUser $authUser, EnquirySource $enquirySource): bool
    {
        return $authUser->can('View:EnquirySource');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:EnquirySource');
    }

    public function update(AuthUser $authUser, EnquirySource $enquirySource): bool
    {
        return $authUser->can('Update:EnquirySource');
    }

    public function delete(AuthUser $authUser, EnquirySource $enquirySource): bool
    {
        return $authUser->can('Delete:EnquirySource');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:EnquirySource');
    }

    public function restore(AuthUser $authUser, EnquirySource $enquirySource): bool
    {
        return $authUser->can('Restore:EnquirySource');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:EnquirySource');
    }
}
