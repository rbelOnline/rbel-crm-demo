<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Goal;
use App\Models\Client;
use App\Models\Policy;
use App\Models\Reminder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function __construct(private AnalyticsService $analytics) {}

    /**
     * @param  int  $year  Issue year. Drives Total Clients, churn, pending delivery, goals,
     *                    the generations chart and the monthly sales chart.
     * @param  string  $ageRole  'owner' or 'insured' — whose birth year the generations chart uses.
     */
    public function build(int $year, string $ageRole, CarbonImmutable $today): array
    {
        return [
            'filters' => ['year' => $year, 'age_role' => $ageRole, 'today' => $today->toDateString()],
            'kpis' => $this->kpis($year, $today),
            'due_today' => $this->dueToday($today),
            // Birthdays and anniversaries are always for the current month.
            'birthdays' => $this->birthdays($today->year, $today->month),
            'anniversaries' => $this->anniversaries($today->year, $today->month),
            'sales' => $this->analytics->monthlySales($year),
            'age_distribution' => $this->analytics->ageDistribution($year, $ageRole),
            'upcoming_appointments' => $this->upcomingAppointments($today),
            'goals' => $this->goals($year),
        ];
    }

    /** Policies issued in $year. */
    private function issuedIn(int $year)
    {
        return Policy::query()->whereBetween('issued_date', ["{$year}-01-01", "{$year}-12-31"]);
    }

    /** Goals whose target date falls in $year. */
    private function goalsDueIn(int $year)
    {
        return Goal::query()->whereBetween('target_date', ["{$year}-01-01", "{$year}-12-31"]);
    }

    public function kpis(int $year, CarbonImmutable $today): array
    {
        $retention = $this->analytics->retention($year, $year);

        $appointments = Appointment::query()
            ->where('status', 'scheduled')
            ->where('appointment_date', '>=', $today->toDateString())
            ->selectRaw('COUNT(*) AS upcoming, SUM(appointment_date = ?) AS today', [$today->toDateString()])
            ->first();

        $goals = $this->goalsDueIn($year)
            ->whereIn('status', ['not_started', 'in_progress'])
            ->selectRaw('COUNT(*) AS active, AVG(LEAST(current_amount / target_amount, 1)) * 100 AS avg_progress')
            ->first();

        return [
            // Client records = rows in the Clients module (one per policy), issued in the year.
            'total_clients' => $this->issuedIn($year)->count(),
            'pending_delivery' => $this->issuedIn($year)->whereNull('policy_delivery_date')->whereIn('status', Policy::IN_FORCE_STATUSES)->count(),
            'appointments_upcoming' => (int) $appointments->upcoming,
            'appointments_today' => (int) $appointments->today,
            'goals_active' => (int) $goals->active,
            'goals_avg_progress' => round((float) $goals->avg_progress, 1),
            'goals_achieved' => $this->goalsDueIn($year)->where('status', 'achieved')->count(),
            'inactive_clients' => $retention['inactive_clients'],
            'churn_rate_pct' => $retention['churn_rate_pct'],
            'churn_definition' => $retention['definition'],
        ];
    }

    /**
     * Everything needing action today, normalised into one list:
     * premiums due, reminders (incl. overdue), appointments, and deliveries
     * pending for 7+ days.
     *
     * client_id is the email recipient: the Policy Owner for premiums and
     * deliveries (the payer/holder), otherwise the reminder's or appointment's client.
     */
    public function dueToday(CarbonImmutable $today): array
    {
        $date = $today->toDateString();
        $items = [];

        foreach (DB::table('vw_premiums_due')->where('next_due_date', $date)->orderBy('owner_name')->limit(25)->get() as $p) {
            $items[] = [
                'type' => 'premium',
                'title' => "Premium due · {$p->policy_number}",
                'subtitle' => "{$p->owner_name} · {$p->product_name} · ".str_replace('_', '-', $p->mode_of_payment),
                'amount' => (float) $p->modal_premium,
                'date' => $date,
                'overdue' => false,
                'link' => "/clients/{$p->policy_id}",
                'client_id' => (int) $p->policy_owner_id,
                'policy_id' => (int) $p->policy_id,
            ];
        }

        $reminders = Reminder::with(['client:id,first_name,last_name', 'policy:id,policy_number'])
            ->whereNull('completed_at')
            ->where('due_date', '<=', $date)
            ->orderBy('due_date')
            ->limit(25)
            ->get();

        foreach ($reminders as $r) {
            $items[] = [
                'type' => $r->type === 'delivery' ? 'delivery' : ($r->type === 'payment' ? 'premium' : 'reminder'),
                'id' => $r->id,
                'title' => $r->title,
                'subtitle' => trim(($r->client ? "{$r->client->first_name} {$r->client->last_name}" : '').($r->policy ? " · {$r->policy->policy_number}" : ''), ' ·'),
                'date' => $r->due_date->toDateString(),
                'overdue' => $r->due_date->lt($today),
                'link' => $r->policy_id ? "/clients/{$r->policy_id}" : ($r->client_id ? "/people/{$r->client_id}" : '/reminders'),
                'client_id' => $r->client_id,
                'policy_id' => $r->policy_id,
            ];
        }

        $appointments = Appointment::with('client:id,first_name,last_name')
            ->where('appointment_date', $date)
            ->whereIn('status', ['scheduled', 'rescheduled'])
            ->orderBy('appointment_time')
            ->get();

        foreach ($appointments as $a) {
            $items[] = [
                'type' => 'appointment',
                'title' => $a->title,
                'subtitle' => CarbonImmutable::parse($a->appointment_time)->format('g:i A').' · '.$a->client?->first_name.' '.$a->client?->last_name,
                'date' => $date,
                'overdue' => false,
                'link' => '/appointments?date_from='.$date.'&date_to='.$date,
                'client_id' => $a->client_id,
                'policy_id' => null,
            ];
        }

        $deliveries = DB::table('vw_policy_delivery_monitoring')
            ->where('days_pending', '>=', 7)
            ->orderByDesc('days_pending')
            ->limit(10)
            ->get();

        foreach ($deliveries as $d) {
            $items[] = [
                'type' => 'delivery',
                'title' => "Deliver policy {$d->policy_number}",
                'subtitle' => "{$d->owner_name} · pending {$d->days_pending} days",
                'date' => $date,
                'overdue' => $d->days_pending > 30,
                'link' => "/clients/{$d->policy_id}",
                'client_id' => (int) $d->policy_owner_id,
                'policy_id' => (int) $d->policy_id,
            ];
        }

        return $items;
    }

    /** Client birthdays in a month, with the age they turn in that year. */
    public function birthdays(int $year, int $month): array
    {
        return Client::query()
            ->whereMonth('birthdate', $month)
            ->orderByRaw('DAY(birthdate)')
            ->limit(50)
            ->get(['id', 'first_name', 'last_name', 'birthdate', 'email', 'mobile_number'])
            ->map(fn (Client $p) => [
                'id' => $p->id,
                'name' => "{$p->first_name} {$p->last_name}",
                'birthdate' => $p->birthdate->toDateString(),
                'day' => $p->birthdate->day,
                'turning' => $year - $p->birthdate->year,
                'has_email' => filled($p->email),
            ])->all();
    }

    /** In-force policies whose issue month is $month, issued before $year (their anniversary in $year). */
    public function anniversaries(int $year, int $month): array
    {
        return Policy::with(['owner:id,first_name,last_name', 'insured:id,first_name,last_name', 'product:id,name'])
            ->where('issued_month', $month)
            ->whereIn('status', Policy::IN_FORCE_STATUSES)
            ->where('issued_date', '<', "{$year}-01-01")
            ->orderByRaw('DAY(issued_date)')
            ->limit(50)
            ->get()
            ->map(fn (Policy $p) => [
                'id' => $p->id,
                'policy_number' => $p->policy_number,
                'product' => $p->product->name,
                'policy_owner' => "{$p->owner->first_name} {$p->owner->last_name}",
                'policy_insured' => "{$p->insured->first_name} {$p->insured->last_name}",
                'issued_date' => $p->issued_date->toDateString(),
                'anniversary_date' => $p->issued_date->copy()->setYear($year)->toDateString(),
                'years' => $year - $p->issued_date->year,
                'ape' => (float) $p->ape,
            ])->all();
    }

    public function upcomingAppointments(CarbonImmutable $today): array
    {
        return Appointment::with('client:id,first_name,last_name')
            ->whereIn('status', ['scheduled', 'rescheduled'])
            ->whereBetween('appointment_date', [$today->toDateString(), $today->addDays(14)->toDateString()])
            ->orderBy('appointment_date')
            ->orderBy('appointment_time')
            ->limit(8)
            ->get()
            ->map(fn (Appointment $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'client' => $a->client ? "{$a->client->first_name} {$a->client->last_name}" : null,
                'client_id' => $a->client_id,
                'date' => $a->appointment_date->toDateString(),
                'time' => substr($a->appointment_time, 0, 5),
                'status' => $a->status,
            ])->all();
    }

    /** Open goals with a target date in the year, soonest first. */
    public function goals(int $year): array
    {
        return $this->goalsDueIn($year)->whereIn('status', ['not_started', 'in_progress'])
            ->orderBy('target_date')
            ->limit(5)
            ->get()
            ->map(fn (Goal $g) => [
                'id' => $g->id,
                'title' => $g->title,
                'target_amount' => (float) $g->target_amount,
                'current_amount' => (float) $g->current_amount,
                'progress' => $g->progress_percentage,
                'target_date' => $g->target_date->toDateString(),
                'status' => $g->status,
            ])->all();
    }
}
