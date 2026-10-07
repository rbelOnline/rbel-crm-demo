<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkDeleteRequest;
use App\Http\Requests\ProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\BulkDelete;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Insurance plans (Plan Name, Plan Type VUL/TRAD) offered on client records. */
class ProductController extends Controller
{
    private const SORTABLE = ['name' => 'name', 'plan_type' => 'plan_type', 'policies' => 'policies_count', 'created_at' => 'created_at'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'plan_type' => ['nullable', Rule::in(Product::PLAN_TYPES)],
            'status' => ['nullable', 'in:active,inactive'],
            'sort' => ['nullable', Rule::in(array_keys(self::SORTABLE))],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Product::withCount('policies')
            ->when($f['search'] ?? null, fn ($q, $t) => $q->where('name', 'like', '%'.addcslashes($t, '%_\\').'%'))
            ->when($f['plan_type'] ?? null, fn ($q, $t) => $q->where('plan_type', $t))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('is_active', $s === 'active'))
            ->orderBy(self::SORTABLE[$f['sort'] ?? 'name'], ($f['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc')
            ->orderBy('id');

        return ProductResource::collection($query->paginate($f['per_page'] ?? 10)->withQueryString());
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $product = Product::create($request->validated() + ['is_active' => true]);

        return (new ProductResource($product->loadCount('policies')))->response()->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($product->loadCount('policies'));
    }

    public function update(ProductRequest $request, Product $product): ProductResource
    {
        $product->update($request->validated());

        return new ProductResource($product->loadCount('policies'));
    }

    public function destroy(Product $product): JsonResponse
    {
        if ($reason = $this->deletionBlocker($product)) {
            return response()->json(['message' => "This plan cannot be deleted because {$reason}. Mark it inactive instead."], 409);
        }

        $product->delete();

        return response()->json(null, 204);
    }

    public function bulkDestroy(BulkDeleteRequest $request, BulkDelete $bulk): JsonResponse
    {
        return $bulk->run(
            Product::class,
            $request->ids(),
            fn (Product $p) => ($reason = $this->deletionBlocker($p)) ? ucfirst($reason).'; mark it inactive instead.' : null,
            fn (Product $p) => $p->delete(),
            fn (Product $p) => $p->name,
        );
    }

    /** Plans on client records must stay, so those records keep their plan. */
    private function deletionBlocker(Product $product): ?string
    {
        $n = $product->policies()->count();

        return $n ? "{$n} client ".str('record')->plural($n).' use it' : null;
    }
}
