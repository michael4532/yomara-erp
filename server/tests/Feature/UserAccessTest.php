<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Accès par utilisateur : modules masqués, droits ajoutés ou retirés (supprimer, valider), envoi depuis sa boîte mail. */
class UserAccessTest extends TestCase
{
    public function test_per_user_modules_and_permissions(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Droits SARL');
        $api = fn () => $this->api($token);
        $com = User::create(['company_id' => $company->id, 'name' => 'Ali Commercial', 'email' => 'ali@droits.ci', 'password' => 'secret123',
            'role_id' => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Commercial')->value('id')]);
        $capi = fn () => $this->api($com->createToken('c')->plainTextToken);
        $partner = $api()->postJson('/api/core/partners', ['name' => 'Client A', 'type' => 'customer'])->assertCreated()->json('data.id');

        // Le commercial crée et modifie, valide (droit par défaut) mais ne supprime pas
        $draft = $capi()->postJson('/api/ventes/documents', ['type' => 'quote', 'partner_id' => $partner, 'date' => now()->toDateString(), 'status' => 'draft',
            'lines' => [['id' => $this->uuid(), 'description' => 'Prestation', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 0]]])->assertCreated()->json('data.id');
        $capi()->deleteJson("/api/ventes/documents/$draft")->assertForbidden();
        $acc = $api()->getJson("/api/users/{$com->id}/access")->assertOk()->json();
        $ventes = collect($acc['modules'])->firstWhere('key', 'ventes');
        $this->assertTrue($ventes['free'] === false || $ventes['free'] === true);
        $perm = collect($ventes['permissions'])->keyBy('key');
        $this->assertEquals([true, false, true], [$perm['ventes.manage']['effective'], $perm['ventes.delete']['effective'], $perm['ventes.validate']['from_role']]);

        // L'administrateur lui donne le droit de supprimer, lui retire la validation et masque le CRM
        $api()->putJson("/api/users/{$com->id}/access", ['modules_denied' => ['crm'], 'grants' => ['ventes.delete'], 'denies' => ['ventes.validate']])->assertOk();
        $capi()->putJson("/api/ventes/documents/$draft", ['status' => 'validated'])->assertStatus(422)->assertJsonFragment(['message' => 'Vous n\'avez pas le droit de valider ce document : enregistrez-le en brouillon, une personne habilitée le validera.']);
        $capi()->deleteJson("/api/ventes/documents/$draft")->assertSuccessful();
        $boot = $capi()->getJson('/api/bootstrap')->assertOk()->json();
        $this->assertNotContains('crm', $boot['enabled_modules']);
        $this->assertContains('crm.manage', $boot['user']['denied_permissions']);
        $this->assertContains('ventes.validate', $boot['user']['denied_permissions']);
        $capi()->getJson('/api/crm/stages')->assertForbidden();
        $this->assertTrue($api()->getJson('/api/users')->json('data') !== null);
        $this->assertTrue(collect($api()->getJson('/api/users')->json('data'))->firstWhere('id', $com->id)['custom_access']);

        // Hors ligne : la suppression envoyée par la synchronisation suit la même règle
        $api()->putJson("/api/users/{$com->id}/access", ['modules_denied' => [], 'grants' => [], 'denies' => []])->assertOk();
        $d2 = $capi()->postJson('/api/ventes/documents', ['type' => 'quote', 'partner_id' => $partner, 'date' => now()->toDateString(), 'status' => 'draft',
            'lines' => [['id' => $this->uuid(), 'description' => 'X', 'quantity' => 1, 'unit_price' => 1, 'tax_rate' => 0]]])->json('data.id');
        $res = $capi()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'sales_documents', 'record_id' => $d2, 'op' => 'delete']]])->assertOk()->json('results.0');
        $this->assertSame('rejected', $res['status']);
        $this->assertNull(DB::table('trade_documents')->where('id', $d2)->value('deleted_at'));
        $api()->putJson("/api/users/{$admin->id}/access", ['modules_denied' => [], 'grants' => [], 'denies' => []])->assertStatus(422); // pas soi-même
    }
}
