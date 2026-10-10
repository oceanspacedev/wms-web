<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\FreightInvoice;
use Illuminate\Auth\Access\HandlesAuthorization;

class FreightInvoicePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:FreightInvoice');
    }

    public function view(AuthUser $authUser, FreightInvoice $freightInvoice): bool
    {
        return $authUser->can('View:FreightInvoice');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:FreightInvoice');
    }

    public function update(AuthUser $authUser, FreightInvoice $freightInvoice): bool
    {
        return $authUser->can('Update:FreightInvoice');
    }

    public function delete(AuthUser $authUser, FreightInvoice $freightInvoice): bool
    {
        return $authUser->can('Delete:FreightInvoice');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:FreightInvoice');
    }

    public function restore(AuthUser $authUser, FreightInvoice $freightInvoice): bool
    {
        return $authUser->can('Restore:FreightInvoice');
    }

    public function forceDelete(AuthUser $authUser, FreightInvoice $freightInvoice): bool
    {
        return $authUser->can('ForceDelete:FreightInvoice');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:FreightInvoice');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:FreightInvoice');
    }

    public function replicate(AuthUser $authUser, FreightInvoice $freightInvoice): bool
    {
        return $authUser->can('Replicate:FreightInvoice');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:FreightInvoice');
    }

}