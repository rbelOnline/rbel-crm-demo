<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReminderRequest;
use App\Http\Resources\ReminderResource;
use App\Models\Reminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class ReminderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'in:open,overdue,completed,due_today'],
            'type' => ['nullable', Rule::in(Reminder::TYPES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $today = today()->toDateString();

        $query = Reminder::with(['client', 'policy:id,policy_number'])
            ->when($f['search'] ?? null, fn ($q, $t) => $q->where('title', 'like', '%'.addcslashes($t, '%_\\').'%'))
            ->when($f['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($f['date_from'] ?? null, fn ($q, $d) => $q->where('due_date', '>=', $d))
            ->when($f['date_to'] ?? null, fn ($q, $d) => $q->where('due_date', '<=', $d));

        match ($f['state'] ?? 'open') {
            'open' => $query->whereNull('completed_at'),
            'overdue' => $query->whereNull('completed_at')->where('due_date', '<', $today),
            'due_today' => $query->whereNull('completed_at')->where('due_date', $today),
            'completed' => $query->whereNotNull('completed_at'),
        };

        ($f['state'] ?? 'open') === 'completed'
            ? $query->orderByDesc('completed_at')
            : $query->orderBy('due_date');

        return ReminderResource::collection($query->paginate($f['per_page'] ?? 10)->withQueryString());
    }

    public function store(ReminderRequest $request): JsonResponse
    {
        $reminder = Reminder::create($this->attributes($request) + ['user_id' => $request->user()->id]);

        return (new ReminderResource($reminder->load(['client', 'policy'])))->response()->setStatusCode(201);
    }

    public function update(ReminderRequest $request, Reminder $reminder): ReminderResource
    {
        $reminder->update($this->attributes($request));

        return new ReminderResource($reminder->load(['client', 'policy']));
    }

    /** Toggle completion. */
    public function complete(Request $request, Reminder $reminder): ReminderResource
    {
        $done = $request->validate(['completed' => ['required', 'boolean']])['completed'];
        $reminder->update(['completed_at' => $done ? now() : null]);

        return new ReminderResource($reminder->load(['client', 'policy']));
    }

    public function destroy(Reminder $reminder): JsonResponse
    {
        $reminder->delete();

        return response()->json(null, 204);
    }

    private function attributes(ReminderRequest $request): array
    {
        $data = Arr::except($request->validated(), 'completed');

        if ($request->has('completed')) {
            $data['completed_at'] = $request->boolean('completed') ? now() : null;
        }

        return $data;
    }
}
