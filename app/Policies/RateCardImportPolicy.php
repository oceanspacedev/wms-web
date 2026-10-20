<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RateCardImport;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class RateCardImportPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:RateCardImport');
    }

    public function view(AuthUser $authUser, RateCardImport $rateCardImport): bool
    {
        return $authUser->can('View:RateCardImport');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:RateCardImport');
    }

    public function update(AuthUser $authUser, RateCardImport $rateCardImport): bool
    {
        return $authUser->can('Update:RateCardImport');
    }

    public function delete(AuthUser $authUser, RateCardImport $rateCardImport): bool
    {
        return $authUser->can('Delete:RateCardImport');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:RateCardImport');
    }

    public function restore(AuthUser $authUser, RateCardImport $rateCardImport): bool
    {
        return $authUser->can('Restore:RateCardImport');
    }

    public function forceDelete(AuthUser $authUser, RateCardImport $rateCardImport): bool
    {
        return $authUser->can('ForceDelete:RateCardImport');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:RateCardImport');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:RateCardImport');
    }

    public function replicate(AuthUser $authUser, RateCardImport $rateCardImport): bool
    {
        return $authUser->can('Replicate:RateCardImport');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:RateCardImport');
    }
}
