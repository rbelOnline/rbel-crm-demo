<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScheduleItemRequest;
use App\Http\Resources\ScheduleItemResource;
use App\Models\ScheduleItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** The signed-in user's personal schedule on the Calendar. Other users' items are never visible. */
class ScheduleItemController extends Controller
{
    /** Every occurrence between from and to (repeating items expanded), by day and time. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);
        $from = Carbon::parse($f['from']);
        $to = Carbon::parse($f['to']);
        if ($to->gt($from->copy()->addDays(45))) {
            throw ValidationException::withMessages(['to' => 'The calendar range can be at most 45 days.']);
        }

        // Single items in the range, and series that have started by its end and not ended before it.
        $items = ScheduleItem::where('user_id', $request->user()->id)
            ->where(fn ($q) => $q
                ->where(fn ($single) => $single->where('repeat', 'none')->whereBetween('date', [$from->toDateString(), $to->toDateString()]))
                ->orWhere(fn ($series) => $series->where('repeat', '!=', 'none')->where('date', '<=', $to->toDateString())
                    ->where(fn ($u) => $u->whereNull('repeat_until')->orWhere('repeat_until', '>=', $from->toDateString()))))
            ->get();

        $occurrences = $items
            ->flatMap(fn (ScheduleItem $item) => array_map(fn ($day) => [$item, $day], $item->occurrences($from, $to)))
            ->sortBy(fn ($o) => $o[1].' '.$o[0]->start_time)
            ->values()
            ->map(fn ($o) => (new ScheduleItemResource($o[0], $o[1]))->resolve($request));

        return response()->json(['data' => $occurrences]);
    }

    public function store(ScheduleItemRequest $request): JsonResponse
    {
        $item = new ScheduleItem($this->attributes($request));
        $item->user_id = $request->user()->id;
        $item->save();

        return (new ScheduleItemResource($item))->response()->setStatusCode(201);
    }

    /** Edits the item — for a repeating one, the whole series. */
    public function update(ScheduleItemRequest $request, ScheduleItem $scheduleItem): ScheduleItemResource
    {
        $this->ensureOwn($request, $scheduleItem);
        $scheduleItem->update($this->attributes($request));

        return new ScheduleItemResource($scheduleItem);
    }

    /** Deletes the item — for a repeating one, every occurrence. */
    public function destroy(Request $request, ScheduleItem $scheduleItem): JsonResponse
    {
        $this->ensureOwn($request, $scheduleItem);
        $scheduleItem->delete();

        return response()->json(null, 204);
    }

    /** Removes one day from a repeating series; the rest of the series stays. */
    public function skip(Request $request, ScheduleItem $scheduleItem): ScheduleItemResource
    {
        $this->ensureOwn($request, $scheduleItem);
        $date = $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'];

        $day = Carbon::parse($date);
        if (! $scheduleItem->repeats() || $scheduleItem->occurrences($day, $day) === []) {
            throw ValidationException::withMessages(['date' => 'This item does not fall on that day.']);
        }

        $scheduleItem->skip_dates = array_values(array_unique([...($scheduleItem->skip_dates ?? []), $date]));
        $scheduleItem->save();

        return new ScheduleItemResource($scheduleItem);
    }

    /** A one-off item has no last day. */
    private function attributes(ScheduleItemRequest $request): array
    {
        $data = $request->validated();
        $data['repeat'] ??= 'none';
        if ($data['repeat'] === 'none') {
            $data['repeat_until'] = null;
        }

        return $data;
    }

    /** Someone else's item is treated as not found, so its existence is not revealed. */
    private function ensureOwn(Request $request, ScheduleItem $item): void
    {
        abort_unless($item->user_id === $request->user()->id, 404);
    }
}
