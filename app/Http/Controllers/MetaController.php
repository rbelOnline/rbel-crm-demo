<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\EmailTemplate;
use App\Models\FundType;
use App\Models\Goal;
use App\Models\Policy;
use App\Models\Beneficiary;
use App\Models\Product;
use App\Models\Reminder;
use App\Services\AnalyticsService;
use App\Services\CalendarLabels;
use App\Services\TemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** Reference data for dropdowns, fetched once by the SPA. */
class MetaController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $years = DB::table('policies')
            ->selectRaw('DISTINCT YEAR(issued_date) AS y')
            ->orderByDesc('y')
            ->pluck('y')
            ->map(fn ($y) => (int) $y)
            ->push((int) now()->year)
            ->unique()
            ->sortDesc()
            ->values();

        return response()->json(['data' => [
            'products' => Product::orderBy('name')->get(['id', 'code', 'name', 'plan_type', 'category', 'is_active']),
            'plan_types' => Product::PLAN_TYPES,
            'fund_types' => FundType::orderBy('name')->get(['id', 'name', 'suitability', 'is_active']),
            'fund_suitabilities' => FundType::SUITABILITIES,
            'policy_statuses' => Policy::STATUSES,
            'payment_modes' => Policy::PAYMENT_MODES,
            'relationships' => Beneficiary::RELATIONSHIPS,
            'appointment_statuses' => Appointment::STATUSES,
            'appointment_labels' => app(CalendarLabels::class)->list(),
            'goal_statuses' => Goal::STATUSES,
            'reminder_types' => Reminder::TYPES,
            'template_statuses' => EmailTemplate::STATUSES,
            'placeholders' => TemplateRenderer::PLACEHOLDERS,
            'years' => $years,
            'churn_definition' => AnalyticsService::CHURN_DEFINITION,
            // false while MAIL_MAILER=log: emails would only be written to the log file.
            'email_delivery_enabled' => config('mail.default') !== 'log',
        ]]);
    }
}
