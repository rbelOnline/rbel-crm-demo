<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\CoverageDocumentRequest;
use App\Http\Resources\PolicyResource;
use App\Models\Policy;
use App\Services\CoverageDocumentService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CoverageDocumentController extends Controller
{
    public function __construct(private CoverageDocumentService $documents) {}

    /** Upload, or replace the existing document. */
    public function store(CoverageDocumentRequest $request, Policy $policy): PolicyResource
    {
        $this->documents->store($policy, $request->file('document'));

        return new PolicyResource($policy->refresh()->load(['owner', 'insured', 'product']));
    }

    public function show(Policy $policy): StreamedResponse
    {
        return $this->documents->response($policy, download: false);
    }

    public function download(Policy $policy): StreamedResponse
    {
        return $this->documents->response($policy, download: true);
    }

    public function destroy(Policy $policy): JsonResponse
    {
        abort_unless($policy->hasCoverageDocument(), 404, 'No coverage document on file.');
        $this->documents->delete($policy);

        return response()->json(null, 204);
    }
}
