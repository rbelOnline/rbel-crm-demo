<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\MailSettingsRequest;
use App\Services\MailSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/** Outgoing mail server settings (admin only), edited on the Profile page. */
class MailSettingsController extends Controller
{
    public function __construct(private MailSettings $settings) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->settings->forForm()]);
    }

    public function update(MailSettingsRequest $request): JsonResponse
    {
        $this->settings->save($request->validated(), $request->user());

        return response()->json(['data' => $this->settings->forForm()]);
    }

    /** Send a test message with the saved settings. */
    public function test(Request $request): JsonResponse
    {
        $to = $request->validate(['to' => ['nullable', 'email:rfc', 'max:191']])['to'] ?? $request->user()->email;

        if (config('mail.default') !== 'smtp') {
            return response()->json(['message' => 'Email sending is turned off. Turn it on and save first.'], 422);
        }

        try {
            Mail::raw(
                'This is a test email from '.config('app.name').". If you can read this, email sending works.\n\nSent by {$request->user()->name}.",
                fn ($m) => $m->to($to)->subject(config('app.name').' test email'),
            );
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Sending failed: '.Str::limit($e->getMessage(), 300)], 422);
        }

        return response()->json(['message' => "Test email sent to {$to}. Check the inbox (and the spam folder)."]);
    }
}
