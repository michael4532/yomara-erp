<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Parcours complet d'une NOUVELLE société : inscription, activation des modules par l'éditeur,
 * création d'un utilisateur par département avec son rôle dédié, puis une action métier réelle par département
 * et les contrôles d'accès (chacun voit son périmètre, pas celui des autres).
 */
class DepartmentJourneysTest extends TestCase
{
    protected string $owner;

    protected array $tok = [];

    protected function push(string $token, array $muts): array
    {
        $r = $this->api($token)->postJson('/api/sync/push', ['mutations' => array_map(fn ($m) => ['id' => $this->uuid(), 'op' => 'upsert'] + $m, $muts)])->assertOk()->json('results');
        foreach ($r as $x) {
            $this->assertSame('applied', $x['status'], json_encode($x));
        }

        return $r;
    }

    public function test_new_company_with_one_user_per_department(): void
    {
        // 1. Inscription d'une nouvelle société (comme un vrai client, en mode SaaS)
        config(['erp.mode' => 'saas']);
        $reg = $this->postJson('/api/auth/register', ['company_name' => 'Nouvelle Entreprise SA', 'name' => 'Koné Directeur', 'email' => 'dg@nouvelle.ci', 'password' => 'secret1234'])
            ->assertCreated()->json();
        $this->owner = $reg['token'];
        $company = Company::where('name', 'Nouvelle Entreprise SA')->firstOrFail();

        // 2. L'éditeur active tous les modules de la société (dépendances respectées)
        [, , $root] = $this->makeCompany('Éditeur', superAdmin: true);
        $keys = Module::where('is_core', false)->where('is_available', true)->pluck('key')->all();
        for ($pass = 0; $pass < 6 && $keys; $pass++) {
            foreach ($keys as $i => $k) {
                $st = $this->api($root)->putJson("/api/admin/companies/{$company->id}/modules/$k", ['enabled' => true])->status();
                if ($st === 200) {
                    unset($keys[$i]);
                }
            }
        }
        $this->assertSame([], array_values($keys), 'Modules non activables : '.implode(', ', $keys));
        app(\App\Support\ModuleRegistry::class)->flush();
        $this->assertGreaterThanOrEqual(80, count($company->fresh()->enabledModules()));

        // Formule Starter limitée à 3 utilisateurs : l'éditeur passe la société en formule Entreprise
        $this->api($this->owner)->postJson('/api/users', ['name' => 'A', 'email' => 'a1@nouvelle.ci', 'password' => 'secret1234']);
        $this->api($this->owner)->postJson('/api/users', ['name' => 'B', 'email' => 'a2@nouvelle.ci', 'password' => 'secret1234']);
        $this->api($this->owner)->postJson('/api/users', ['name' => 'C', 'email' => 'a3@nouvelle.ci', 'password' => 'secret1234'])->assertStatus(422);
        $plan = \App\Models\Plan::where('code', 'entreprise')->value('id');
        $this->api($root)->putJson("/api/admin/companies/{$company->id}", ['plan_id' => $plan, 'status' => 'active'])->assertOk();

        // 3. Un utilisateur par département, avec son rôle dédié
        $roles = collect($this->api($this->owner)->getJson('/api/roles')->assertOk()->json('data'))->keyBy('name');
        $people = [
            'direction' => 'Direction', 'ventes' => 'Commercial', 'caisse' => 'Caissier', 'achats' => 'Acheteur', 'stock' => 'Magasinier',
            'finance' => 'Comptable', 'rh' => 'RH / Paie', 'sav' => 'Responsable SAV', 'projets' => 'Chef de projet', 'marketing' => 'Marketing & communication',
            'juridique' => 'Juriste', 'salarie' => 'Salarié',
        ];
        foreach ($people as $k => $role) {
            $this->assertTrue($roles->has($role), "Rôle manquant : $role");
            $this->api($this->owner)->postJson('/api/users', ['name' => ucfirst($k).' Test', 'email' => "$k@nouvelle.ci", 'password' => 'secret1234', 'role_id' => $roles[$role]['id']])
                ->assertCreated()->assertJsonPath('data.is_active', true);
            $this->tok[$k] = $this->postJson('/api/auth/login', ['email' => "$k@nouvelle.ci", 'password' => 'secret1234', 'device_name' => 'test'])->assertOk()->json('token');
            // Chaque personne ouvre l'ERP : tableau de bord et données de démarrage
            $this->api($this->tok[$k])->getJson('/api/bootstrap')->assertOk();
            $this->api($this->tok[$k])->getJson('/api/sync/pull?cursor=0&limit=200')->assertOk();
        }

        // Paramétrage de base par le dirigeant : client, produit, entrepôt, caisse
        $wh = $this->api($this->owner)->postJson('/api/stock/warehouses', ['name' => 'Magasin central', 'is_default' => true])->assertCreated()->json('data.id');
        $product = $this->api($this->owner)->postJson('/api/core/products', ['name' => 'Ordinateur portable', 'sale_price' => 450000, 'purchase_price' => 320000, 'tax_rate' => 18])->assertCreated()->json('data.id');
        $register = $this->api($this->owner)->postJson('/api/pos/registers', ['name' => 'Caisse 1', 'code' => 'C1', 'warehouse_id' => $wh])->assertCreated()->json('data.id');

        // 4. Achats : fournisseur et facture fournisseur
        $sup = $this->uuid();
        $this->push($this->tok['achats'], [
            ['entity' => 'partners', 'record_id' => $sup, 'data' => ['name' => 'Tech Distribution CI', 'type' => 'supplier']],
            ['entity' => 'purchase_documents', 'record_id' => $this->uuid(), 'data' => ['type' => 'supplier_invoice', 'partner_id' => $sup, 'date' => now()->toDateString(), 'status' => 'validated',
                'lines' => [['product_id' => $product, 'description' => 'Ordinateurs', 'quantity' => 10, 'unit_price' => 320000, 'tax_rate' => 18]]]],
        ]);

        // Stock : entrée de marchandises par le magasinier
        $this->api($this->tok['stock'])->postJson('/api/stock/moves', ['product_id' => $product, 'warehouse_id' => $wh, 'quantity' => 20, 'type' => 'initial', 'date' => now()->toDateString()])->assertCreated();

        // Ventes : client et facture validée par le commercial
        $cli = $this->uuid();
        $inv = $this->push($this->tok['ventes'], [
            ['entity' => 'partners', 'record_id' => $cli, 'data' => ['name' => 'Banque Atlantique', 'type' => 'customer', 'email' => 'achats@banque.ci']],
            ['entity' => 'sales_documents', 'record_id' => $this->uuid(), 'data' => ['type' => 'invoice', 'partner_id' => $cli, 'date' => now()->toDateString(), 'status' => 'validated',
                'lines' => [['product_id' => $product, 'description' => 'Ordinateur portable', 'quantity' => 2, 'unit_price' => 450000, 'tax_rate' => 18]]]],
        ])[1]['record'];
        $this->assertStringStartsWith('FAC-', $inv['number']);
        $this->assertEquals(1062000, $inv['total']);

        // Caisse : session, ticket payé en espèces, clôture
        $session = $this->uuid();
        $doc = $this->uuid();
        $this->push($this->tok['caisse'], [
            ['entity' => 'pos_sessions', 'record_id' => $session, 'data' => ['register_id' => $register, 'opening_cash' => 10000, 'status' => 'open']],
            ['entity' => 'sales_documents', 'record_id' => $doc, 'data' => ['type' => 'receipt', 'date' => now()->toDateString(), 'status' => 'validated', 'pos_session_id' => $session, 'warehouse_id' => $wh,
                'lines' => [['product_id' => $product, 'description' => 'Ordinateur portable', 'quantity' => 1, 'unit_price' => 381355.93, 'tax_rate' => 18]]]],
            ['entity' => 'sales_payments', 'record_id' => $this->uuid(), 'data' => ['document_id' => $doc, 'date' => now()->toDateString(), 'amount' => 450000, 'method' => 'cash']],
            ['entity' => 'pos_sessions', 'record_id' => $session, 'data' => ['status' => 'closed', 'counted_cash' => 460000]],
        ]);
        $this->assertEquals(0, $this->api($this->owner)->getJson("/api/pos/sessions/$session")->json('data.difference'));
        // Stock : 20 − 2 vendus − 1 en caisse (+10 achetés si la facture fournisseur entre en stock)
        $qty = collect($this->api($this->tok['stock'])->getJson('/api/stock/levels')->json('data'))->where('product_id', $product)->sum('quantity');
        $this->assertContains((float) $qty, [17.0, 27.0]);

        // Finance : le comptable voit la comptabilité et la trésorerie générées
        $this->api($this->tok['finance'])->getJson('/api/compta/dashboard')->assertOk();
        $this->api($this->tok['finance'])->getJson('/api/tresorerie/accounts')->assertOk();

        // RH : salarié (stagiaire), congé ; le salarié dépose une note de frais
        $emp = $this->uuid();
        $salarieUser = User::where('email', 'salarie@nouvelle.ci')->value('id');
        $this->push($this->tok['rh'], [
            ['entity' => 'rh_employees', 'record_id' => $emp, 'data' => ['first_name' => 'Awa', 'last_name' => 'Bamba', 'email' => 'salarie@nouvelle.ci', 'user_id' => $salarieUser,
                'job_title' => 'Assistante commerciale', 'staff_type' => 'intern', 'hire_date' => now()->subMonth()->toDateString(), 'base_salary' => 100000]],
            ['entity' => 'rh_leaves', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $emp, 'type' => 'annual', 'start_date' => now()->addDays(10)->toDateString(), 'end_date' => now()->addDays(11)->toDateString(), 'status' => 'approved']],
        ]);
        $this->api($this->tok['salarie'])->getJson('/api/rh/me')->assertOk();

        // SAV : contrat d'entretien chez le client
        $this->api($this->tok['sav'])->postJson('/api/interventions/contracts', ['partner_id' => $cli, 'name' => 'Maintenance parc informatique', 'start_date' => now()->toDateString(),
            'frequency' => 'quarterly', 'price' => 60000, 'billing' => 'quarterly'])->assertCreated();

        // Projets : projet client
        $this->api($this->tok['projets'])->postJson('/api/projets/projects', ['name' => 'Déploiement réseau Banque', 'partner_id' => $cli, 'billing_mode' => 'fixed'])->assertCreated();

        // Marketing : publication planifiée
        $this->api($this->tok['marketing'])->postJson('/api/contenus/records/posts', ['title' => 'Offre rentrée', 'networks' => ['facebook'], 'caption' => '-10 % sur les portables', 'status' => 'review'])->assertCreated();

        // Juridique : contrat client
        $this->api($this->tok['juridique'])->postJson('/api/juridique/records/contracts', ['title' => 'Contrat cadre Banque Atlantique', 'kind' => 'customer', 'status' => 'active', 'partner_id' => $cli])->assertCreated();

        // Direction : vue d'ensemble, rapports et validations
        $this->api($this->tok['direction'])->getJson('/api/dashboard')->assertOk();
        $this->api($this->tok['direction'])->getJson('/api/rapports/meta')->assertOk();

        // 5. Cloisonnement : chacun reste dans son périmètre
        $this->api($this->tok['caisse'])->getJson('/api/compta/dashboard')->assertForbidden();
        $this->api($this->tok['salarie'])->getJson('/api/compta/dashboard')->assertForbidden();
        $this->api($this->tok['marketing'])->postJson('/api/projets/projects', ['name' => 'Interdit'])->assertForbidden();
        $this->api($this->tok['ventes'])->postJson('/api/users', ['name' => 'X', 'email' => 'x@nouvelle.ci', 'password' => 'secret1234'])->assertForbidden();
        $r = $this->api($this->tok['caisse'])->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'op' => 'upsert', 'entity' => 'purchase_documents', 'record_id' => $this->uuid(),
            'data' => ['type' => 'supplier_invoice', 'partner_id' => $sup, 'date' => now()->toDateString(), 'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1]]]]]])->json('results.0.status');
        $this->assertNotSame('applied', $r);
        // Données d'une autre société : jamais visibles
        [, , $other] = $this->makeCompany('Concurrent SARL');
        $this->assertNotContains('Banque Atlantique', collect($this->api($other)->getJson('/api/core/partners')->json('data'))->pluck('name'));
        $this->assertSame(0, DB::table('partners')->where('company_id', '!=', $company->id)->where('name', 'Banque Atlantique')->count());
        $this->assertTrue(Str::startsWith($inv['number'], 'FAC-'));
    }
}
