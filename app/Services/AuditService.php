<?php

namespace App\Services;

use App\Models\AuditLog;

class AuditService
{
    public static function log(
        string $actionType,
        string $entityType,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $operatorUserId = null,
        ?int $branchId = null
    ): AuditLog {
        $operatorUserId = $operatorUserId ?? auth()->id();
        $ip = request()->ip();
        $userAgent = request()->userAgent();

        return AuditLog::create([
            'operator_user_id' => $operatorUserId,
            'branch_id' => $branchId,
            'action_type' => $actionType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
    }
}
