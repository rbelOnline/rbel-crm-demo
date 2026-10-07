<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientSummaryResource;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Lightweight type-ahead for client pickers (policy owner / insured, appointments, reminders). */
class ClientLookupController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'ids' => ['nullable', 'string', 'max:500'],
            // owner: clients who may own a policy (is_policy_owner).
            // owns / insured: clients who actually own / are insured under a policy.
            'role' => ['nullable', 'in:owner,owns,insured'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $query = Client::query()->select(['id', 'first_name', 'middle_name', 'last_name', 'birthdate', 'gender', 'is_policy_owner']);

        match ($validated['role'] ?? null) {
            'owner' => $query->where('is_policy_owner', true),
            'owns' => $query->whereHas('ownedPolicies'),
            'insured' => $query->whereHas('insuredPolicies'),
            default => null,
        };

        // Resolve specific ids (to label an already-selected value).
        if (filled($validated['ids'] ?? null)) {
            $ids = array_filter(array_map('intval', explode(',', $validated['ids'])));
            $query->whereIn('id', $ids);
        } else {
            $query->search($validated['q'] ?? null);
        }

        return ClientSummaryResource::collection(
            $query->orderBy('last_name')->orderBy('first_name')->limit($validated['limit'] ?? 20)->get()
        );
    }
}
