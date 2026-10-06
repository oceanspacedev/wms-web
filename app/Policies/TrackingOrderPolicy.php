<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TrackingOrder;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class TrackingOrderPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:TrackingOrder');
    }

    public function view(AuthUser $authUser, TrackingOrder $trackingOrder): bool
    {
        return $authUser->can('View:TrackingOrder');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:TrackingOrder');
    }

    public function update(AuthUser $authUser, TrackingOrder $trackingOrder): bool
    {
        return $authUser->can('Update:TrackingOrder');
    }

    public function delete(AuthUser $authUser, TrackingOrder $trackingOrder): bool
    {
        return $authUser->can('Delete:TrackingOrder');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:TrackingOrder');
    }

    public function restore(AuthUser $authUser, TrackingOrder $trackingOrder): bool
    {
        return $authUser->can('Restore:TrackingOrder');
    }

    public function forceDelete(AuthUser $authUser, TrackingOrder $trackingOrder): bool
    {
        return $authUser->can('ForceDelete:TrackingOrder');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:TrackingOrder');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:TrackingOrder');
    }

    public function replicate(AuthUser $authUser, TrackingOrder $trackingOrder): bool
    {
        return $authUser->can('Replicate:TrackingOrder');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:TrackingOrder');
    }
}
