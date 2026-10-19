<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StockAllocation;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class StockAllocationPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:StockAllocation');
    }

    public function view(AuthUser $authUser, StockAllocation $stockAllocation): bool
    {
        return $authUser->can('View:StockAllocation');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:StockAllocation');
    }

    public function update(AuthUser $authUser, StockAllocation $stockAllocation): bool
    {
        return $authUser->can('Update:StockAllocation');
    }

    public function delete(AuthUser $authUser, StockAllocation $stockAllocation): bool
    {
        return $authUser->can('Delete:StockAllocation');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:StockAllocation');
    }

    public function restore(AuthUser $authUser, StockAllocation $stockAllocation): bool
    {
        return $authUser->can('Restore:StockAllocation');
    }

    public function forceDelete(AuthUser $authUser, StockAllocation $stockAllocation): bool
    {
        return $authUser->can('ForceDelete:StockAllocation');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:StockAllocation');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:StockAllocation');
    }

    public function replicate(AuthUser $authUser, StockAllocation $stockAllocation): bool
    {
        return $authUser->can('Replicate:StockAllocation');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:StockAllocation');
    }
}
