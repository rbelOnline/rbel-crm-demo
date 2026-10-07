<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Names of the four appointment colour labels (green, blue, yellow, red), one
 * app_settings row. Colours are fixed; their names are edited on the Calendar.
 */
class CalendarLabels
{
    private const KEY = 'calendar_labels';

    public const DEFAULTS = [
        'green' => 'Policy review',
        'blue' => 'Meeting',
        'yellow' => 'Follow-up',
        'red' => 'Urgent',
    ];

    /** @return array<string, string> colour => name, in Appointment::LABELS order */
    public function names(): array
    {
        $saved = json_decode((string) DB::table('app_settings')->where('key', self::KEY)->value('value'), true) ?: [];

        return array_combine(Appointment::LABELS, array_map(fn ($c) => $saved[$c] ?? self::DEFAULTS[$c], Appointment::LABELS));
    }

    /** @return list<array{key: string, name: string}> for the API */
    public function list(): array
    {
        $names = $this->names();

        return array_map(fn ($c) => ['key' => $c, 'name' => $names[$c]], Appointment::LABELS);
    }

    /** @param  array<string, string>  $names  colour => name */
    public function update(array $names, User $by): void
    {
        $old = $this->names();
        $new = array_combine(Appointment::LABELS, array_map(fn ($c) => trim($names[$c]), Appointment::LABELS));

        DB::table('app_settings')->updateOrInsert(
            ['key' => self::KEY],
            ['value' => json_encode($new), 'updated_by' => $by->id, 'updated_at' => now(), 'created_at' => DB::raw('COALESCE(created_at, NOW())')],
        );

        AuditLogger::record('updated', 'settings', null, ['calendar_labels' => $old], ['calendar_labels' => $new], 'Renamed calendar labels', $by->id);
    }
}
