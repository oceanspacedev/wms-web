<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\WarehouseMapping;
use Illuminate\Auth\Access\HandlesAuthorization;

class WarehouseMappingPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:WarehouseMapping');
    }

    public function view(AuthUser $authUser, WarehouseMapping $warehouseMapping): bool
    {
        return $authUser->can('View:WarehouseMapping');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:WarehouseMapping');
    }

    public function update(AuthUser $authUser, WarehouseMapping $warehouseMapping): bool
    {
        return $authUser->can('Update:WarehouseMapping');
    }

    public function delete(AuthUser $authUser, WarehouseMapping $warehouseMapping): bool
    {
        return $authUser->can('Delete:WarehouseMapping');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:WarehouseMapping');
    }

    public function restore(AuthUser $authUser, WarehouseMapping $warehouseMapping): bool
    {
        return $authUser->can('Restore:WarehouseMapping');
    }

    public function forceDelete(AuthUser $authUser, WarehouseMapping $warehouseMapping): bool
    {
        return $authUser->can('ForceDelete:WarehouseMapping');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:WarehouseMapping');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:WarehouseMapping');
    }

    public function replicate(AuthUser $authUser, WarehouseMapping $warehouseMapping): bool
    {
        return $authUser->can('Replicate:WarehouseMapping');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:WarehouseMapping');
    }

}