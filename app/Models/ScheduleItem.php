<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A personal schedule item on the Calendar (owned by one user, visible only to them).
 * It may repeat daily, weekly, monthly or yearly from `date` until `repeat_until`
 * (open-ended when null); single days can be removed from a series (`skip_dates`).
 */
#[Fillable(['title', 'date', 'start_time', 'end_time', 'repeat', 'repeat_until', 'label', 'notes'])]
class ScheduleItem extends Model
{
    use Auditable, HasFactory;

    public const REPEATS = ['none', 'daily', 'weekly', 'monthly', 'yearly'];

    protected string $auditModule = 'schedule';

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'repeat_until' => 'date:Y-m-d',
            'skip_dates' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function repeats(): bool
    {
        return $this->repeat !== null && $this->repeat !== 'none';
    }

    /**
     * Days (Y-m-d) this item falls on between $from and $to, inclusive. A monthly item on
     * the 29th–31st skips months without that day; a yearly one on Feb 29 only falls in leap years.
     *
     * @return list<string>
     */
    public function occurrences(CarbonInterface $from, CarbonInterface $to): array
    {
        $start = CarbonImmutable::parse($this->date)->startOfDay();
        $from = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();
        if ($this->repeat_until && CarbonImmutable::parse($this->repeat_until)->lt($end)) {
            $end = CarbonImmutable::parse($this->repeat_until)->startOfDay();
        }
        if ($end->lt($from) || $start->gt($end)) {
            return [];
        }

        $first = $start->max($from);
        $days = [];

        switch ($this->repeat ?: 'none') {
            case 'none':
                $days = $start->betweenIncluded($from, $end) ? [$start] : [];
                break;

            case 'daily':
                for ($d = $first; $d->lte($end); $d = $d->addDay()) {
                    $days[] = $d;
                }
                break;

            case 'weekly':
                // First same-weekday on or after $first.
                $d = $first->addDays((7 - ((int) $start->diffInDays($first) % 7)) % 7);
                for (; $d->lte($end); $d = $d->addWeek()) {
                    $days[] = $d;
                }
                break;

            case 'monthly':
                for ($m = $first->startOfMonth(); $m->lte($end); $m = $m->addMonthNoOverflow()) {
                    if ($start->day <= $m->daysInMonth) {
                        $days[] = $m->setDay($start->day);
                    }
                }
                break;

            case 'yearly':
                for ($y = $first->year; $y <= $end->year; $y++) {
                    if ($start->month === 2 && $start->day === 29 && ! CarbonImmutable::create($y)->isLeapYear()) {
                        continue;
                    }
                    $days[] = CarbonImmutable::create($y, $start->month, $start->day);
                }
                break;
        }

        $skip = $this->skip_dates ?? [];

        return array_values(array_filter(
            array_map(fn (CarbonImmutable $d) => $d->toDateString(), $days),
            fn (string $d) => $d >= $start->toDateString() && $d >= $from->toDateString() && $d <= $end->toDateString() && ! in_array($d, $skip, true),
        ));
    }
}
