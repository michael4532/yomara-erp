<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Audit du 5 novembre 2026 : chaque test rejoue l'attaque et vérifie qu'elle ne passe plus. */
class SecurityAudit2Test extends TestCase
{
    public function test_sync_push_does_not_return_records_the_user_cannot_see(): void
    {
        [$company, , $token] = $this->makeCompany('Boutique Sync');
        $admin = $this->api($token);
        $admin->getJson('/api/sync/pull?cursor=0')->assertOk();
        $emp = $this->uuid();
        $admin->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'op' => 'upsert', 'entity' => 'rh_employees', 'record_id' => $emp,
            'data' => ['first_name' => 'Awa', 'last_name' => 'K', 'base_salary' => 950000, 'hire_date' => '2025-01-01']]]])->assertOk();
        $roleId = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Caissier')->value('id')
            ?? Role::create(['company_id' => $company->id, 'name' => 'Caisse seule', 'permissions' => ['pos.use']])->id;
        $cashier = User::create(['company_id' => $company->id, 'role_id' => $roleId, 'name' => 'Caissier', 'email' => 'caisse@sync.ci', 'password' => 'secret123', 'is_active' => true]);
        $c = $this->api($cashier->createToken('t')->plainTextToken);
        $c->getJson('/api/rh/employees')->assertForbidden();

        $mid = $this->uuid();
        $r = $c->postJson('/api/sync/push', ['mutations' => [['id' => $mid, 'op' => 'upsert', 'entity' => 'rh_employees', 'record_id' => $emp, 'data' => []]]])->assertOk()->json('results.0');
        $this->assertSame('rejected', $r['status']);
        $this->assertNull($r['record']);
        $this->assertFalse($r['deleted']);
        $this->assertStringNotContainsString('950000', json_encode($r));
        // même identifiant renvoyé (rejeu) : toujours rien
        $r = $c->postJson('/api/sync/push', ['mutations' => [['id' => $mid, 'op' => 'upsert', 'entity' => 'rh_employees', 'record_id' => $emp, 'data' => []]]])->assertOk()->json('results.0');
        $this->assertNull($r['record']);
        // identifiant de modification d'un autre utilisateur : refusé sans données
        $r = $admin->postJson('/api/sync/push', ['mutations' => [['id' => $mid, 'op' => 'upsert', 'entity' => 'rh_employees', 'record_id' => $emp, 'data' => []]]])->assertOk()->json('results.0');
        $this->assertSame('rejected', $r['status']);
        // identifiant déjà pris par une autre société : refus propre, pas d'erreur 500
        [, , $token2] = $this->makeCompany('Autre Sync');
        $this->api($token2)->postJson('/api/sync/push', ['mutations' => [['id' => $mid, 'op' => 'upsert', 'entity' => 'partners', 'record_id' => $this->uuid(), 'data' => ['name' => 'X', 'type' => 'customer']]]])
            ->assertOk()->assertJsonPath('results.0.status', 'rejected');
    }

    public function test_public_page_links_cannot_smuggle_javascript_through_variables(): void
    {
        [, , $token] = $this->makeCompany('Agence Pages');
        $this->api($token)->postJson('/api/marketing/forms', ['kind' => 'page', 'name' => 'P', 'slug' => 'promo-x', 'active' => true, 'blocks' => [
            ['type' => 'button', 'label' => 'Go', 'url' => '{{prenom}}javascript:alert(document.domain)'],
            ['type' => 'html', 'html' => '<a href="{{nom}}javascript:alert(1)">x</a><a href="{{lien_desinscription}}">ok</a>'],
            ['type' => 'text', 'text' => '[clic]({{prenom}}javascript:alert(2))'],
        ]])->assertSuccessful();
        $html = $this->get('/f/promo-x')->assertOk()->getContent();
        // aucun lien cliquable vers javascript: (le texte brut affiché reste inoffensif)
        $this->assertDoesNotMatchRegularExpression('/(href|src|action)\s*=\s*["\']?\s*javascript:/i', $html);
    }

    public function test_store_demo_and_own_credentials_cannot_be_changed_with_a_token_alone(): void
    {
        [, , $token] = $this->makeCompany('Éditeur 2', 'entreprise', true);
        $pw = $this->api($token)->postJson('/api/admin/store-demo', ['email' => 'demo2@exemple.ci'])->assertCreated()->json('data.password');
        $demo = User::where('email', 'demo2@exemple.ci')->first();
        $d = $this->api($this->postJson('/api/auth/login', ['email' => 'demo2@exemple.ci', 'password' => $pw])->json('token'));
        $d->putJson('/api/users/'.$demo->id, ['name' => 'Compte démo', 'email' => 'pirate@exemple.ci'])->assertStatus(422);
        $d->putJson('/api/users/'.$demo->id, ['name' => 'Compte démo', 'email' => 'demo2@exemple.ci', 'password' => 'Pirate12345'])->assertStatus(422);
        $this->assertSame('demo2@exemple.ci', $demo->fresh()->email);
        $this->postJson('/api/auth/login', ['email' => 'demo2@exemple.ci', 'password' => $pw])->assertOk();

        // tout utilisateur : son propre e-mail exige le mot de passe actuel
        [$c, $owner, $t2] = $this->makeCompany('Cabinet Id');
        $me = $this->api($t2);
        $me->putJson('/api/users/'.$owner->id, ['name' => $owner->name, 'email' => 'vole@exemple.ci'])->assertStatus(422);
        $me->putJson('/api/users/'.$owner->id, ['name' => $owner->name, 'email' => 'nouveau@exemple.ci', 'current_password' => 'secret123'])->assertOk();
        $me->putJson('/api/users/'.$owner->id, ['name' => 'Nom modifié', 'email' => 'nouveau@exemple.ci'])->assertOk();
    }

    public function test_api_keys_cannot_touch_payment_and_messaging_settings(): void
    {
        [, $owner, ] = $this->makeCompany('Boutique Clé');
        $key = $this->api($owner->createToken('api:compta', ['read', 'write'])->plainTextToken);
        $key->postJson('/api/payments/gateways', ['provider' => 'stripe'])->assertForbidden();
        $key->putJson('/api/messaging/channels/email', ['provider' => 'smtp'])->assertForbidden();
        $key->putJson('/api/company', ['name' => 'Détournée'])->assertForbidden();
        $key->postJson('/api/me/delete-account', ['password' => 'x', 'scope' => 'user'])->assertForbidden();
        $key->getJson('/api/company')->assertOk();
    }
}
