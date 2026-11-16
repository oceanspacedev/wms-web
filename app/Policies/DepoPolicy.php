<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Depo;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class DepoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Depo');
    }

    public function view(AuthUser $authUser, Depo $depo): bool
    {
        return $authUser->can('View:Depo');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Depo');
    }

    public function update(AuthUser $authUser, Depo $depo): bool
    {
        return $authUser->can('Update:Depo');
    }

    public function delete(AuthUser $authUser, Depo $depo): bool
    {
        return $authUser->can('Delete:Depo');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Depo');
    }

    public function restore(AuthUser $authUser, Depo $depo): bool
    {
        return $authUser->can('Restore:Depo');
    }

    public function forceDelete(AuthUser $authUser, Depo $depo): bool
    {
        return $authUser->can('ForceDelete:Depo');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Depo');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Depo');
    }

    public function replicate(AuthUser $authUser, Depo $depo): bool
    {
        return $authUser->can('Replicate:Depo');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Depo');
    }
}
