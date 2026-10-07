<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'module' => ['nullable', 'string', 'max:40'],
            'action' => ['nullable', 'string', 'max:40'],
            'user_id' => ['nullable', 'integer'],
            'record_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = AuditLog::with('user:id,name')
            ->when($f['module'] ?? null, fn ($q, $m) => $q->where('module', $m))
            ->when($f['action'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($f['user_id'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->when($f['record_id'] ?? null, fn ($q, $r) => $q->where('record_id', $r))
            ->when($f['date_from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($f['date_to'] ?? null, fn ($q, $d) => $q->where('created_at', '<', now()->parse($d)->addDay()->toDateString()))
            ->when($f['search'] ?? null, fn ($q, $t) => $q->where('description', 'like', '%'.addcslashes($t, '%_\\').'%'))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return AuditLogResource::collection($query->paginate($f['per_page'] ?? 10)->withQueryString());
    }

    /** Distinct modules/actions for the filter dropdowns. */
    public function facets(): JsonResponse
    {
        return response()->json(['data' => [
            'modules' => AuditLog::distinct()->orderBy('module')->pluck('module'),
            'actions' => AuditLog::distinct()->orderBy('action')->pluck('action'),
        ]]);
    }
}
