<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Goal;
use App\Models\Client;
use Tests\TestCase;

class AuditAndAuthorizationTest extends TestCase
{
    public function test_crud_actions_are_audited_with_json_values(): void
    {
        $user = $this->signIn('admin');

        $id = $this->postJson('/api/clients', ['first_name' => 'Audit', 'last_name' => 'Me'])->json('data.id');
        $goal = Goal::factory()->create();
        $goal->update(['title' => 'Renamed goal']);

        $created = AuditLog::where(['module' => 'clients', 'action' => 'created', 'record_id' => $id])->firstOrFail();
        $this->assertSame($user->id, $created->user_id);
        $this->assertSame('Audit', $created->new_values['first_name']);
        $this->assertArrayNotHasKey('full_name', $created->new_values); // generated columns excluded

        $this->assertTrue(AuditLog::where(['module' => 'goals', 'action' => 'updated', 'record_id' => $goal->id])->exists());

        $this->getJson('/api/audit-logs?module=clients')->assertOk()->assertJsonPath('data.0.user.id', $user->id);
        $this->getJson('/api/audit-logs/facets')->assertOk()->assertJsonStructure(['data' => ['modules', 'actions']]);
    }

    public function test_only_admins_can_view_audit_logs(): void
    {
        $this->signIn('advisor');
        $this->getJson('/api/audit-logs')->assertForbidden();

        $this->signIn('assistant');
        $this->getJson('/api/audit-logs')->assertForbidden();
    }

    public function test_role_permissions_are_exposed_to_the_spa(): void
    {
        $this->signIn('assistant');
        $this->getJson('/api/user')->assertJsonPath('data.permissions.manage', false)->assertJsonPath('data.permissions.admin', false);

        $this->signIn('admin');
        $this->getJson('/api/user')->assertJsonPath('data.permissions.manage', true)->assertJsonPath('data.permissions.admin', true);
    }

    public function test_search_input_is_treated_as_data_not_sql(): void
    {
        $this->signIn();
        Client::factory()->create(['first_name' => "O'Brien"]);

        $this->getJson('/api/clients?search='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/clients?search='.urlencode("O'Brien"))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/clients?search=%25')->assertOk()->assertJsonCount(0, 'data'); // literal %, not wildcard
    }

    public function test_meta_endpoint(): void
    {
        $this->signIn();

        $this->getJson('/api/meta')->assertOk()->assertJsonStructure(['data' => ['products', 'policy_statuses', 'payment_modes', 'years', 'placeholders']]);
    }
}
