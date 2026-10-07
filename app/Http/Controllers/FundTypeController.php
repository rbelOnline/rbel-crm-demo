<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkDeleteRequest;
use App\Http\Requests\FundTypeRequest;
use App\Http\Resources\FundTypeResource;
use App\Models\FundType;
use App\Services\BulkDelete;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Fund types offered in the Fund Type dropdown on client records. */
class FundTypeController extends Controller
{
    private const SORTABLE = ['name' => 'name', 'suitability' => 'suitability', 'policies' => 'policies_count', 'created_at' => 'created_at'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
            'suitability' => ['nullable', Rule::in(FundType::SUITABILITIES)],
            'sort' => ['nullable', Rule::in(array_keys(self::SORTABLE))],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = FundType::withCount('policies')
            ->when($f['search'] ?? null, fn ($q, $t) => $q->where('name', 'like', '%'.addcslashes($t, '%_\\').'%'))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('is_active', $s === 'active'))
            ->when($f['suitability'] ?? null, fn ($q, $s) => $q->where('suitability', $s))
            ->orderBy(self::SORTABLE[$f['sort'] ?? 'name'], ($f['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc')
            ->orderBy('id');

        return FundTypeResource::collection($query->paginate($f['per_page'] ?? 10)->withQueryString());
    }

    public function store(FundTypeRequest $request): JsonResponse
    {
        $fundType = FundType::create($request->validated() + ['is_active' => true]);

        return (new FundTypeResource($fundType->loadCount('policies')))->response()->setStatusCode(201);
    }

    public function show(FundType $fundType): FundTypeResource
    {
        return new FundTypeResource($fundType->loadCount('policies'));
    }

    public function update(FundTypeRequest $request, FundType $fundType): FundTypeResource
    {
        $fundType->update($request->validated());

        return new FundTypeResource($fundType->loadCount('policies'));
    }

    public function destroy(FundType $fundType): JsonResponse
    {
        if ($reason = $this->deletionBlocker($fundType)) {
            return response()->json(['message' => "This fund type cannot be deleted because {$reason}. Mark it inactive instead."], 409);
        }

        $fundType->delete();

        return response()->json(null, 204);
    }

    public function bulkDestroy(BulkDeleteRequest $request, BulkDelete $bulk): JsonResponse
    {
        return $bulk->run(
            FundType::class,
            $request->ids(),
            fn (FundType $f) => ($reason = $this->deletionBlocker($f)) ? ucfirst($reason).'; mark it inactive instead.' : null,
            fn (FundType $f) => $f->delete(),
            fn (FundType $f) => $f->name,
        );
    }

    /** Fund types on client records must stay, so those records keep theirs. */
    private function deletionBlocker(FundType $fundType): ?string
    {
        $n = $fundType->policies()->count();

        return $n ? "{$n} client ".str('record')->plural($n).' use it' : null;
    }
}
