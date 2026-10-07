<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes rows to audit_logs. Model changes arrive through the Auditable
 * trait; non-CRUD actions (document upload, login, email send) call record()
 * directly.
 */
class AuditLogger
{
    private static bool $enabled = true;

    public static function record(
        string $action,
        string $module,
        ?int $recordId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
        ?int $userId = null,
    ): ?AuditLog {
        if (! self::$enabled) {
            return null;
        }

        return AuditLog::create([
            'user_id' => $userId ?? Auth::id(),
            'action' => $action,
            'module' => $module,
            'record_id' => $recordId,
            'description' => $description,
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'ip_address' => app()->runningInConsole() ? null : Request::ip(),
            'user_agent' => app()->runningInConsole() ? null : substr((string) Request::userAgent(), 0, 255),
        ]);
    }

    /**
     * Run a callback with auditing switched off (bulk seeding, imports).
     */
    public static function withoutAuditing(callable $callback): mixed
    {
        $previous = self::$enabled;
        self::$enabled = false;

        try {
            return $callback();
        } finally {
            self::$enabled = $previous;
        }
    }
}
