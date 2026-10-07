<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\GoalRequest;
use App\Http\Resources\GoalResource;
use App\Models\Goal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class GoalController extends Controller
{
    private const SORTABLE = ['target_date', 'title', 'target_amount', 'current_amount', 'status', 'created_at', 'progress'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(Goal::STATUSES)],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = Goal::query()
            ->when($f['search'] ?? null, fn ($q, $t) => $q->where('title', 'like', '%'.addcslashes($t, '%_\\').'%'))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
;

        $direction = ($f['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        ($f['sort'] ?? 'target_date') === 'progress'
            ? $query->orderByRaw("current_amount / target_amount {$direction}")
            : $query->orderBy($f['sort'] ?? 'target_date', $direction);

        $summary = Goal::query()
            ->selectRaw("COUNT(*) AS total,
                SUM(status = 'achieved') AS achieved,
                SUM(status IN ('not_started', 'in_progress')) AS open,
                SUM(status IN ('not_started', 'in_progress') AND target_date < CURDATE()) AS overdue,
                ROUND(AVG(CASE WHEN status IN ('not_started', 'in_progress') THEN LEAST(current_amount / target_amount, 1) END) * 100, 1) AS avg_progress")
            ->first();

        return GoalResource::collection($query->paginate($f['per_page'] ?? 10)->withQueryString())
            ->additional(['summary' => array_map(fn ($v) => (float) $v, $summary->getAttributes())]);
    }

    public function store(GoalRequest $request): JsonResponse
    {
        $goal = Goal::create($request->validated() + ['user_id' => $request->user()->id]);

        return (new GoalResource($goal))->response()->setStatusCode(201);
    }

    public function show(Goal $goal): GoalResource
    {
        return new GoalResource($goal);
    }

    public function update(GoalRequest $request, Goal $goal): GoalResource
    {
        $goal->update($request->validated());

        return new GoalResource($goal);
    }

    public function destroy(Goal $goal): JsonResponse
    {
        $goal->delete();

        return response()->json(null, 204);
    }
}
