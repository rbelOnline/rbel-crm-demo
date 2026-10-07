<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\FundType;
use App\Models\Goal;
use App\Models\Lead;
use App\Models\Policy;
use App\Models\Product;
use App\Models\Reminder;
use App\Models\ScheduleItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

/**
 * Synthetic households that exercise every owner/insured combination:
 *  - self-insured adults (owner = insured)
 *  - parents owning policies on their children (owner ≠ insured)
 *  - spouses insuring each other (owner ≠ insured)
 *  - adult children owning health cover on elderly parents (owner ≠ insured)
 * Family members become clients only once they are on a policy; beneficiaries
 * get their own copy of a family member's details.
 * All names, emails and phone numbers are fictional.
 */
class DemoDataSeeder extends Seeder
{
    private const FIRST_MALE = ['Juan', 'Jose', 'Miguel', 'Carlo', 'Paolo', 'Rafael', 'Andres', 'Gabriel', 'Emilio', 'Ramon', 'Nestor', 'Dante', 'Rodel', 'Arnel', 'Jericho', 'Marvin', 'Enrico', 'Lorenzo', 'Benjie', 'Tomas'];

    private const FIRST_FEMALE = ['Maria', 'Ana', 'Liza', 'Carmela', 'Isabel', 'Patricia', 'Kristine', 'Angelica', 'Rosario', 'Teresa', 'Jasmine', 'Bea', 'Camille', 'Divina', 'Lourdes', 'Marites', 'Nina', 'Sofia', 'Andrea', 'Pilar'];

    private const LAST = ['Dela Cruz', 'Santos', 'Reyes', 'Bautista', 'Garcia', 'Mendoza', 'Villanueva', 'Castillo', 'Ramos', 'Aquino', 'Navarro', 'Soriano', 'Domingo', 'Salazar', 'Pascual', 'Manalo', 'Lacson', 'Tolentino', 'Gonzales', 'Francisco', 'Valdez', 'Mercado', 'Aguilar', 'Rivera', 'Del Rosario', 'Samonte', 'Cabrera', 'Ocampo', 'Panganiban', 'Evangelista'];

    private const CITIES = ['Quezon City', 'Makati', 'Pasig', 'Taguig', 'Mandaluyong', 'Cebu City', 'Davao City', 'Antipolo', 'Bacoor', 'Iloilo City', 'Baguio', 'San Fernando'];

    private CarbonImmutable $today;

    private array $products;

    private int $sequence = 0;

    public function run(): void
    {
        mt_srand(20260929);
        fake()->seed(20260929);

        $this->today = CarbonImmutable::today();
        $this->products = Product::pluck('id', 'code')->all();

        // Model events are off for bulk seeding (no per-row audit / cache churn).
        Model::withoutEvents(function () {
            for ($h = 0; $h < 140; $h++) {
                $this->household();
            }

            $this->fundTypes();
            $this->prospects(18);
            $this->appointments();
            $this->scheduleItems();
            $this->goals();
            $this->reminders();
            $this->emailTemplates();
            $this->emailLogs();
            $this->auditLogs();
        });
    }

    private function household(): void
    {
        $last = $this->pick(self::LAST);
        $city = $this->pick(self::CITIES);
        $headAge = mt_rand(26, 62);
        $headGender = mt_rand(0, 1) ? 'male' : 'female';

        $head = $this->person($last, $headGender, $headAge, $city);
        $spouse = mt_rand(1, 100) <= 70 ? $this->person($last, $headGender === 'male' ? 'female' : 'male', max(22, $headAge + mt_rand(-5, 4)), $city) : null;

        $children = [];
        $childCount = $headAge > 30 ? mt_rand(0, 3) : mt_rand(0, 1);
        for ($c = 0; $c < $childCount; $c++) {
            $childAge = max(0, min($headAge - 20, mt_rand(0, 28)));
            $children[] = $this->person($last, mt_rand(0, 1) ? 'male' : 'female', $childAge, $city);
        }

        $parent = ($headAge >= 35 && mt_rand(1, 100) <= 20)
            ? $this->person($last, mt_rand(0, 1) ? 'male' : 'female', min(90, $headAge + mt_rand(24, 32)), $city)
            : null;

        $family = array_values(array_filter([$head, $spouse, ...$children, $parent]));

        // 1. Head: self-insured life / investment cover.
        if (mt_rand(1, 100) <= 90) {
            $this->policy($head, $head, $this->pick(['RB-LP20', 'RB-WLL', 'RB-WBV', 'RB-TG10', 'RB-RS']), $family);
        }
        if (mt_rand(1, 100) <= 25) {
            $this->policy($head, $head, $this->pick(['RB-HS', 'RB-CCP']), $family);
        }

        // 2. Spouse: own policy, or insured under a policy the head owns.
        if ($spouse) {
            $roll = mt_rand(1, 100);
            if ($roll <= 40) {
                $this->policy($spouse, $spouse, $this->pick(['RB-LP20', 'RB-HS', 'RB-WBV']), $family);
            } elseif ($roll <= 70) {
                $this->policy($head, $spouse, $this->pick(['RB-LP20', 'RB-TG10', 'RB-CCP']), $family);
            }
        }

        // 3. Children: parent owns, child is insured (education / life).
        foreach ($children as $child) {
            if (mt_rand(1, 100) <= 55) {
                $owner = $spouse && mt_rand(0, 1) ? $spouse : $head;
                $this->policy($owner, $child, $child->birthdate->age < 18 ? $this->pick(['RB-EFP', 'RB-LP20', 'RB-HS']) : 'RB-LP20', $family);
            }
            // Working adult children buy their own cover.
            if ($child->birthdate->age >= 22 && mt_rand(1, 100) <= 40) {
                $this->policy($child, $child, $this->pick(['RB-TG10', 'RB-WBV', 'RB-HS']), $family);
            }
        }

        // 4. Elderly parent: adult child owns health cover on the parent.
        if ($parent && mt_rand(1, 100) <= 70) {
            $this->policy($head, $parent, $this->pick(['RB-HS', 'RB-CCP']), $family);
        }
    }

    /** A family member, not saved: they become a client when put on a policy (see enrol()). */
    private function person(string $last, string $gender, int $age, string $city): Client
    {
        $first = $this->pick($gender === 'male' ? self::FIRST_MALE : self::FIRST_FEMALE);
        $birth = $this->today->subYears($age)->subDays(mt_rand(0, 364));
        $slug = strtolower(str_replace(' ', '', $first.'.'.$last));

        return new Client([
            'first_name' => $first,
            'middle_name' => mt_rand(1, 100) <= 75 ? $this->pick(self::LAST) : null,
            'last_name' => $last,
            'birthdate' => $birth->toDateString(),
            'gender' => $gender,
            'email' => $age >= 16 && mt_rand(1, 100) <= 85 ? $slug.mt_rand(10, 9999).'@example.test' : null,
            'mobile_number' => $age >= 16 ? '+63 9'.mt_rand(10, 99).' '.mt_rand(100, 999).' '.mt_rand(1000, 9999) : null,
            'address' => mt_rand(1, 250).' '.$this->pick(['Mabini', 'Rizal', 'Bonifacio', 'Luna', 'Magsaysay', 'Quezon', 'Burgos']).' St., '.$city,
            'occupation' => $age >= 22 ? $this->pick(['Engineer', 'Teacher', 'Nurse', 'Business Owner', 'Accountant', 'IT Specialist', 'OFW', 'Sales Manager', 'Physician', 'Architect', 'Government Employee']) : ($age >= 5 ? 'Student' : null),
            'is_policy_owner' => false,
        ]);
    }

    /** Create one policy with beneficiaries chosen from the family (never the insured). */
    private function policy(Client $owner, Client $insured, string $productCode, array $family): void
    {
        // Weighted toward recent years so the current-year dashboard has data.
        $daysBack = (int) (pow(mt_rand(0, 1000) / 1000, 1.6) * 7 * 365);
        $issued = $this->today->subDays($daysBack);
        $ageYears = $issued->diffInDays($this->today) / 365;

        $status = match (true) {
            $daysBack < 20 && mt_rand(1, 100) <= 60 => 'pending',
            $ageYears > 2 && mt_rand(1, 100) <= 18 => 'lapsed',
            $ageYears > 3 && mt_rand(1, 100) <= 8 => 'surrendered',
            $ageYears > 2 && mt_rand(1, 100) <= 4 => 'terminated',
            $daysBack < 60 && mt_rand(1, 100) <= 3 => 'postponed',
            default => 'active',
        };

        $deliveryLag = mt_rand(3, 25);
        $delivered = ($daysBack > 45 || mt_rand(1, 100) <= 45) && $deliveryLag <= $daysBack && $status !== 'postponed'
            ? $issued->addDays($deliveryLag)->toDateString()
            : null;

        [$apeMin, $apeMax, $saOptions] = match ($productCode) {
            'RB-EFP' => [18000, 60000, [300000, 500000, 1000000]],
            'RB-HS', 'RB-CCP' => [15000, 55000, [500000, 1000000, 2000000]],
            'RB-WBV' => [36000, 240000, [1000000, 2000000, 5000000]],
            'RB-RS' => [60000, 300000, [1000000, 3000000]],
            'RB-TG10' => [8000, 30000, [1000000, 2000000, 3000000]],
            default => [24000, 150000, [1000000, 2000000, 3000000, 5000000]],
        };

        $this->sequence++;

        $this->enrol($owner, true);
        $this->enrol($insured, false);

        $policy = Policy::create([
            'policy_number' => sprintf('RB-%d-%06d', $issued->year, 100000 + $this->sequence),
            'policy_owner_id' => $owner->id,
            'policy_insured_id' => $insured->id,
            'product_id' => $this->products[$productCode],
            'ape' => round(mt_rand($apeMin, $apeMax) / 100) * 100,
            'issued_date' => $issued->toDateString(),
            'mode_of_payment' => $productCode === 'RB-RS' && mt_rand(0, 1) ? 'single' : $this->pick(['annual', 'annual', 'semi_annual', 'quarterly', 'monthly', 'monthly']),
            'sum_assured' => $this->pick($saOptions),
            'status' => $status,
            'policy_delivery_date' => $delivered,
            'is_orphan' => mt_rand(1, 100) <= 6,
        ]);

        $policy->forceFill(['status_changed_at' => $status === 'active' || $status === 'pending'
            ? $issued->startOfDay()
            : $issued->addDays(mt_rand(200, max(201, $daysBack)))->min($this->today)->startOfDay(),
        ])->saveQuietly();

        $this->beneficiaries($policy, $insured, $family);
    }

    /** Save a family member as a client the first time they are on a policy; owners are flagged. */
    private function enrol(Client $client, bool $asOwner): void
    {
        if ($asOwner) {
            $client->is_policy_owner = true;
        }
        if (! $client->exists || $client->isDirty()) {
            $client->save();
        }
    }

    private function beneficiaries(Policy $policy, Client $insured, array $family): void
    {
        $candidates = array_values(array_filter($family, fn (Client $p) => $p !== $insured));

        if ($candidates === []) {
            // No family: name a sibling who is not otherwise a client.
            $candidates = [$this->person($insured->last_name, mt_rand(0, 1) ? 'male' : 'female', max(18, $insured->birthdate->age + mt_rand(-6, 6)), 'Manila')];
        }

        shuffle($candidates);
        $primary = array_slice($candidates, 0, min(count($candidates), mt_rand(1, 3)));
        $shares = $this->split(count($primary));

        foreach ($primary as $i => $person) {
            Beneficiary::create($this->details($person) + [
                'policy_id' => $policy->id,
                'relationship' => $this->relationship($insured, $person),
                'beneficiary_type' => 'primary',
                'designation' => mt_rand(1, 100) <= 20 ? 'irrevocable' : 'revocable',
                'allocation_percentage' => $shares[$i],
            ]);
        }

        // Occasionally a contingent beneficiary.
        $rest = array_slice($candidates, count($primary));
        if ($rest !== [] && mt_rand(1, 100) <= 30) {
            Beneficiary::create($this->details($rest[0]) + [
                'policy_id' => $policy->id,
                'relationship' => $this->relationship($insured, $rest[0]),
                'beneficiary_type' => 'contingent',
                'designation' => 'revocable',
                'allocation_percentage' => 100,
            ]);
        }
    }

    /** A family member's details, copied onto a beneficiary designation. */
    private function details(Client $person): array
    {
        return Arr::only($person->getAttributes(), Beneficiary::DETAIL_FIELDS);
    }

    private function relationship(Client $insured, Client $beneficiary): string
    {
        $diff = $insured->birthdate->age - $beneficiary->birthdate->age;

        return match (true) {
            $diff >= 16 => 'child',
            $diff <= -16 => 'parent',
            $insured->last_name === $beneficiary->last_name && abs($diff) <= 8 && $insured->gender !== $beneficiary->gender => 'spouse',
            default => 'sibling',
        };
    }

    /** Split 100% into n shares that sum to exactly 100. */
    private function split(int $n): array
    {
        return match ($n) {
            1 => [100],
            2 => mt_rand(0, 1) ? [50, 50] : [60, 40],
            default => [40, 30, 30],
        };
    }

    private function prospects(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $person = $this->person($this->pick(self::LAST), mt_rand(0, 1) ? 'male' : 'female', mt_rand(22, 55), $this->pick(self::CITIES));
            Lead::create(Arr::only($person->getAttributes(), ['first_name', 'middle_name', 'last_name', 'occupation', 'birthdate', 'gender', 'email', 'mobile_number']) + [
                'notes' => mt_rand(0, 1) ? 'Referred by an existing client. Interested in '.$this->pick(['life', 'health', 'education', 'retirement']).' cover.' : null,
            ]);
        }
    }

    private function appointments(): void
    {
        $advisor = User::where('email', 'admin@rbel-crm.test')->value('id');
        $clients = Client::inRandomOrder()->limit(90)->pluck('id')->all();
        $titles = ['Annual policy review', 'Financial needs analysis', 'Policy delivery', 'Beneficiary update', 'Retirement planning session', 'Education plan consultation', 'Claims assistance', 'Premium payment follow-up'];

        foreach ($clients as $i => $clientId) {
            $offset = mt_rand(-45, 30);
            // Guarantee a few appointments today.
            if ($i < 3) {
                $offset = 0;
            }
            $date = $this->today->addDays($offset);

            Appointment::create([
                'client_id' => $clientId,
                'user_id' => $advisor,
                'title' => $this->pick($titles),
                'description' => 'Discuss coverage, riders and upcoming premium schedule.',
                'appointment_date' => $date->toDateString(),
                'appointment_time' => sprintf('%02d:%s', mt_rand(8, 17), $this->pick(['00', '30'])),
                'location' => $this->pick(['RBEL Office, Makati', 'Client residence', 'Video call', 'Client workplace']),
                'label' => mt_rand(1, 100) <= 60 ? $this->pick(Appointment::LABELS) : null,
                'status' => $offset < 0 ? $this->pick(['completed', 'completed', 'completed', 'cancelled', 'rescheduled']) : $this->pick(['scheduled', 'scheduled', 'scheduled', 'rescheduled']),
                'notes' => mt_rand(0, 1) ? 'Bring updated illustration and valid IDs.' : null,
            ]);
        }
    }

    /** Fund options, with one to three funds on each VUL (investment-linked) policy. */
    private function fundTypes(): void
    {
        $funds = collect([
            ['Peso Money Market Fund', 'conservative', true],
            ['Peso Bond Fund', 'conservative', true],
            ['Dollar Bond Fund', 'moderate', true],
            ['Balanced Fund', 'moderate', true],
            ['Peso Equity Fund', 'aggressive', true],
            ['Global Equity Fund', 'aggressive', true],
            ['Index Tracker Fund', 'aggressive', false],
        ])->map(fn (array $f) => FundType::create(['name' => $f[0], 'suitability' => $f[1], 'is_active' => $f[2]]));

        $active = $funds->where('is_active', true)->pluck('id')->all();

        foreach (Policy::whereHas('product', fn ($q) => $q->where('plan_type', 'VUL'))->get() as $policy) {
            shuffle($active);
            $policy->fundTypes()->attach(array_slice($active, 0, mt_rand(1, 3)));
        }
    }

    /** Personal calendar items for the admin: one-offs around today plus a few repeating ones. */
    private function scheduleItems(): void
    {
        $user = User::where('email', 'admin@rbel-crm.test')->value('id');

        $items = [
            ['Team huddle', $this->today->startOfWeek(), '08:30', '09:00', 'weekly', 'blue', 'Weekly pipeline check-in with the unit.'],
            ['Prepare monthly production report', $this->today->startOfMonth()->addDays(2), '16:00', '17:00', 'monthly', 'yellow', null],
            ['Gym', $this->today->subDays(10), '06:00', '07:00', 'daily', 'green', null],
            ['Licensing exam review', $this->today->addDays(4), '13:00', '15:00', 'none', 'red', 'Bring reviewer and calculator.'],
            ['Client prospecting calls', $this->today->addDay(), '10:00', '11:30', 'none', 'green', 'Call five referrals from last week.'],
            ['Product training webinar', $this->today->addDays(9), '14:00', '16:00', 'none', 'blue', null],
            ['Submit pending requirements', $this->today->subDays(3), '09:00', null, 'none', 'yellow', null],
            ['Wedding anniversary', $this->today->addDays(20)->subYears(6), '19:00', null, 'yearly', 'red', null],
        ];

        foreach ($items as [$title, $date, $start, $end, $repeat, $label, $notes]) {
            $item = new ScheduleItem([
                'title' => $title,
                'date' => $date->toDateString(),
                'start_time' => $start,
                'end_time' => $end,
                'repeat' => $repeat,
                'label' => $label,
                'notes' => $notes,
            ]);
            $item->user_id = $user;
            $item->save();
        }
    }

    private function goals(): void
    {
        $user = User::where('email', 'admin@rbel-crm.test')->value('id');
        $year = $this->today->year;

        // current_amount is derived from policy APE on save (GoalProgressService).
        $goals = [
            ['title' => "{$year} Annual APE target", 'start_date' => "{$year}-01-01", 'target_amount' => 6000000, 'target_date' => "{$year}-12-31", 'status' => 'in_progress'],
            ['title' => 'Q4 APE sprint', 'start_date' => "{$year}-10-01", 'target_amount' => 1500000, 'target_date' => "{$year}-12-15", 'status' => 'in_progress'],
            ['title' => 'H1 next year', 'start_date' => ($year + 1).'-01-01', 'target_amount' => 3000000, 'target_date' => ($year + 1).'-06-30', 'status' => 'not_started'],
        ];

        foreach ($goals as $goal) {
            Goal::create($goal + [
                'user_id' => $user,
                'description' => 'Tracked in RBEL-CRM.',
            ]);
        }
    }

    private function reminders(): void
    {
        $user = User::where('email', 'admin@rbel-crm.test')->value('id');
        $policies = Policy::with('owner:id,first_name')->whereIn('status', ['active', 'pending'])->inRandomOrder()->limit(30)->get();

        foreach ($policies as $i => $policy) {
            $type = $this->pick(['follow_up', 'payment', 'delivery', 'follow_up', 'other']);
            $offset = $i < 4 ? 0 : mt_rand(-10, 20);

            Reminder::create([
                'user_id' => $user,
                'client_id' => $policy->policy_owner_id,
                'policy_id' => $policy->id,
                'type' => $type,
                'title' => match ($type) {
                    'payment' => "Collect premium for {$policy->policy_number}",
                    'delivery' => "Deliver policy contract {$policy->policy_number}",
                    'follow_up' => "Follow up with {$policy->owner->first_name} on rider upgrade",
                    default => 'Update client KYC documents',
                },
                'due_date' => $this->today->addDays($offset)->toDateString(),
                'completed_at' => $offset < -5 && mt_rand(0, 1) ? $this->today->addDays($offset + 1) : null,
            ]);
        }
    }

    private function emailTemplates(): void
    {
        $user = User::where('email', 'admin@rbel-crm.test')->value('id');

        $templates = [
            ['Birthday Greeting', 'Happy birthday, {{first_name}}! 🎉', "Dear {{first_name}},\n\nWishing you a very happy birthday! Thank you for continuing to trust us with your family's protection.\n\nMay this year bring you good health and prosperity.\n\nWarm regards,\n{{advisor_name}}", 'active'],
            ['Policy Anniversary', 'Your policy {{policy_number}} anniversary', "Dear {{first_name}},\n\nYour {{product}} policy ({{policy_number}}), issued on {{issued_date}}, is {{policy_years}} year(s) old today. Happy policy anniversary!\n\nPolicy owner: {{policy_owner}}\nInsured: {{policy_insured}}\nSum assured: {{sum_assured}}\n\nLet's schedule a quick review to make sure your coverage still fits your goals.\n\n{{advisor_name}}", 'active'],
            ['Premium Due Reminder', 'Premium reminder for policy {{policy_number}}', "Hi {{first_name}},\n\nThis is a friendly reminder that the premium for your {{product}} policy ({{policy_number}}) is due soon. Keeping your premiums up to date keeps {{policy_insured}}'s coverage in force.\n\nThank you,\n{{advisor_name}}", 'active'],
            ['Policy Delivery', 'Your policy contract {{policy_number}} is ready', "Dear {{first_name}},\n\nGood news! The policy contract for {{policy_number}} ({{product}}) is ready for delivery. I will contact you to arrange a convenient schedule.\n\n{{advisor_name}}", 'active'],
            ['Welcome New Client', 'Welcome, {{first_name}}!', "Dear {{full_name}},\n\nWelcome! I'm glad to be your financial advisor. Feel free to reach out anytime you have questions about your plans.\n\n{{advisor_name}}", 'active'],
            ['Year-end Review Invitation', 'Let\'s review your plans before year-end', "Hi {{first_name}},\n\nAs {{today}} approaches year-end, it's a great time to review your protection and savings goals together.\n\n{{advisor_name}}", 'draft'],
        ];

        foreach ($templates as [$name, $subject, $body, $status]) {
            EmailTemplate::create(compact('name', 'subject', 'body', 'status') + ['created_by' => $user]);
        }
    }

    private function emailLogs(): void
    {
        $user = User::where('email', 'admin@rbel-crm.test')->value('id');
        $templates = EmailTemplate::where('status', 'active')->get();
        $clients = Client::whereNotNull('email')->inRandomOrder()->limit(24)->get();

        foreach ($clients as $i => $client) {
            $template = $templates[$i % $templates->count()];
            $status = $i % 9 === 0 ? 'failed' : 'sent';
            $at = $this->today->subDays(mt_rand(0, 60))->setTime(mt_rand(8, 18), mt_rand(0, 59));

            EmailLog::create([
                'email_template_id' => $template->id,
                'user_id' => $user,
                'client_id' => $client->id,
                'recipient' => $client->email,
                'subject' => str_replace('{{first_name}}', $client->first_name, $template->subject),
                'status' => $status,
                'error_message' => $status === 'failed' ? 'Mailbox unavailable (550). Recipient address rejected.' : null,
                'sent_at' => $status === 'sent' ? $at : null,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
    }

    /** A realistic trail of past actions so the Audit Log page has history. */
    private function auditLogs(): void
    {
        $users = User::pluck('id')->all();
        $policies = Policy::inRandomOrder()->limit(25)->get(['id', 'policy_number', 'status']);
        $clients = Client::inRandomOrder()->limit(15)->get(['id', 'first_name', 'last_name', 'mobile_number']);

        foreach ($policies as $i => $policy) {
            AuditLog::create([
                'user_id' => $users[$i % count($users)],
                'action' => $i % 5 === 0 ? 'uploaded_document' : ($i % 3 === 0 ? 'created' : 'updated'),
                'module' => 'policies',
                'record_id' => $policy->id,
                'description' => $i % 5 === 0 ? "Uploaded coverage document for policy {$policy->policy_number}" : null,
                'old_values' => $i % 3 === 0 || $i % 5 === 0 ? null : ['status' => 'pending'],
                'new_values' => $i % 5 === 0 ? ['file' => 'coverage.pdf'] : ['status' => $policy->status],
                'ip_address' => '127.0.0.1',
                'created_at' => $this->today->subDays(mt_rand(0, 30))->setTime(mt_rand(8, 18), mt_rand(0, 59)),
            ]);
        }

        foreach ($clients as $i => $client) {
            AuditLog::create([
                'user_id' => $users[$i % count($users)],
                'action' => $i % 4 === 0 ? 'created' : 'updated',
                'module' => 'clients',
                'record_id' => $client->id,
                'old_values' => $i % 4 === 0 ? null : ['mobile_number' => '+63 900 000 0000'],
                'new_values' => $i % 4 === 0 ? ['first_name' => $client->first_name, 'last_name' => $client->last_name] : ['mobile_number' => $client->mobile_number],
                'ip_address' => '127.0.0.1',
                'created_at' => $this->today->subDays(mt_rand(0, 30))->setTime(mt_rand(8, 18), mt_rand(0, 59)),
            ]);
        }
    }

    private function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }
}
