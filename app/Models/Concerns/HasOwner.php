<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Data yang dicatat dari aplikasi mobile dimiliki akun pencatatnya.
 * Super admin melihat semua data, akun lain hanya data miliknya sendiri.
 */
trait HasOwner
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->hasRole('super_admin') || $this->user_id === $user->id;
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->hasRole('super_admin')) {
            return;
        }

        $query->where($query->qualifyColumn('user_id'), $user->id);
    }
}
