<?php

namespace Tests\Feature;

use App\Licensing\LicenseClient;
use App\Licensing\LicenseIssuer;
use App\Models\Company;
use App\Models\License;
use App\Models\Module;
use App\Support\ModuleRegistry;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LicenseTest extends TestCase
{
    protected string $admin;

    protected string $client;

    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.mode' => 'saas']);
        // Ces tests achètent le CRM : on le sort du socle gratuit (sinon il est compris d'office dans toutes les formules)
        \App\Models\Plan::where('code', 'gratuit')->get()->each->update(['modules' => ['ventes']]);
        [, , $this->admin] = $this->makeCompany('Yomara', 'entreprise', superAdmin: true);
        [$c, , $this->client] = $this->makeCompany('Groupe Awa', 'starter');
        $this->companyId = $c->id;
        \App\Models\Plan::where('code', 'starter')->update(['modules' => ['ventes', 'stock']]);
        Module::where('key', 'crm')->update(['billing_mode' => 'both', 'price_monthly' => 7500, 'price_yearly' => 75000, 'price_one_time' => 150000]);
        File::delete(LicenseClient::statePath());
    }

    protected function tearDown(): void
    {
        File::delete(LicenseClient::statePath());
        parent::tearDown();
    }

    protected function generate(string $name = 'Awa Distribution', string $target = 'windows'): array
    {
        return $this->api($this->client)->postJson('/api/licenses', ['company_name' => $name, 'target' => $target])->assertCreated()->json('data');
    }

    public function test_owner_generates_licenses_activation_is_bound_to_one_device(): void
    {
        $a = $this->generate('Awa Distribution', 'server');
        $b = $this->generate('Awa Immobilier', 'windows');
        $this->assertMatchesRegularExpression('/^YMR(-[A-Z2-9]{5}){4}$/', $a['key']);
        $this->assertNotSame($a['key'], $b['key']);
        $this->assertSame('unused', $a['status']);

        // Les sociétés « licence » restent hors des listes de la plateforme
        $this->assertSame(0, Company::where('name', 'Awa Distribution')->count());
        $names = collect($this->api($this->admin)->getJson('/api/admin/companies')->assertOk()->json('data'))->pluck('name');
        $this->assertNotContains('Awa Distribution', $names);

        $fp1 = str_repeat('a', 64);
        $fp2 = str_repeat('b', 64);
        $r = $this->postJson('/api/license/activate', ['key' => strtolower($a['key']), 'fingerprint' => $fp1, 'device' => ['name' => 'SRV-AWA', 'version' => '0.98.0', 'users' => 4, 'url' => 'https://erp.awa.ci']])->assertOk()->json('license');
        $p = json_decode($r['payload'], true);
        $this->assertTrue(\App\Licensing\LicenseSigner::verify($r['payload'], $r['signature'], $r['public_key']));
        $this->assertSame('Awa Distribution', $p['company_name']);
        $this->assertContains('core', $p['enabled']);
        $this->assertNotContains('crm', $p['enabled']);
        $this->assertTrue(\Carbon\Carbon::parse($p['valid_until'])->gt(now()->addDays(29)));

        // Même appareil : réactivation acceptée ; autre appareil : refusée
        $this->postJson('/api/license/activate', ['key' => $a['key'], 'fingerprint' => $fp1])->assertOk();
        $this->postJson('/api/license/activate', ['key' => $a['key'], 'fingerprint' => $fp2])->assertStatus(409);
        $this->postJson('/api/license/heartbeat', ['key' => $a['key'], 'fingerprint' => $fp2])->assertStatus(409);
        $this->postJson('/api/license/heartbeat', ['key' => $a['key'], 'fingerprint' => $fp1, 'device' => ['users' => 6]])->assertOk();
        $this->postJson('/api/license/activate', ['key' => 'YMR-AAAAA-AAAAA-AAAAA-AAAAA', 'fingerprint' => $fp1])->assertNotFound();

        $mine = $this->api($this->client)->getJson('/api/licenses')->assertOk()->json();
        $this->assertSame(2, $mine['stats']['total']);
        $this->assertSame(1, $mine['stats']['online']);
        $row = collect($mine['data'])->firstWhere('id', $a['id']);
        $this->assertSame('SRV-AWA', $row['device_name']);
        $this->assertSame(6, $row['users_count']);
        $events = $this->api($this->client)->getJson('/api/licenses/'.$a['id'])->assertOk()->json('data.events');
        $this->assertContains('refused', array_column($events, 'type'));

        // Libérer l'appareil : un autre poste peut l'activer
        $this->api($this->client)->postJson('/api/licenses/'.$a['id'].'/reset')->assertOk();
        $this->postJson('/api/license/activate', ['key' => $a['key'], 'fingerprint' => $fp2])->assertOk();

        // Révocation par le super admin
        $this->api($this->admin)->postJson('/api/admin/licenses/'.$a['id'].'/status', ['action' => 'revoke'])->assertOk();
        $this->postJson('/api/license/activate', ['key' => $a['key'], 'fingerprint' => $fp2])->assertStatus(403);

        // Une autre société ne voit pas ces licences
        [, , $other] = $this->makeCompany('Autre', 'starter');
        $this->api($other)->getJson('/api/licenses/'.$b['id'])->assertNotFound();
        $this->assertSame(0, count($this->api($other)->getJson('/api/licenses')->json('data')));

        $adm = $this->api($this->admin)->getJson('/api/admin/licenses')->assertOk()->json();
        $this->assertSame(2, $adm['stats']['total']);
        $this->assertSame(1, $adm['stats']['by_target']['windows']);
        $an = $this->api($this->admin)->getJson('/api/admin/analytics')->assertOk()->json('licenses');
        $this->assertSame(2, $an['total']);
    }

    public function test_modules_bought_for_a_license_only_reach_that_installation(): void
    {
        $bank = $this->api($this->admin)->postJson('/api/admin/payment-gateways', ['driver' => 'bank_transfer', 'label' => 'Virement', 'instructions' => 'RIB'])->assertCreated()->json('id');
        $a = $this->generate('Entreprise A');
        $b = $this->generate('Entreprise B');

        $store = $this->api($this->client)->getJson('/api/store?license='.$a['id'])->assertOk()->json();
        $this->assertSame('Entreprise A', $store['license']['company_name']);
        $this->assertCount(2, $store['licenses']);
        $this->assertFalse(collect($store['modules'])->firstWhere('key', 'crm')['active']);

        $order = $this->api($this->client)->postJson('/api/store/orders', ['module' => 'crm', 'billing' => 'monthly', 'gateway_id' => $bank, 'license_id' => $a['id']])->assertCreated()->json('order');
        $this->api($this->client)->postJson('/api/store/orders/'.$order['id'].'/declare', ['reference' => 'VIR-1'])->assertOk();
        $this->api($this->admin)->postJson('/api/admin/store/orders/'.$order['id'].'/approve')->assertOk();

        $this->assertTrue(collect($this->api($this->client)->getJson('/api/store?license='.$a['id'])->json('modules'))->firstWhere('key', 'crm')['active']);
        $this->assertFalse(collect($this->api($this->client)->getJson('/api/store?license='.$b['id'])->json('modules'))->firstWhere('key', 'crm')['active']);
        // L'espace en ligne du client n'en profite pas
        $this->assertFalse(collect($this->api($this->client)->getJson('/api/store')->json('modules'))->firstWhere('key', 'crm')['active']);
        $orders = $this->api($this->client)->getJson('/api/store')->json('orders');
        $this->assertSame('Entreprise A', $orders[0]['license_name']);

        $issuer = app(LicenseIssuer::class);
        $this->assertContains('crm', $issuer->modules(License::find($a['id']))['enabled']);
        $this->assertNotContains('crm', $issuer->modules(License::find($b['id']))['enabled']);
        $this->assertContains('crm', array_column($this->api($this->client)->getJson('/api/licenses/'.$a['id'])->json('data.modules'), 'key'));

        // Licence d'une autre société : interdit
        [, , $other] = $this->makeCompany('Autre', 'starter');
        $this->api($other)->getJson('/api/store?license='.$a['id'])->assertNotFound();
        $this->api($other)->postJson('/api/store/orders', ['module' => 'crm', 'billing' => 'monthly', 'gateway_id' => $bank, 'license_id' => $a['id']])->assertNotFound();
    }

    public function test_installation_side_enforces_the_license(): void
    {
        $a = $this->generate('Entreprise A');
        $bank = $this->api($this->admin)->postJson('/api/admin/payment-gateways', ['driver' => 'bank_transfer', 'label' => 'Virement', 'instructions' => 'RIB'])->assertCreated()->json('id');
        $order = $this->api($this->client)->postJson('/api/store/orders', ['module' => 'crm', 'billing' => 'yearly', 'gateway_id' => $bank, 'license_id' => $a['id']])->assertCreated()->json('order');
        $this->api($this->admin)->postJson('/api/admin/store/orders/'.$order['id'].'/approve')->assertOk();

        // La « plateforme » répond via le même code (Http simulé)
        $issuer = app(LicenseIssuer::class);
        Http::fake(function (HttpRequest $r) use ($issuer) {
            $d = $r->data();
            $mode = config('erp.mode');
            config(['erp.mode' => 'saas']); // la plateforme calcule en mode SaaS
            app(ModuleRegistry::class)->flush();
            [$code, $body] = str_ends_with($r->url(), '/activate')
                ? $issuer->activate($d['key'], $d['fingerprint'], (array) ($d['device'] ?? []), '10.0.0.1')
                : $issuer->heartbeat($d['key'], $d['fingerprint'], (array) ($d['device'] ?? []), '10.0.0.1');
            config(['erp.mode' => $mode]);
            app(ModuleRegistry::class)->flush();

            return Http::response($body, $code);
        });

        // Bascule en installation unique (même base pour le test)
        config(['erp.mode' => 'single', 'erp.license_enforce' => true]);
        $company = Company::find($this->companyId);
        $registry = app(ModuleRegistry::class);
        $registry->flush();
        $this->assertTrue(LicenseClient::enforced());
        // Sans compte relié : les fonctions de base (formule Gratuite) restent disponibles
        $this->assertEqualsCanonicalizing(['core', 'ventes', 'stock', 'crm'], array_values(array_intersect($registry->enabledFor($company), ['core', 'ventes', 'stock', 'crm'])));
        $this->assertNotContains('fne', $registry->enabledFor($company));

        try {
            LicenseClient::activate('YMR-XXXXX-XXXXX-XXXXX-XXXXX');
            $this->fail('clé inconnue acceptée');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('inconnue', $e->getMessage());
        }

        $info = LicenseClient::activate($a['key'], 'Entreprise A');
        $this->assertSame('valid', $info['status']);
        $this->assertSame('Entreprise A', $info['company_name']);
        $this->assertContains('crm', array_column($info['modules']->all(), 'key'));
        $registry->flush();
        $enabled = $registry->enabledFor($company);
        $this->assertContains('crm', $enabled);
        $this->assertNotContains('fne', $enabled); // la formule « en ligne » de la société ne compte pas
        $this->assertSame(1, License::find($a['id'])->events()->where('type', 'activated')->count());

        // Fichier de licence modifié à la main : signature invalide, modules bloqués
        $s = json_decode(file_get_contents(LicenseClient::statePath()), true);
        $p = json_decode($s['payload'], true);
        $p['enabled'][] = 'ventes';
        $s['payload'] = json_encode($p);
        file_put_contents(LicenseClient::statePath(), json_encode($s));
        $this->refreshLicenseCache();
        $registry->flush();
        $this->assertNotContains('fne', $registry->enabledFor($company));

        // La vérification suivante rétablit la licence signée
        $this->assertSame('valid', LicenseClient::check()['status']);
        $registry->flush();
        $this->assertContains('crm', $registry->enabledFor($company));

        // Suspendue par l'éditeur : plus de modules à la vérification suivante
        config(['erp.mode' => 'saas']);
        $this->api($this->admin)->postJson('/api/admin/licenses/'.$a['id'].'/status', ['action' => 'suspend'])->assertOk();
        config(['erp.mode' => 'single']);
        $this->assertSame('suspended', LicenseClient::check()['status']);
        $registry->flush();
        $this->assertContains('crm', $registry->enabledFor($company)); // CRM fait partie des fonctions de base
        $this->assertNotContains('lots', $registry->enabledFor($company));

        // Appareil libéré puis activé ailleurs : cette installation est refusée
        License::find($a['id'])->update(['status' => 'active', 'fingerprint' => str_repeat('c', 64)]);
        $this->assertSame('refused', LicenseClient::check()['status']);
    }

    protected function refreshLicenseCache(): void
    {
        $r = new \ReflectionProperty(LicenseClient::class, 'cache');
        $r->setValue(null, null);
    }

    public function test_landing_signup_contact_and_editor_analytics(): void
    {
        $ua = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130', 'Referer' => 'https://www.google.com/'];
        $this->withHeaders($ua)->get('/?utm_source=facebook&utm_campaign=lancement')->assertOk()->assertSee('Créer mon entreprise')->assertSee('Un besoin précis');
        $this->withHeaders(['User-Agent' => 'Googlebot/2.1'])->get('/')->assertOk(); // robot ignoré
        $this->withHeaders($ua)->get('/inscription')->assertOk();
        \Illuminate\Support\Facades\Mail::fake();
        $this->withHeaders($ua)->post('/inscription', ['company_name' => 'Boutique Kady', 'name' => 'Kady Traoré', 'email' => 'kady@exemple.ci', 'phone' => '+2250700000001',
            'password' => 'MotDePasse!1', 'password_confirmation' => 'MotDePasse!1', 'terms' => '1'])->assertRedirect('/inscription/bienvenue');
        $this->assertSame(1, \App\Models\User::where('email', 'kady@exemple.ci')->count());
        $this->withHeaders($ua)->post('/contact', ['type' => 'custom_module', 'name' => 'Ali Koné', 'company' => 'Transports Koné', 'email' => 'ali@kone.ci', 'message' => 'Module de gestion des tournées de camions.'])
            ->assertRedirect('/contact#formulaire');
        $this->assertDatabaseHas('landing_requests', ['email' => 'ali@kone.ci', 'type' => 'custom_module', 'status' => 'new']);

        // Prospects versés dans le Marketing et le CRM de la société de l'éditeur
        $editor = \App\Services\SiteTraffic::editorCompany();
        $this->assertSame('Yomara', $editor->name);
        $this->assertSame(2, \App\Modules\Marketing\Models\Contact::withoutGlobalScopes()->where('company_id', $editor->id)->whereIn('email', ['kady@exemple.ci', 'ali@kone.ci'])->count());
        $this->assertSame(1, \App\Modules\Crm\Models\Lead::withoutGlobalScopes()->where('company_id', $editor->id)->where('email', 'ali@kone.ci')->count());

        $a = $this->api($this->admin)->getJson('/api/admin/site-analytics')->assertOk()->json();
        $this->assertSame(1, $a['totals']['visitors']);
        $this->assertSame(1, $a['totals']['signups']);
        $this->assertSame(1, $a['totals']['contacts']);
        $this->assertSame('facebook', $a['sources'][0]['label']);
        $this->assertSame('Windows', $a['os'][0]['label']);
        $this->assertSame('Module sur mesure', $a['requests'][0]['type_label']);
        $this->api($this->admin)->putJson('/api/admin/landing-requests/'.$a['requests'][0]['id'], ['status' => 'handled'])->assertOk();
        $this->api($this->client)->getJson('/api/admin/site-analytics')->assertForbidden();
        // Pages du site : téléchargement (installation sous licence) et contact
        $this->flushHeaders();
        $this->get('/telecharger')->assertOk()->assertSee('Compte Yomara');
        $this->get('/contact')->assertOk()->assertSee('module sur mesure');
    }

    public function test_saas_mode_install_requires_editor_key_and_landing_is_public(): void
    {
        $this->get('/')->assertOk()->assertSee('Créer mon entreprise')->assertSee('Télécharger');
        $this->get('/inscription')->assertOk();
        config(['erp.mode' => 'single']);
        $this->get('/')->assertRedirect('/app/');
    }
}
