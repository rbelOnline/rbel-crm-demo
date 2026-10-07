<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\AutomationRequest;
use App\Models\Automation;
use App\Models\User;
use App\Services\AutomationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Daily email automations (admin only). */
class AutomationController extends Controller
{
    public function __construct(private AutomationService $automations) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Automation::with(['template:id,name,status', 'sender:id,name,email'])->orderBy('id')->get()->map(fn (Automation $a) => $this->shape($a)),
            'senders' => User::orderBy('name')->get(['id', 'name', 'email']),
            'email_delivery_enabled' => config('mail.default') !== 'log',
            // Last scheduler check; stale means automatic sending is not running.
            'scheduler_last_seen' => Cache::get(AutomationService::HEARTBEAT_KEY),
        ]);
    }

    public function update(AutomationRequest $request, Automation $automation): JsonResponse
    {
        $automation->update($request->validated());

        return response()->json(['data' => $this->shape($automation->refresh()->load(['template:id,name,status', 'sender:id,name,email']))]);
    }

    /** Who gets today's emails, before anything is sent. */
    public function preview(Automation $automation): JsonResponse
    {
        return response()->json(['data' => array_map(fn (array $c) => [
            'client_id' => $c['client']?->id,
            'name' => $c['client']?->displayName(),
            'email' => $c['client']?->email,
            'policy_id' => $c['policy']?->id,
            'policy_number' => $c['policy']?->policy_number,
            'detail' => $c['detail'],
            'status' => $c['status'],
        ], $this->automations->candidates($automation))]);
    }

    /** Send today's emails now, instead of waiting for the send time. */
    public function run(Request $request, Automation $automation): JsonResponse
    {
        abort_unless($automation->email_template_id, 422, 'Choose an email template first.');

        return response()->json(['data' => $this->automations->run($automation, 'manual', $request->user())]);
    }

    private function shape(Automation $a): array
    {
        return [
            'id' => $a->id,
            'key' => $a->key,
            'label' => $a->label(),
            'enabled' => $a->enabled,
            'email_template_id' => $a->email_template_id,
            'template' => $a->template,
            'send_time' => $a->sendTime(),
            'sender_user_id' => $a->sender_user_id,
            'sender' => $a->sender,
            'last_run_at' => $a->last_run_at?->toIso8601String(),
            'last_run_summary' => $a->last_run_summary,
        ];
    }
}
