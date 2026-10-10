<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Expedition;
use Illuminate\Auth\Access\HandlesAuthorization;

class ExpeditionPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Expedition');
    }

    public function view(AuthUser $authUser, Expedition $expedition): bool
    {
        return $authUser->can('View:Expedition');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Expedition');
    }

    public function update(AuthUser $authUser, Expedition $expedition): bool
    {
        return $authUser->can('Update:Expedition');
    }

    public function delete(AuthUser $authUser, Expedition $expedition): bool
    {
        return $authUser->can('Delete:Expedition');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Expedition');
    }

    public function restore(AuthUser $authUser, Expedition $expedition): bool
    {
        return $authUser->can('Restore:Expedition');
    }

    public function forceDelete(AuthUser $authUser, Expedition $expedition): bool
    {
        return $authUser->can('ForceDelete:Expedition');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Expedition');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Expedition');
    }

    public function replicate(AuthUser $authUser, Expedition $expedition): bool
    {
        return $authUser->can('Replicate:Expedition');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Expedition');
    }

}