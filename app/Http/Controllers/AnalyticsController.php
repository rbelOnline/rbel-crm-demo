<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use App\Support\AnalyticsCache;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function __construct(private AnalyticsService $analytics) {}

    /**
     * Full analytics bundle. `role` (owner|insured) selects whose
     * demographics and rankings are reported.
     */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'year' => ['nullable', 'integer', 'between:1950,2100'],
            'role' => ['nullable', 'in:owner,insured'],
        ]);

        $year = isset($f['year']) ? (int) $f['year'] : null;
        $role = $f['role'] ?? 'owner';

        return response()->json(['data' => [
            'filters' => ['year' => $year, 'role' => $role],
            'sales' => $this->analytics->monthlySales($year ?? (int) now()->year),
            'annual_sales' => $this->analytics->annualSales(),
            'age_distribution' => $this->analytics->ageDistribution($year, $role),
            'gender_distribution' => $this->analytics->genderDistribution($year, $role),
            'policies' => $this->analytics->policyAnalytics($year),
            'top_clients' => $this->analytics->topClientsByApe($year, $role),
            'retention' => $this->analytics->retention(),
        ]]);
    }

    /** Monthly sales, optionally for one client in one role. */
    public function sales(Request $request): JsonResponse
    {
        $f = $request->validate([
            'year' => ['required', 'integer', 'between:1950,2100'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'role' => ['required_with:client_id', 'nullable', 'in:owner,insured'],
        ]);

        return response()->json(['data' => $this->analytics->monthlySales(
            (int) $f['year'], $f['role'] ?? null, isset($f['client_id']) ? (int) $f['client_id'] : null,
        )]);
    }

    public function ageDistribution(Request $request): JsonResponse
    {
        $f = $request->validate([
            'year' => ['nullable', 'integer', 'between:1950,2100'],
            'role' => ['required', 'in:owner,insured'],
        ]);

        return response()->json(['data' => $this->analytics->ageDistribution(isset($f['year']) ? (int) $f['year'] : null, $f['role'])]);
    }
}
