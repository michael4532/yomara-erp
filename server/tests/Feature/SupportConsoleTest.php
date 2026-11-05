<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformActivity;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupportConsoleTest extends TestCase
{
    public function test_support_chat_between_company_and_editor_and_console_analytics(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Support SARL');
        $api = fn () => $this->api($token);
        $role = \App\Models\Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Commercial')->value('id');
        $bob = User::create(['company_id' => $company->id, 'name' => 'Bob', 'email' => 'bob@sup.ci', 'password' => 'secret123', 'role_id' => $role]);
        $bobToken = $bob->createToken('t')->plainTextToken;

        // Bob n'est pas désigné : pas d'accès ; l'administrateur le désigne
        $this->api($bobToken)->getJson('/api/support/status')->assertOk()->assertJson(['allowed' => false]);
        $this->api($bobToken)->postJson('/api/support/conversations', ['subject' => 'x', 'body' => 'y'])->assertForbidden();
        $this->api($bobToken)->putJson('/api/support/contacts', ['user_ids' => [$bob->id]])->assertForbidden();
        $api()->putJson('/api/support/contacts', ['user_ids' => [$bob->id, 999999]])->assertOk()->assertJson(['contacts' => [$bob->id]]);
        $this->api($bobToken)->getJson('/api/support/status')->assertJson(['allowed' => true, 'unread' => 0]);

        $c = $this->api($bobToken)->postJson('/api/support/conversations', ['subject' => 'Facture bloquée', 'body' => 'La facture <b>FA-12</b> ne part pas', 'category' => 'bug'])
            ->assertCreated()->json();
        $id = $c['data']['id'];
        $this->assertSame('La facture FA-12 ne part pas', $c['messages'][0]['body']);

        // L'éditeur voit la conversation et répond
        [, $root, $rootToken] = $this->makeCompany('Editeur', superAdmin: true);
        $this->api($rootToken)->getJson('/api/admin/support/conversations?status=active')->assertOk()->assertJsonPath('data.0.unread', 1)->assertJsonPath('data.0.company', 'Support SARL');
        $this->api($bobToken)->getJson('/api/admin/support/conversations')->assertForbidden();
        $this->api($rootToken)->getJson("/api/admin/support/conversations/$id")->assertOk()->assertJsonPath('data.unread', 0);
        $this->api($rootToken)->postJson("/api/admin/support/conversations/$id/messages", ['body' => 'Corrigé, réessayez.'])->assertOk()->assertJsonPath('data.status', 'waiting');

        // La société reçoit la réponse (badge), la lit, clôt avec une note
        $this->api($bobToken)->getJson('/api/support/status')->assertJson(['unread' => 1]);
        $m = $this->api($bobToken)->getJson("/api/support/conversations/$id?after=".$c['messages'][0]['id'])->assertOk()->json('messages');
        $this->assertSame([true, 'Corrigé, réessayez.', 'Support Yomara'], [$m[0]['from_platform'], $m[0]['body'], $m[0]['author']]);
        $this->api($bobToken)->getJson('/api/support/status')->assertJson(['unread' => 0]);
        $this->api($bobToken)->postJson("/api/support/conversations/$id/close", ['rating' => 5])->assertOk()->assertJsonPath('data.status', 'closed');

        // Une autre société ne voit pas cette conversation
        [, , $otherToken] = $this->makeCompany('Autre SA');
        $this->api($otherToken)->getJson("/api/support/conversations/$id")->assertNotFound();

        // Console éditeur : activité du jour, connectés, ventes
        $api()->postJson('/api/presence/ping')->assertOk();
        $this->assertSame(1, DB::table('platform_activity')->where('user_id', $admin->id)->where('day', now()->toDateString())->count());
        PlatformActivity::sample();
        $a = $this->api($rootToken)->getJson('/api/admin/analytics?days=7')->assertOk()->json();
        $this->assertGreaterThanOrEqual(1, $a['now']['users']);
        $this->assertGreaterThanOrEqual(1, $a['today']['users']);
        $this->assertCount(7, $a['daily']);
        $this->assertCount(12, $a['monthly']);
        $this->assertSame(0, $a['support']['open']);
        $this->api($token)->getJson('/api/admin/analytics')->assertForbidden();
    }
}
