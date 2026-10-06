<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CsaImport;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CsaImportPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CsaImport');
    }

    public function view(AuthUser $authUser, CsaImport $csaImport): bool
    {
        return $authUser->can('View:CsaImport');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CsaImport');
    }

    public function update(AuthUser $authUser, CsaImport $csaImport): bool
    {
        return $authUser->can('Update:CsaImport');
    }

    public function delete(AuthUser $authUser, CsaImport $csaImport): bool
    {
        return $authUser->can('Delete:CsaImport');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:CsaImport');
    }

    public function restore(AuthUser $authUser, CsaImport $csaImport): bool
    {
        return $authUser->can('Restore:CsaImport');
    }

    public function forceDelete(AuthUser $authUser, CsaImport $csaImport): bool
    {
        return $authUser->can('ForceDelete:CsaImport');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CsaImport');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CsaImport');
    }

    public function replicate(AuthUser $authUser, CsaImport $csaImport): bool
    {
        return $authUser->can('Replicate:CsaImport');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CsaImport');
    }
}
