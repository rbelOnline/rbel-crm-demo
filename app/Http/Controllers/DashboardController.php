<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): JsonResponse
    {
        $today = CarbonImmutable::today();

        $f = $request->validate([
            'year' => ['nullable', 'integer', 'between:1950,2100'],
            'age_role' => ['nullable', 'in:owner,insured'],
        ]);

        // Defaults to the current year.
        $year = (int) ($f['year'] ?? $today->year);

        return response()->json(['data' => $dashboard->build($year, $f['age_role'] ?? 'owner', $today)]);
    }
}
