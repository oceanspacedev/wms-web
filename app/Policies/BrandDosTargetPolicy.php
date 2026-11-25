<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BrandDosTarget;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class BrandDosTargetPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:BrandDosTarget');
    }

    public function view(AuthUser $authUser, BrandDosTarget $brandDosTarget): bool
    {
        return $authUser->can('View:BrandDosTarget');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:BrandDosTarget');
    }

    public function update(AuthUser $authUser, BrandDosTarget $brandDosTarget): bool
    {
        return $authUser->can('Update:BrandDosTarget');
    }

    public function delete(AuthUser $authUser, BrandDosTarget $brandDosTarget): bool
    {
        return $authUser->can('Delete:BrandDosTarget');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:BrandDosTarget');
    }

    public function restore(AuthUser $authUser, BrandDosTarget $brandDosTarget): bool
    {
        return $authUser->can('Restore:BrandDosTarget');
    }

    public function forceDelete(AuthUser $authUser, BrandDosTarget $brandDosTarget): bool
    {
        return $authUser->can('ForceDelete:BrandDosTarget');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:BrandDosTarget');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:BrandDosTarget');
    }

    public function replicate(AuthUser $authUser, BrandDosTarget $brandDosTarget): bool
    {
        return $authUser->can('Replicate:BrandDosTarget');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:BrandDosTarget');
    }
}
