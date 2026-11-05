<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Approbations\Models\ApprovalRequest;
use Tests\TestCase;

/** Fonctions créées par l'entreprise, organigramme, responsable direct, modules choisis à la création et approbation hiérarchique. */
class FonctionsHierarchieTest extends TestCase
{
    public function test_functions_hierarchy_modules_and_manager_approval(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Hiérarchie SARL');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();

        // Fonctions : Directeur commercial > Chef d'équipe vente
        $dir = $api()->postJson('/api/roles', ['name' => 'Directeur commercial', 'permissions' => ['ventes.view', 'ventes.manage', 'crm.view'], 'color' => '#1F6FEB', 'description' => 'Pilote les ventes'])->assertCreated()->json('data');
        $chef = $api()->postJson('/api/roles', ['name' => 'Chef d\'équipe vente', 'parent_id' => $dir['id'], 'permissions' => ['ventes.view']])->assertCreated()->json('data');
        $api()->postJson('/api/roles', ['name' => 'Directeur commercial', 'permissions' => []])->assertStatus(422); // nom en double
        $api()->putJson('/api/roles/'.$dir['id'], ['parent_id' => $chef['id']])->assertStatus(422);                 // boucle
        $api()->putJson('/api/roles/'.$chef['id'], ['parent_id' => 999999])->assertStatus(422);

        $meta = $api()->getJson('/api/users/form-meta')->assertOk()->json();
        $chefMeta = collect($meta['roles'])->firstWhere('id', $chef['id']);
        $this->assertSame($dir['id'], $chefMeta['parent_id']);
        $this->assertContains('ventes', $chefMeta['modules']);
        $this->assertNotEmpty($meta['modules']);

        // Utilisateurs : la directrice, puis un chef d'équipe rattaché à elle, avec le CRM en plus et sans Ventes
        $awa = $api()->postJson('/api/users', ['name' => 'Awa Directrice', 'email' => 'awa@hier.ci', 'password' => 'secret123', 'role_id' => $dir['id']])->assertCreated()->json('data');
        $kofi = $api()->postJson('/api/users', ['name' => 'Kofi Chef', 'email' => 'kofi@hier.ci', 'password' => 'secret123', 'role_id' => $chef['id'],
            'manager_id' => $awa['id'], 'job_title' => 'Chef de rayon Cocody', 'modules' => ['crm']])->assertCreated()->json('data');
        $this->assertSame([$awa['id'], 'Chef de rayon Cocody'], [$kofi['manager_id'], $kofi['job_title']]);
        $k = User::find($kofi['id']);
        $this->assertTrue($k->hasPermission('crm.view'));    // module ajouté pour lui
        $this->assertFalse($k->hasPermission('ventes.view')); // module de sa fonction décoché
        // pas de boucle hiérarchique
        $api()->putJson('/api/users/'.$awa['id'], ['manager_id' => $kofi['id']])->assertStatus(422);

        // Circuit : « responsable hiérarchique » ; une demande de Kofi va à Awa
        $push = fn (string $entity, array $data, ?string $id = null) => $api()->postJson('/api/sync/push', ['mutations' => [[
            'id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data,
        ]]])->assertOk()->json('results.0');
        $this->assertSame('applied', $push('wf_rules', ['name' => 'Remises', 'targets' => ['sale.quote'], 'discount_over' => 5, 'steps' => [['name' => 'Responsable', 'manager' => true]]])['status']);
        $svc = app(\App\Modules\Approbations\ApprovalService::class);
        $this->assertSame([$awa['id']], $svc->managersOf($kofi['id'], $company->id));
        // sans responsable direct : titulaires de la fonction au-dessus
        User::find($kofi['id'])->forceFill(['manager_id' => null])->save();
        $this->assertSame([$awa['id']], $svc->managersOf($kofi['id'], $company->id));

        // Suppression d'une fonction : les fonctions rattachées remontent
        $sub = $api()->postJson('/api/roles', ['name' => 'Stagiaire vente', 'parent_id' => $chef['id'], 'permissions' => []])->json('data');
        User::find($kofi['id'])->forceFill(['role_id' => $dir['id']])->save();
        $api()->deleteJson('/api/roles/'.$chef['id'])->assertOk();
        $this->assertSame($dir['id'], Role::find($sub['id'])->parent_id);
    }
}
