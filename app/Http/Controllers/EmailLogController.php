<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmailLogResource;
use App\Models\EmailLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class EmailLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(EmailLog::STATUSES)],
            'email_template_id' => ['nullable', 'integer'],
            'client_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = EmailLog::with(['template:id,name', 'client:id,first_name,middle_name,last_name', 'user:id,name'])
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['email_template_id'] ?? null, fn ($q, $id) => $q->where('email_template_id', $id))
            ->when($f['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($f['search'] ?? null, fn ($q, $t) => $q->where('subject', 'like', '%'.addcslashes($t, '%_\\').'%'))
            ->latest('id');

        return EmailLogResource::collection($query->paginate($f['per_page'] ?? 10)->withQueryString());
    }
}
