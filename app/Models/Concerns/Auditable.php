<?php

namespace App\Models\Concerns;

use App\Support\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Records created/updated/deleted events for the model in audit_logs,
 * storing only the attributes that actually changed as JSON.
 */
trait Auditable
{
    /** Columns never written to the audit trail. */
    protected static array $auditExcluded = [
        'created_at', 'updated_at', 'password', 'remember_token',
        // Generated columns are derived from other audited columns.
        'issued_month',
    ];

    public static function bootAuditable(): void
    {
        static::created(function (self $model) {
            AuditLogger::record('created', $model->auditModule(), $model->getKey(), null, $model->auditValues($model->getAttributes()));
        });

        static::updated(function (self $model) {
            $changes = $model->auditValues($model->getChanges());

            if ($changes === []) {
                return;
            }

            $old = $model->auditValues(Arr::only($model->getRawOriginal(), array_keys($changes)));
            AuditLogger::record('updated', $model->auditModule(), $model->getKey(), $old, $changes);
        });

        static::deleted(function (self $model) {
            AuditLogger::record('deleted', $model->auditModule(), $model->getKey(), $model->auditValues($model->getAttributes()), null);
        });
    }

    public function auditModule(): string
    {
        return property_exists($this, 'auditModule')
            ? $this->auditModule
            : Str::snake(Str::pluralStudly(class_basename($this)));
    }

    protected function auditValues(array $attributes): array
    {
        return $this->auditRedact(Arr::except($attributes, array_merge(static::$auditExcluded, $this->getHidden())));
    }

    /** Shorten bulky values before they are written to the audit trail (override per model). */
    protected function auditRedact(array $values): array
    {
        return $values;
    }
}
