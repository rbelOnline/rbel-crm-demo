<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkDeleteRequest;
use App\Http\Requests\ClientIndexRequest;
use App\Http\Requests\ClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\Policy;
use App\Services\BulkDelete;
use App\Services\ExcelExport;
use App\Services\PolicyService;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Clients: the people on policies. Their policies are exposed in two separate
 * lists — owned and insured.
 */
class ClientController extends Controller
{
    public const SORTABLE = [
        'name' => ['last_name', 'first_name'],
        'email' => ['email'],
        'birthdate' => ['birthdate'],
        'created_at' => ['created_at'],
        'owned_policies' => ['owned_policies_count'],
        'insured_policies' => ['insured_policies_count'],
        'total_ape' => ['owned_policies_sum_ape'],
    ];

    public function __construct(private PolicyService $policies) {}

    public function index(ClientIndexRequest $request): AnonymousResourceCollection
    {
        $f = $request->validated();

        return ClientResource::collection(
            $this->filtered($f)->paginate(min((int) ($f['per_page'] ?? 10), 100))->withQueryString()
        );
    }

    /** Excel download of the list with the same filters and sort as index(). */
    public function export(ClientIndexRequest $request, ExcelExport $excel): StreamedResponse
    {
        $f = Arr::except($request->validated(), ['page', 'per_page']);

        AuditLogger::record('exported', 'clients', newValues: ['filters' => $f], description: 'Exported clients to Excel');

        $columns = [
            ['header' => 'Last name', 'width' => 18],
            ['header' => 'First name', 'width' => 18],
            ['header' => 'Middle name', 'width' => 16],
            ['header' => 'Policy Owner', 'width' => 12],
            ['header' => 'Email', 'width' => 30],
            ['header' => 'Mobile', 'width' => 18],
            ['header' => 'Birthdate', 'type' => 'date', 'width' => 12],
            ['header' => 'Age', 'type' => 'int', 'width' => 6],
            ['header' => 'Gender', 'width' => 10],
            ['header' => 'Occupation', 'width' => 20],
            ['header' => 'Address', 'width' => 36],
            ['header' => 'Added', 'type' => 'date', 'width' => 12],
        ];

        $rows = (function () use ($f) {
            foreach ($this->filtered($f)->lazy(500) as $c) {
                yield [
                    $c->last_name, $c->first_name, $c->middle_name, $c->is_policy_owner ? 'Yes' : 'No',
                    $c->email, $c->mobile_number, $c->birthdate?->toDateTimeImmutable(), $c->age,
                    $c->gender ? ucfirst($c->gender) : null, $c->occupation, $c->address,
                    $c->created_at?->toDateTimeImmutable(),
                ];
            }
        })();

        return $excel->download('clients-'.today()->toDateString().'.xlsx', $columns, $rows);
    }

    private function filtered(array $f): Builder
    {
        // Scopes are applied through scopes() so static analysis can resolve the calls.
        $query = Client::query()
            ->scopes(['search' => [$f['search'] ?? null]])
            ->withCount(['ownedPolicies', 'insuredPolicies', ...Client::statusCounts()])
            ->withSum('ownedPolicies', 'ape');

        if (isset($f['is_policy_owner'])) {
            $query->where('is_policy_owner', (bool) $f['is_policy_owner']);
        }

        match ($f['role'] ?? null) {
            'owner' => $query->whereHas('ownedPolicies'),
            'insured' => $query->whereHas('insuredPolicies'),
            // Owns at least one policy but is not insured under any.
            'owner_only' => $query->whereHas('ownedPolicies')->whereDoesntHave('insuredPolicies'),
            // Insured under at least one policy but owns none.
            'insured_only' => $query->whereHas('insuredPolicies')->whereDoesntHave('ownedPolicies'),
            default => null,
        };

        // Same rule as Client::statusFor(), as queries.
        $inForce = fn ($q) => $q->whereIn('status', Policy::IN_FORCE_STATUSES);
        $churned = fn ($q) => $q->whereIn('status', Policy::CHURN_STATUSES);
        $matured = fn ($q) => $q->where('status', 'matured');
        match ($f['client_status'] ?? null) {
            'active' => $query->whereHas('ownedPolicies', $inForce),
            'inactive' => $query->whereDoesntHave('ownedPolicies', $inForce)->whereHas('ownedPolicies', $churned),
            'completed' => $query->whereDoesntHave('ownedPolicies', $inForce)->whereDoesntHave('ownedPolicies', $churned)->whereHas('ownedPolicies', $matured),
            'prospect' => $query->whereDoesntHave('ownedPolicies', $inForce)->whereDoesntHave('ownedPolicies', $churned)->whereDoesntHave('ownedPolicies', $matured),
            default => null,
        };

        self::applyPersonFilters($query, $f);

        $direction = ($f['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        foreach (self::SORTABLE[$f['sort'] ?? 'name'] as $column) {
            $query->orderBy($column, $direction);
        }
        $query->orderBy('clients.id', $direction);

        return $query;
    }

    /** Gender, birth month and age filters, shared with the Leads list. */
    public static function applyPersonFilters(Builder $query, array $f): void
    {
        if (filled($f['gender'] ?? null)) {
            $query->where('gender', $f['gender']);
        }

        if (filled($f['birth_month'] ?? null)) {
            $query->whereMonth('birthdate', (int) $f['birth_month']);
        }

        // Age bounds as birthdate ranges so the birthdate index is usable.
        if (isset($f['age_min'])) {
            $query->where('birthdate', '<=', today()->subYears($f['age_min'])->toDateString());
        }
        if (isset($f['age_max'])) {
            $query->where('birthdate', '>', today()->subYears($f['age_max'] + 1)->toDateString());
        }
    }

    public function store(ClientRequest $request): JsonResponse
    {
        $client = Client::create($request->validated());

        return (new ClientResource($client->refresh()))->response()->setStatusCode(201);
    }

    public function show(Client $client): ClientResource
    {
        $client->load([
            'ownedPolicies' => fn ($q) => $q->with(['insured', 'product'])->withCount('beneficiaries')->orderByDesc('issued_date'),
            'insuredPolicies' => fn ($q) => $q->with(['owner', 'product'])->withCount('beneficiaries')->orderByDesc('issued_date'),
            'appointments' => fn ($q) => $q->orderByDesc('appointment_date')->limit(10),
        ])->loadCount(['ownedPolicies', 'insuredPolicies', ...Client::statusCounts()])->loadSum('ownedPolicies', 'ape');

        return (new ClientResource($client))->additional([
            'meta' => ['policy_activity' => $this->policyActivity($client->id)],
        ]);
    }

    public function update(ClientRequest $request, Client $client): ClientResource
    {
        $client->update($request->validated());

        return new ClientResource($client->refresh());
    }

    /** Deletes the client with their policies, appointments, reminders and email logs. */
    public function destroy(Client $client): JsonResponse
    {
        $this->policies->deleteClient($client);

        return response()->json(null, 204);
    }

    public function bulkDestroy(BulkDeleteRequest $request, BulkDelete $bulk): JsonResponse
    {
        return $bulk->run(
            Client::class,
            $request->ids(),
            fn () => null,
            fn (Client $c) => $this->policies->deleteClient($c),
            fn (Client $c) => $c->displayName(),
        );
    }

    /**
     * Chronological policy activity for one client across BOTH roles, with the
     * previous/next issue dates via LAG/LEAD window functions.
     */
    private function policyActivity(int $clientId): array
    {
        return array_map(fn ($r) => (array) $r, DB::select("
            SELECT p.id, p.policy_number, p.issued_date, p.status, p.ape,
                   CASE
                       WHEN p.policy_owner_id = :a AND p.policy_insured_id = :b THEN 'owner_and_insured'
                       WHEN p.policy_owner_id = :c THEN 'owner'
                       ELSE 'insured'
                   END AS role,
                   LAG(p.issued_date)  OVER w AS previous_issued_date,
                   LEAD(p.issued_date) OVER w AS next_issued_date,
                   DATEDIFF(p.issued_date, LAG(p.issued_date) OVER w) AS days_since_previous,
                   SUM(p.ape) OVER (w ROWS UNBOUNDED PRECEDING) AS cumulative_ape
            FROM policies p
            WHERE p.policy_owner_id = :d OR p.policy_insured_id = :e
            WINDOW w AS (ORDER BY p.issued_date, p.id)
            ORDER BY p.issued_date, p.id", ['a' => $clientId, 'b' => $clientId, 'c' => $clientId, 'd' => $clientId, 'e' => $clientId]));
    }
}
