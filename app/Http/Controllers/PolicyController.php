<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkDeleteRequest;
use App\Http\Requests\PolicyIndexRequest;
use App\Http\Requests\PolicyRequest;
use App\Http\Resources\PolicyResource;
use App\Models\Policy;
use App\Services\BulkDelete;
use App\Services\ExcelExport;
use App\Services\PolicySearch;
use App\Services\PolicyService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PolicyController extends Controller
{
    public function __construct(private PolicyService $policies) {}

    public function index(PolicyIndexRequest $request, PolicySearch $search): AnonymousResourceCollection
    {
        return PolicyResource::collection($search->paginate($request->validated()));
    }

    /** Excel download of the Clients list with the same filters and sort as index(). */
    public function export(PolicyIndexRequest $request, PolicySearch $search, ExcelExport $excel): StreamedResponse
    {
        $f = Arr::except($request->validated(), ['page', 'per_page']);

        AuditLogger::record('exported', 'policies', newValues: ['filters' => $f], description: 'Exported clients to Excel');

        // Owner and insured are separate columns, never merged into one "client" field.
        $columns = [
            ['header' => 'Policy No.', 'width' => 18],
            ['header' => 'Policy Owner', 'width' => 26],
            ['header' => 'Owner Email', 'width' => 28],
            ['header' => 'Owner Mobile', 'width' => 16],
            ['header' => 'Policy Insured', 'width' => 26],
            ['header' => 'Product', 'width' => 26],
            ['header' => 'APE', 'type' => 'money', 'width' => 14],
            ['header' => 'Sum Assured', 'type' => 'money', 'width' => 16],
            ['header' => 'Fund Types', 'width' => 30],
            ['header' => 'Suitability', 'width' => 22],
            ['header' => 'Mode of Payment', 'width' => 15],
            ['header' => 'Issued Date', 'type' => 'date', 'width' => 12],
            ['header' => 'Status', 'width' => 12],
            ['header' => 'Delivery Date', 'type' => 'date', 'width' => 13],
            ['header' => 'Orphan', 'width' => 8],
            ['header' => 'Remarks', 'width' => 40],
        ];

        $rows = (function () use ($search, $f) {
            foreach ($search->query($f)->with(['owner', 'insured', 'product', 'fundTypes'])->cursor() as $p) {
                yield [
                    $p->policy_number,
                    $p->owner?->displayName(), $p->owner?->email, $p->owner?->mobile_number,
                    $p->insured?->displayName(),
                    $p->product?->name,
                    (float) $p->ape, (float) $p->sum_assured, $p->fundTypes->pluck('name')->implode('; ') ?: null, $p->fundTypes->pluck('suitability')->filter()->unique()->map(fn ($s) => ucfirst($s))->implode('; ') ?: null,
                    str($p->mode_of_payment)->replace('_', '-')->title()->toString(),
                    $p->issued_date?->toDateTimeImmutable(),
                    str($p->status)->title()->toString(),
                    $p->policy_delivery_date?->toDateTimeImmutable(),
                    $p->is_orphan ? 'Yes' : 'No',
                    $p->remarks,
                ];
            }
        })();

        return $excel->download('clients-'.today()->toDateString().'.xlsx', $columns, $rows);
    }

    public function store(PolicyRequest $request): JsonResponse
    {
        $policy = $this->policies->create($request->validated());

        return (new PolicyResource($this->loadDetail($policy)))->response()->setStatusCode(201);
    }

    public function show(Policy $policy): PolicyResource
    {
        return new PolicyResource($this->loadDetail($policy));
    }

    public function update(PolicyRequest $request, Policy $policy): PolicyResource
    {
        $this->policies->update($policy, $request->validated());

        return new PolicyResource($this->loadDetail($policy));
    }

    public function destroy(Policy $policy): JsonResponse
    {
        $this->policies->delete($policy);

        return response()->json(null, 204);
    }

    /** Mass delete (Clients list): each policy with its beneficiaries and coverage document. */
    public function bulkDestroy(BulkDeleteRequest $request, BulkDelete $bulk): JsonResponse
    {
        return $bulk->run(
            Policy::class,
            $request->ids(),
            fn () => null,
            fn (Policy $policy) => $this->policies->delete($policy),
            fn (Policy $p) => $p->policy_number,
        );
    }

    private function loadDetail(Policy $policy): Policy
    {
        return $policy->refresh()->load(['owner', 'insured', 'product', 'fundTypes', 'beneficiaries'])->loadCount('beneficiaries');
    }
}
