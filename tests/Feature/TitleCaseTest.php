<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Support\TitleCase;
use Tests\TestCase;

/** Names, occupations and addresses are saved in Title Case. */
class TitleCaseTest extends TestCase
{
    public function test_title_case_rules(): void
    {
        $this->assertSame('Juan Dela Cruz', TitleCase::apply('juan DELA cruz'));
        $this->assertSame('Santos-Reyes', TitleCase::apply('santos-reyes'));
        $this->assertSame("O'Neil", TitleCase::apply("o'neil"));
        $this->assertSame('Ma. Cristina', TitleCase::apply('MA. CRISTINA'));
        $this->assertSame('Peña', TitleCase::apply('PEÑA'));
        $this->assertSame('Juan Dela Cruz III', TitleCase::apply('juan dela cruz iii'));
        $this->assertSame('Blk 143 Lot 22 Ph 4-D Narra Dr.', TitleCase::apply('blk 143 lot 22 ph 4-d narra dr.'));
        $this->assertNull(TitleCase::apply(null));
    }

    public function test_clients_are_saved_in_title_case_on_create_and_edit(): void
    {
        $this->signIn();

        $id = $this->postJson('/api/clients', [
            'first_name' => 'carmela', 'middle_name' => 'REYES', 'last_name' => 'dela cruz',
            'occupation' => 'registered nurse', 'address' => '12 mabini st., pasig city', 'email' => 'Carmela@Example.test',
        ])->assertCreated()
            ->assertJsonPath('data.first_name', 'Carmela')
            ->assertJsonPath('data.middle_name', 'Reyes')
            ->assertJsonPath('data.last_name', 'Dela Cruz')
            ->assertJsonPath('data.occupation', 'Registered Nurse')
            ->assertJsonPath('data.address', '12 Mabini St., Pasig City')
            ->assertJsonPath('data.email', 'carmela@example.test') // email: lower case
            ->json('data.id');

        $this->putJson("/api/clients/{$id}", ['first_name' => 'CARMELA', 'last_name' => 'garcia'])
            ->assertOk()->assertJsonPath('data.last_name', 'Garcia')->assertJsonPath('data.first_name', 'Carmela');

        // The audit trail records the formatted values.
        $this->assertSame('Dela Cruz', AuditLog::where(['module' => 'clients', 'action' => 'created', 'record_id' => $id])->value('new_values')['last_name']);
    }

    public function test_leads_and_beneficiaries_are_saved_in_title_case(): void
    {
        $this->signIn();
        ['a' => $policy] = $this->ownerInsuredScenario();

        $this->postJson('/api/leads', ['first_name' => 'lara', 'last_name' => 'AQUINO', 'occupation' => 'architect', 'email' => 'Lara.Aquino@Example.TEST'])
            ->assertCreated()->assertJsonPath('data.first_name', 'Lara')->assertJsonPath('data.last_name', 'Aquino')->assertJsonPath('data.occupation', 'Architect')
            ->assertJsonPath('data.email', 'lara.aquino@example.test');

        $this->postJson("/api/policies/{$policy->id}/beneficiaries", [
            'first_name' => 'ana', 'last_name' => 'santos-reyes', 'email' => 'ANA@EXAMPLE.TEST', 'relationship' => 'child', 'allocation_percentage' => 100,
        ])->assertCreated()->assertJsonPath('data.first_name', 'Ana')->assertJsonPath('data.last_name', 'Santos-Reyes')->assertJsonPath('data.email', 'ana@example.test');
    }

    public function test_owner_and_insured_typed_on_the_policy_form_are_title_cased(): void
    {
        $this->signIn();
        $contact = ['birthdate' => '1990-05-20', 'email' => 'p@example.test', 'mobile_number' => '+63 917 555 0000', 'address' => '1 rizal ave., manila'];

        $data = $this->postJson('/api/policies', [
            'policy_number' => 'RB-TC-0001', 'product_id' => $this->product()->id, 'ape' => 1000, 'sum_assured' => 100000,
            'issued_date' => now()->subDay()->toDateString(), 'mode_of_payment' => 'annual', 'status' => 'active',
            'policy_owner' => ['first_name' => 'rosa', 'last_name' => 'VILLANUEVA'] + $contact,
            'policy_insured' => ['first_name' => 'ben', 'last_name' => 'villanueva'] + $contact,
        ])->assertCreated()->json('data');

        $owner = Client::findOrFail($data['policy_owner']['id']);
        $this->assertSame(['Rosa', 'Villanueva', '1 Rizal Ave., Manila'], [$owner->first_name, $owner->last_name, $owner->address]);
        $this->assertSame('Ben', Client::find($data['policy_insured']['id'])->first_name);
    }
}
