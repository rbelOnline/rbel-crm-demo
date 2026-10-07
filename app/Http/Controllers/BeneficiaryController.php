<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\BeneficiaryRequest;
use App\Http\Resources\BeneficiaryResource;
use App\Models\Beneficiary;
use App\Models\Policy;
use App\Services\PolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Beneficiaries nested under a policy (scoped route bindings). */
class BeneficiaryController extends Controller
{
    public function __construct(private PolicyService $policies) {}

    public function index(Policy $policy): AnonymousResourceCollection
    {
        return BeneficiaryResource::collection(
            $policy->beneficiaries()->orderBy('beneficiary_type')->orderByDesc('allocation_percentage')->get()
        );
    }

    public function store(BeneficiaryRequest $request, Policy $policy): JsonResponse
    {
        $beneficiary = $this->policies->saveBeneficiary($policy, $request->validated());

        return (new BeneficiaryResource($beneficiary))->response()->setStatusCode(201);
    }

    public function update(BeneficiaryRequest $request, Policy $policy, Beneficiary $beneficiary): BeneficiaryResource
    {
        $this->policies->saveBeneficiary($policy, $request->validated(), $beneficiary);

        return new BeneficiaryResource($beneficiary->refresh());
    }

    public function destroy(Policy $policy, Beneficiary $beneficiary): JsonResponse
    {
        $beneficiary->delete();

        return response()->json(null, 204);
    }
}
