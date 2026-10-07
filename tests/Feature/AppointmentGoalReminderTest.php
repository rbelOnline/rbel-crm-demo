<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Goal;
use App\Models\Client;
use App\Models\Policy;
use App\Models\Reminder;
use Tests\TestCase;

class AppointmentGoalReminderTest extends TestCase
{
    public function test_appointment_crud_and_filters(): void
    {
        $this->signIn('admin');
        $person = Client::factory()->create(['first_name' => 'Lorenzo']);

        $id = $this->postJson('/api/appointments', [
            'client_id' => $person->id, 'title' => 'Policy review', 'appointment_date' => today()->toDateString(),
            'appointment_time' => '10:30', 'status' => 'scheduled',
        ])->assertCreated()->assertJsonPath('data.appointment_time', '10:30')->json('data.id');

        Appointment::factory()->create(['appointment_date' => today()->subDays(10)->toDateString(), 'status' => 'completed']);

        $this->getJson('/api/appointments?status=completed')->assertJsonCount(1, 'data');
        $this->getJson('/api/appointments?date='.today()->toDateString())->assertJsonCount(1, 'data');
        $this->getJson('/api/appointments?search=Lorenzo')->assertJsonPath('data.0.id', $id);

        $this->putJson("/api/appointments/{$id}", [
            'client_id' => $person->id, 'title' => 'Policy review', 'appointment_date' => today()->addDay()->toDateString(),
            'appointment_time' => '11:00', 'status' => 'rescheduled',
        ])->assertOk()->assertJsonPath('data.status', 'rescheduled');

        $this->deleteJson("/api/appointments/{$id}")->assertNoContent();
    }

    public function test_appointment_validation(): void
    {
        $this->signIn();

        $this->postJson('/api/appointments', ['client_id' => 999999, 'appointment_time' => '25:99', 'status' => 'maybe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_id', 'title', 'appointment_date', 'appointment_time', 'status']);
    }

    public function test_advisor_goal_counts_ape_of_all_policies_issued_in_the_range(): void
    {
        $this->signIn();
        $owner = Client::factory()->born('1970-01-01')->create();
        $issued = fn (string $date, float $ape, string $status = 'active') => Policy::factory()->selfInsured($owner)->create(['ape' => $ape, 'issued_date' => $date, 'status' => $status]);
        $issued('2025-12-31', 7000);              // before Date from
        $issued('2026-01-01', 11000);             // first day: counted
        $issued('2026-03-31', 4000, 'lapsed');    // last day: counted
        $issued('2026-02-01', 3000, 'postponed'); // not counted
        $issued('2026-04-01', 9000);              // after Date to

        // A range in the past is fine: progress is counted after the fact.
        $this->postJson('/api/goals', [
            'title' => 'Q1 APE', 'target_amount' => 60000,
            'start_date' => '2026-01-01', 'target_date' => '2026-03-31', 'status' => 'in_progress',
        ])->assertCreated()->assertJsonPath('data.current_amount', 15000)->assertJsonPath('data.progress_percentage', 25);

        // Closed goals keep their final value when policies change later.
        $closed = Goal::factory()->create(['status' => 'cancelled']);
        $before = (float) $closed->current_amount;
        $issued(today()->toDateString(), 50000);
        $this->assertEquals($before, (float) $closed->fresh()->current_amount);
    }

    public function test_goal_validation(): void
    {
        $this->signIn();
        $base = ['title' => 'Goal', 'target_amount' => 1000, 'start_date' => '2026-01-01', 'target_date' => '2026-12-31', 'status' => 'in_progress'];

        $this->postJson('/api/goals', ['target_amount' => 0] + $base)->assertUnprocessable()->assertJsonValidationErrors(['target_amount']);
        $this->postJson('/api/goals', ['start_date' => null] + $base)->assertUnprocessable()->assertJsonValidationErrors(['start_date']);
        $this->postJson('/api/goals', ['target_date' => '2025-12-31'] + $base)
            ->assertUnprocessable()->assertJsonValidationErrors(['target_date' => 'Date to cannot be before Date from.']);

        // Cannot mark achieved before the derived amount reaches the target.
        $this->postJson('/api/goals', ['target_amount' => 100, 'status' => 'achieved'] + $base)->assertUnprocessable()->assertJsonValidationErrors('status');

        // Progress is capped at 100%.
        $owner = Client::factory()->create();
        Policy::factory()->selfInsured($owner)->create(['ape' => 150, 'issued_date' => today()->toDateString()]);
        $over = Goal::factory()->create(['target_amount' => 100, 'start_date' => today()->toDateString()]);
        $this->assertSame(100.0, $over->progress_percentage);
    }

    public function test_reminders_and_completion(): void
    {
        $this->signIn();
        Reminder::factory()->create(['due_date' => today()->subDays(2)->toDateString(), 'title' => 'Overdue']);
        $today = Reminder::factory()->create(['title' => 'Today']);

        $this->getJson('/api/reminders?state=overdue')->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_overdue', true);
        $this->getJson('/api/reminders?state=due_today')->assertJsonCount(1, 'data');

        $this->patchJson("/api/reminders/{$today->id}/complete", ['completed' => true])->assertOk();
        $this->getJson('/api/reminders?state=completed')->assertJsonCount(1, 'data');
    }

    public function test_calendar_lists_every_appointment_in_the_range_in_time_order(): void
    {
        $this->signIn();
        $client = Client::factory()->create(['first_name' => 'Rosa']);
        $late = Appointment::factory()->create(['client_id' => $client->id, 'appointment_date' => '2026-10-05', 'appointment_time' => '15:00']);
        $early = Appointment::factory()->create(['client_id' => $client->id, 'appointment_date' => '2026-10-05', 'appointment_time' => '09:30']);
        $other = Appointment::factory()->create(['appointment_date' => '2026-10-31', 'appointment_time' => '08:00']);
        Appointment::factory()->create(['appointment_date' => '2026-11-15']); // outside the range
        Appointment::factory()->count(12)->create(['appointment_date' => '2026-10-20']); // more than one list page: all are returned

        $data = $this->getJson('/api/appointments/calendar?from=2026-10-01&to=2026-10-31')->assertOk()->json('data');

        $this->assertCount(15, $data);
        $this->assertSame([$early->id, $late->id], array_slice(array_column($data, 'id'), 0, 2));
        $this->assertSame($other->id, end($data)['id']);
        $this->assertSame('Rosa', $data[0]['client']['first_name']);

        $this->getJson('/api/appointments/calendar?from=2026-10-31&to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/appointments/calendar?from=2026-01-01&to=2026-12-31')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/appointments/calendar?from=nope&to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    public function test_appointments_take_a_colour_label_and_can_be_filtered_by_it(): void
    {
        $this->signIn();
        $client = Client::factory()->create();
        $payload = ['client_id' => $client->id, 'title' => 'Review', 'appointment_date' => '2026-10-10', 'appointment_time' => '10:00', 'status' => 'scheduled'];

        $red = $this->postJson('/api/appointments', $payload + ['label' => 'red'])->assertCreated()->assertJsonPath('data.label', 'red')->json('data.id');
        $this->postJson('/api/appointments', $payload)->assertCreated()->assertJsonPath('data.label', null);
        $this->postJson('/api/appointments', $payload + ['label' => 'purple'])->assertUnprocessable()->assertJsonValidationErrors('label');

        $this->assertSame([$red], array_column($this->getJson('/api/appointments?label=red')->assertOk()->json('data'), 'id'));
        $this->getJson('/api/appointments/calendar?from=2026-10-01&to=2026-10-31')->assertOk()->assertJsonPath('data.0.label', 'red');

        // A label can be cleared again.
        $this->putJson("/api/appointments/{$red}", $payload + ['label' => null])->assertOk()->assertJsonPath('data.label', null);
    }

    public function test_label_names_have_defaults_and_can_be_renamed_by_managers(): void
    {
        $this->signIn('assistant');
        $this->getJson('/api/calendar-labels')->assertOk()
            ->assertJsonPath('data', [
                ['key' => 'green', 'name' => 'Policy review'], ['key' => 'blue', 'name' => 'Meeting'],
                ['key' => 'yellow', 'name' => 'Follow-up'], ['key' => 'red', 'name' => 'Urgent'],
            ]);
        $names = ['green' => 'Delivery', 'blue' => 'Prospecting', 'yellow' => 'Payment', 'red' => 'Claims'];
        $this->putJson('/api/calendar-labels', ['labels' => $names])->assertForbidden();

        $this->signIn('advisor');
        $this->putJson('/api/calendar-labels', ['labels' => ['green' => ''] + $names])->assertUnprocessable()->assertJsonValidationErrors('labels.green');
        $this->putJson('/api/calendar-labels', ['labels' => $names])->assertOk()->assertJsonPath('data.3.name', 'Claims');
        $this->putJson('/api/calendar-labels', ['labels' => ['red' => 'Urgent claims'] + $names])->assertOk()->assertJsonPath('data.3.name', 'Urgent claims'); // updates in place

        $this->getJson('/api/meta')->assertOk()->assertJsonPath('data.appointment_labels.0', ['key' => 'green', 'name' => 'Delivery']);
        $this->assertTrue(\App\Models\AuditLog::where(['module' => 'settings', 'action' => 'updated'])->where('description', 'Renamed calendar labels')->exists());
    }
}
