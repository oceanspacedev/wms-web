<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\WarehouseDocument;
use Illuminate\Auth\Access\HandlesAuthorization;

class WarehouseDocumentPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:WarehouseDocument');
    }

    public function view(AuthUser $authUser, WarehouseDocument $warehouseDocument): bool
    {
        return $authUser->can('View:WarehouseDocument');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:WarehouseDocument');
    }

    public function update(AuthUser $authUser, WarehouseDocument $warehouseDocument): bool
    {
        return $authUser->can('Update:WarehouseDocument');
    }

    public function delete(AuthUser $authUser, WarehouseDocument $warehouseDocument): bool
    {
        return $authUser->can('Delete:WarehouseDocument');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:WarehouseDocument');
    }

    public function restore(AuthUser $authUser, WarehouseDocument $warehouseDocument): bool
    {
        return $authUser->can('Restore:WarehouseDocument');
    }

    public function forceDelete(AuthUser $authUser, WarehouseDocument $warehouseDocument): bool
    {
        return $authUser->can('ForceDelete:WarehouseDocument');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:WarehouseDocument');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:WarehouseDocument');
    }

    public function replicate(AuthUser $authUser, WarehouseDocument $warehouseDocument): bool
    {
        return $authUser->can('Replicate:WarehouseDocument');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:WarehouseDocument');
    }

}