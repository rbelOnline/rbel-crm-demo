<?php

namespace Tests\Feature;

use App\Models\ScheduleItem;
use Tests\TestCase;

/** Personal schedule on the Calendar: each user's own, by the hour. */
class ScheduleItemTest extends TestCase
{
    private function item(array $overrides = []): array
    {
        return array_merge(['title' => 'Prospecting calls', 'date' => '2026-10-05', 'start_time' => '09:00', 'end_time' => '10:00', 'label' => 'green', 'notes' => null], $overrides);
    }

    public function test_add_list_edit_and_delete_own_schedule(): void
    {
        $this->signIn();

        $id = $this->postJson('/api/schedule-items', $this->item())
            ->assertCreated()->assertJsonPath('data.start_time', '09:00')->assertJsonPath('data.end_time', '10:00')->assertJsonPath('data.label', 'green')
            ->json('data.id');
        $this->postJson('/api/schedule-items', $this->item(['title' => 'Lunch', 'start_time' => '12:00', 'end_time' => null, 'label' => null]))->assertCreated();
        $this->postJson('/api/schedule-items', $this->item(['title' => 'Gym', 'start_time' => '06:30', 'date' => '2026-10-06']))->assertCreated();
        $this->postJson('/api/schedule-items', $this->item(['date' => '2026-12-01']))->assertCreated(); // outside the range below

        $titles = array_column($this->getJson('/api/schedule-items?from=2026-10-01&to=2026-10-31')->assertOk()->json('data'), 'title');
        $this->assertSame(['Prospecting calls', 'Lunch', 'Gym'], $titles); // by date, then time

        $this->putJson("/api/schedule-items/{$id}", $this->item(['start_time' => '14:00', 'end_time' => '15:30']))->assertOk()->assertJsonPath('data.start_time', '14:00');

        $this->deleteJson("/api/schedule-items/{$id}")->assertNoContent();
        $this->assertSame(0, ScheduleItem::whereKey($id)->count());
    }

    public function test_schedules_are_private_to_their_owner(): void
    {
        $this->signIn('admin');
        $mine = $this->postJson('/api/schedule-items', $this->item(['title' => 'Admin only']))->assertCreated()->json('data.id');

        // Another user — even a manager — neither sees nor touches it.
        $this->signIn('advisor');
        $this->getJson('/api/schedule-items?from=2026-10-01&to=2026-10-31')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson("/api/schedule-items/{$mine}", $this->item(['title' => 'Hijacked']))->assertNotFound();
        $this->deleteJson("/api/schedule-items/{$mine}")->assertNotFound();
        $this->assertSame('Admin only', ScheduleItem::find($mine)->title);
    }

    public function test_schedule_validation(): void
    {
        $this->signIn();

        $this->postJson('/api/schedule-items', $this->item(['title' => '', 'start_time' => '9am', 'label' => 'purple']))
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'start_time', 'label']);
        $this->postJson('/api/schedule-items', $this->item(['start_time' => '10:00', 'end_time' => '09:00']))
            ->assertUnprocessable()->assertJsonValidationErrors(['end_time' => 'End time must be after the start time.']);
        $this->getJson('/api/schedule-items?from=2026-01-01&to=2026-12-31')->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    private function days(string $from, string $to): array
    {
        return array_column($this->getJson("/api/schedule-items?from={$from}&to={$to}")->assertOk()->json('data'), 'date');
    }

    public function test_daily_and_weekly_items_repeat_until_their_last_day(): void
    {
        $this->signIn();
        $this->postJson('/api/schedule-items', $this->item(['title' => 'Standup', 'date' => '2026-10-05', 'repeat' => 'daily', 'repeat_until' => '2026-10-08']))
            ->assertCreated()->assertJsonPath('data.repeat', 'daily')->assertJsonPath('data.repeat_until', '2026-10-08');
        $this->assertSame(['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08'], $this->days('2026-10-01', '2026-10-31'));

        ScheduleItem::query()->delete();
        // Weekly, open-ended: same weekday (Monday) each week, from the first day on — also in a later month.
        $this->postJson('/api/schedule-items', $this->item(['date' => '2026-10-05', 'repeat' => 'weekly']))->assertCreated();
        $this->assertSame(['2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26'], $this->days('2026-10-01', '2026-10-31'));
        $this->assertSame(['2027-03-01', '2027-03-08'], $this->days('2027-03-01', '2027-03-10'));
        $this->assertSame([], $this->days('2026-09-01', '2026-09-30')); // nothing before the first day

        // Each occurrence carries the series' first day.
        $this->getJson('/api/schedule-items?from=2026-10-10&to=2026-10-14')->assertJsonPath('data.0.date', '2026-10-12')->assertJsonPath('data.0.starts_on', '2026-10-05');
    }

    public function test_monthly_and_yearly_items_skip_days_a_month_or_year_does_not_have(): void
    {
        $this->signIn();
        $this->postJson('/api/schedule-items', $this->item(['title' => 'Month end', 'date' => '2026-01-31', 'repeat' => 'monthly']))->assertCreated();
        // February and April have no 31st.
        $this->assertSame([], $this->days('2026-02-01', '2026-02-28'));
        $this->assertSame(['2026-03-31'], $this->days('2026-03-01', '2026-03-31'));
        $this->assertSame([], $this->days('2026-04-01', '2026-04-30'));
        $this->assertSame(['2026-05-31'], $this->days('2026-05-01', '2026-05-31'));

        ScheduleItem::query()->delete();
        $this->postJson('/api/schedule-items', $this->item(['title' => 'Leap day', 'date' => '2024-02-29', 'repeat' => 'yearly']))->assertCreated();
        $this->assertSame([], $this->days('2027-02-01', '2027-03-10'));
        $this->assertSame(['2028-02-29'], $this->days('2028-02-01', '2028-03-10'));

        ScheduleItem::query()->delete();
        $this->postJson('/api/schedule-items', $this->item(['title' => 'Anniversary', 'date' => '2026-10-20', 'repeat' => 'yearly']))->assertCreated();
        $this->assertSame(['2030-10-20'], $this->days('2030-10-01', '2030-10-31'));
    }

    public function test_one_day_can_be_removed_from_a_series_or_the_whole_series_deleted(): void
    {
        $this->signIn();
        $id = $this->postJson('/api/schedule-items', $this->item(['date' => '2026-10-05', 'repeat' => 'daily', 'repeat_until' => '2026-10-07']))->json('data.id');

        $this->postJson("/api/schedule-items/{$id}/skip", ['date' => '2026-10-06'])->assertOk();
        $this->assertSame(['2026-10-05', '2026-10-07'], $this->days('2026-10-01', '2026-10-31'));
        // Only days the series falls on can be skipped.
        $this->postJson("/api/schedule-items/{$id}/skip", ['date' => '2026-10-09'])->assertUnprocessable()->assertJsonValidationErrors('date');

        // Editing changes every occurrence; a one-off item has no last day.
        $this->putJson("/api/schedule-items/{$id}", $this->item(['date' => '2026-10-05', 'repeat' => 'none', 'repeat_until' => '2026-10-07']))
            ->assertOk()->assertJsonPath('data.repeat', 'none')->assertJsonPath('data.repeat_until', null);
        $this->assertSame(['2026-10-05'], $this->days('2026-10-01', '2026-10-31'));

        $this->putJson("/api/schedule-items/{$id}", $this->item(['date' => '2026-10-05', 'repeat' => 'weekly', 'repeat_until' => '2026-10-01']))
            ->assertUnprocessable()->assertJsonValidationErrors('repeat_until');
        $this->postJson('/api/schedule-items', $this->item(['repeat' => 'hourly']))->assertUnprocessable()->assertJsonValidationErrors('repeat');

        $this->deleteJson("/api/schedule-items/{$id}")->assertNoContent();
        $this->assertSame([], $this->days('2026-10-01', '2026-10-31'));
    }
}
