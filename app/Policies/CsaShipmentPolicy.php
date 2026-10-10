<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\CsaShipment;
use Illuminate\Auth\Access\HandlesAuthorization;

class CsaShipmentPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CsaShipment');
    }

    public function view(AuthUser $authUser, CsaShipment $csaShipment): bool
    {
        return $authUser->can('View:CsaShipment');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CsaShipment');
    }

    public function update(AuthUser $authUser, CsaShipment $csaShipment): bool
    {
        return $authUser->can('Update:CsaShipment');
    }

    public function delete(AuthUser $authUser, CsaShipment $csaShipment): bool
    {
        return $authUser->can('Delete:CsaShipment');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:CsaShipment');
    }

    public function restore(AuthUser $authUser, CsaShipment $csaShipment): bool
    {
        return $authUser->can('Restore:CsaShipment');
    }

    public function forceDelete(AuthUser $authUser, CsaShipment $csaShipment): bool
    {
        return $authUser->can('ForceDelete:CsaShipment');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CsaShipment');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CsaShipment');
    }

    public function replicate(AuthUser $authUser, CsaShipment $csaShipment): bool
    {
        return $authUser->can('Replicate:CsaShipment');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CsaShipment');
    }

}