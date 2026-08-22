<?php

namespace App\Services;

use App\Models\User;

class BranchAccessService
{
    public static function canAccessBranch(User $user, int $branchId, ?string $permission = null): bool
    {
        if ($user->role === 'super_admin') {
            return true;
        }

        $branchUser = $user->branches()->where('branch_id', $branchId)->first();
        if (!$branchUser) {
            return false;
        }

        if (!$permission) {
            return true;
        }

        $permissions = json_decode($branchUser->pivot->permissions ?? '[]', true) ?? [];
        return in_array('*', $permissions) || in_array($permission, $permissions);
    }
}
