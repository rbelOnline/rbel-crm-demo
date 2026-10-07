<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Type-ahead for the Policy Owner on the Clients (policy) form: existing Policy
 * Owners (clients flagged is_policy_owner) and leads. Picking a lead converts
 * them into a client when the policy is saved (PolicyService).
 */
class OwnerLookupController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            // Resolve one already-selected value instead of searching.
            'client_id' => ['nullable', 'integer'],
            'lead_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);
        $limit = $v['limit'] ?? 20;
        $columns = ['id', 'first_name', 'middle_name', 'last_name', 'birthdate'];

        if (isset($v['client_id']) || isset($v['lead_id'])) {
            $clients = isset($v['client_id']) ? Client::whereKey($v['client_id'])->get($columns) : collect();
            $leads = isset($v['lead_id']) ? Lead::whereKey($v['lead_id'])->get($columns) : collect();
        } else {
            $clients = Client::query()->policyOwners()->search($v['q'] ?? null)
                ->orderBy('last_name')->orderBy('first_name')->limit($limit)->get($columns);
            $leads = Lead::query()->search($v['q'] ?? null)
                ->orderBy('last_name')->orderBy('first_name')->limit($limit)->get($columns);
        }

        $shape = fn (string $kind) => fn ($p) => [
            'kind' => $kind,
            'id' => $p->id,
            'first_name' => $p->first_name,
            'middle_name' => $p->middle_name,
            'last_name' => $p->last_name,
            'age' => $p->birthdate?->age,
        ];

        return response()->json([
            'data' => [...$clients->map($shape('client')), ...$leads->map($shape('lead'))],
        ]);
    }
}
