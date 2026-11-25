<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StockItem;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Stock rows come and go with their catalogue item, so there is no create / delete:
 * staff view stock and update it (adjust quantity, change the alert level).
 */
class StockItemPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:StockItem');
    }

    public function view(AuthUser $authUser, StockItem $stockItem): bool
    {
        return $authUser->can('View:StockItem');
    }

    public function update(AuthUser $authUser, StockItem $stockItem): bool
    {
        return $authUser->can('Update:StockItem');
    }

    public function create(AuthUser $authUser): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser, StockItem $stockItem): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }
}
