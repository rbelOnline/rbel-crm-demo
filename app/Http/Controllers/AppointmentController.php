<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppointmentRequest;
use App\Http\Requests\BulkDeleteRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Client;
use App\Services\BulkDelete;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    private const SORTABLE = ['appointment_date', 'title', 'status', 'created_at'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(Appointment::STATUSES)],
            'label' => ['nullable', Rule::in(Appointment::LABELS)],
            'date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'client_id' => ['nullable', 'integer'],
            'upcoming' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = Appointment::with('client')
            ->when($f['search'] ?? null, function ($q, $term) {
                $escaped = addcslashes($term, '%_\\');
                $q->where(fn ($w) => $w->where('title', 'like', "%{$escaped}%")
                    ->orWhereHas('client', fn ($c) => Client::applySearch($c, $term, 'clients')));
            })
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['label'] ?? null, fn ($q, $l) => $q->where('label', $l))
            ->when($f['date'] ?? null, fn ($q, $d) => $q->where('appointment_date', $d))
            ->when($f['date_from'] ?? null, fn ($q, $d) => $q->where('appointment_date', '>=', $d))
            ->when($f['date_to'] ?? null, fn ($q, $d) => $q->where('appointment_date', '<=', $d))
            ->when($f['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($request->boolean('upcoming'), fn ($q) => $q->where('appointment_date', '>=', today()->toDateString()));

        $sort = $f['sort'] ?? 'appointment_date';
        $direction = $f['direction'] ?? ($request->boolean('upcoming') ? 'asc' : 'desc');
        $query->orderBy($sort, $direction);
        if ($sort === 'appointment_date') {
            $query->orderBy('appointment_time', $direction);
        }

        return AppointmentResource::collection($query->paginate($f['per_page'] ?? 10)->withQueryString());
    }

    /** Calendar: every appointment from $from to $to (inclusive, at most ~6 weeks), in time order. */
    public function calendar(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);
        if (Carbon::parse($f['to'])->gt(Carbon::parse($f['from'])->addDays(45))) {
            throw ValidationException::withMessages(['to' => 'The calendar range can be at most 45 days.']);
        }

        return AppointmentResource::collection(
            Appointment::with('client')
                ->whereBetween('appointment_date', [$f['from'], $f['to']])
                ->orderBy('appointment_date')
                ->orderBy('appointment_time')
                ->get()
        );
    }

    public function store(AppointmentRequest $request): JsonResponse
    {
        $appointment = Appointment::create($request->validated() + ['user_id' => $request->user()->id]);

        return (new AppointmentResource($appointment->load('client')))->response()->setStatusCode(201);
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        return new AppointmentResource($appointment->load(['client', 'user']));
    }

    public function update(AppointmentRequest $request, Appointment $appointment): AppointmentResource
    {
        $appointment->update($request->validated());

        return new AppointmentResource($appointment->load('client'));
    }

    public function destroy(Appointment $appointment): JsonResponse
    {
        $appointment->delete();

        return response()->json(null, 204);
    }

    public function bulkDestroy(BulkDeleteRequest $request, BulkDelete $bulk): JsonResponse
    {
        return $bulk->run(Appointment::class, $request->ids(), fn () => null, fn (Appointment $a) => $a->delete(), fn (Appointment $a) => $a->title);
    }
}
