<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Trip;
use App\Models\User;

class AuditLogger
{
    /**
     * Record an auditable action.
     *
     * @param Trip|null $trip The trip associated with the action.
     * @param User|null $user The user who performed the action.
     * @param string $action The action identifier.
     * @param string|null $type The subject model type.
     * @param int|null $id The subject model ID.
     * @param array $metadata Additional audit information.
     */
    public function record(
        ?Trip $trip,
        ?User $user,
        string $action,
        ?string $type = null,
        ?int $id = null,
        array $metadata = []
    ): void {
        AuditLog::create([
            'trip_id' => $trip?->id,
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $type,
            'subject_id' => $id,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
