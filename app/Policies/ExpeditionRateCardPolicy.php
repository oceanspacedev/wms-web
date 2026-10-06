<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ExpeditionRateCard;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ExpeditionRateCardPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ExpeditionRateCard');
    }

    public function view(AuthUser $authUser, ExpeditionRateCard $expeditionRateCard): bool
    {
        return $authUser->can('View:ExpeditionRateCard');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ExpeditionRateCard');
    }

    public function update(AuthUser $authUser, ExpeditionRateCard $expeditionRateCard): bool
    {
        return $authUser->can('Update:ExpeditionRateCard');
    }

    public function delete(AuthUser $authUser, ExpeditionRateCard $expeditionRateCard): bool
    {
        return $authUser->can('Delete:ExpeditionRateCard');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ExpeditionRateCard');
    }

    public function restore(AuthUser $authUser, ExpeditionRateCard $expeditionRateCard): bool
    {
        return $authUser->can('Restore:ExpeditionRateCard');
    }

    public function forceDelete(AuthUser $authUser, ExpeditionRateCard $expeditionRateCard): bool
    {
        return $authUser->can('ForceDelete:ExpeditionRateCard');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ExpeditionRateCard');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ExpeditionRateCard');
    }

    public function replicate(AuthUser $authUser, ExpeditionRateCard $expeditionRateCard): bool
    {
        return $authUser->can('Replicate:ExpeditionRateCard');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ExpeditionRateCard');
    }
}
