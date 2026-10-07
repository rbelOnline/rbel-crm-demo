<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkDeleteRequest;
use App\Http\Requests\ConvertLeadRequest;
use App\Http\Requests\LeadIndexRequest;
use App\Http\Requests\LeadRequest;
use App\Http\Resources\ClientResource;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use App\Services\BulkDelete;
use App\Services\ExcelExport;
use App\Services\LeadConverter;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Leads: prospects with no policy yet, kept apart from clients until converted. */
class LeadController extends Controller
{
    public const SORTABLE = [
        'name' => ['last_name', 'first_name'],
        'email' => ['email'],
        'birthdate' => ['birthdate'],
        'created_at' => ['created_at'],
    ];

    public function index(LeadIndexRequest $request): AnonymousResourceCollection
    {
        $f = $request->validated();

        return LeadResource::collection(
            $this->filtered($f)->paginate(min((int) ($f['per_page'] ?? 10), 100))->withQueryString()
        );
    }

    /** Excel download of the list with the same filters and sort as index(). */
    public function export(LeadIndexRequest $request, ExcelExport $excel): StreamedResponse
    {
        $f = Arr::except($request->validated(), ['page', 'per_page']);

        AuditLogger::record('exported', 'leads', newValues: ['filters' => $f], description: 'Exported leads to Excel');

        $columns = [
            ['header' => 'Last name', 'width' => 18],
            ['header' => 'First name', 'width' => 18],
            ['header' => 'Middle name', 'width' => 16],
            ['header' => 'Email', 'width' => 30],
            ['header' => 'Mobile', 'width' => 18],
            ['header' => 'Birthdate', 'type' => 'date', 'width' => 12],
            ['header' => 'Age', 'type' => 'int', 'width' => 6],
            ['header' => 'Gender', 'width' => 10],
            ['header' => 'Occupation', 'width' => 20],
            ['header' => 'Notes', 'width' => 40],
            ['header' => 'Added', 'type' => 'date', 'width' => 12],
        ];

        $rows = (function () use ($f) {
            foreach ($this->filtered($f)->lazy(500) as $l) {
                yield [
                    $l->last_name, $l->first_name, $l->middle_name, $l->email, $l->mobile_number,
                    $l->birthdate?->toDateTimeImmutable(), $l->age, $l->gender ? ucfirst($l->gender) : null,
                    $l->occupation, $l->notes, $l->created_at?->toDateTimeImmutable(),
                ];
            }
        })();

        return $excel->download('leads-'.today()->toDateString().'.xlsx', $columns, $rows);
    }

    private function filtered(array $f): Builder
    {
        $query = Lead::query()->scopes(['search' => [$f['search'] ?? null]]);

        ClientController::applyPersonFilters($query, $f);

        $direction = ($f['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        foreach (self::SORTABLE[$f['sort'] ?? 'name'] as $column) {
            $query->orderBy($column, $direction);
        }
        $query->orderBy('leads.id', $direction);

        return $query;
    }

    public function store(LeadRequest $request): JsonResponse
    {
        $lead = Lead::create($request->validated());

        return (new LeadResource($lead->refresh()))->response()->setStatusCode(201);
    }

    public function show(Lead $lead): LeadResource
    {
        return new LeadResource($lead);
    }

    public function update(LeadRequest $request, Lead $lead): LeadResource
    {
        $lead->update($request->validated());

        return new LeadResource($lead->refresh());
    }

    public function destroy(Lead $lead): JsonResponse
    {
        $lead->delete();

        return response()->json(null, 204);
    }

    public function bulkDestroy(BulkDeleteRequest $request, BulkDelete $bulk): JsonResponse
    {
        return $bulk->run(Lead::class, $request->ids(), fn () => null, fn (Lead $l) => $l->delete(), fn (Lead $l) => $l->displayName());
    }

    /** Make the lead a client; the lead record is removed. */
    public function convert(ConvertLeadRequest $request, Lead $lead, LeadConverter $converter): JsonResponse
    {
        $client = $converter->convert($lead, $request->validated());

        return (new ClientResource($client->refresh()))->response()->setStatusCode(201);
    }
}
