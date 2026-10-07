<?php

namespace App\Http\Resources;

use App\Models\ScheduleItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One schedule item; for the calendar, one occurrence of it. `date` is the day shown,
 * `starts_on` the first day of the series (equal to `date` for a single item).
 *
 * @mixin ScheduleItem
 */
class ScheduleItemResource extends JsonResource
{
    public function __construct($resource, private ?string $occurrence = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'date' => $this->occurrence ?? $this->date?->toDateString(),
            'starts_on' => $this->date?->toDateString(),
            'start_time' => substr((string) $this->start_time, 0, 5),
            'end_time' => $this->end_time ? substr((string) $this->end_time, 0, 5) : null,
            'repeat' => $this->repeat ?? 'none',
            'repeat_until' => $this->repeat_until?->toDateString(),
            'label' => $this->label,
            'notes' => $this->notes,
        ];
    }
}
