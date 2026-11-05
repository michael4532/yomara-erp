<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Modules\Crm\Models\Lead;
use App\Modules\Marketing\Models\Contact;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\SiteMessage;
use App\Modules\Sites\Models\SiteRequest;
use App\Modules\Sites\SiteService;
use App\Modules\Sites\Templates;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SitesTest extends TestCase
{
    protected function tearDown(): void
    {
        SiteService::$resolver = null;
        parent::tearDown();
    }

    /** Les requêtes de test réutilisent l'hôte de la précédente : retour à l'application. */
    private function home(): void
    {
        app('url')->forceRootUrl('http://localhost');
    }

    private function public(): void
    {
        $this->flushHeaders();
        app()->forgetInstance('tenant');
    }

    public function test_template_gallery_request_delivery_customisation_publication_contact_and_stats(): void
    {
        Storage::fake('public');
        [$yomara, $root, $rootToken] = $this->makeCompany('Yomara Technologies', 'entreprise', superAdmin: true);
        [$company, $admin, $token] = $this->makeCompany('Maquis Chez Tantie');
        $company->forceFill(['phone' => '07 07 12 34 56', 'city' => 'Cocody', 'address' => 'Rue des Jardins', 'email' => 'contact@tantie.ci'])->save();
        $api = fn () => $this->api($token);

        // Galerie : plus de 50 modèles, aperçu signé avec les données de la société
        $this->assertGreaterThanOrEqual(50, count(Templates::all()));
        $g = $api()->getJson('/api/sites/templates')->assertOk()->json();
        $this->assertCount(54, $g['data']);
        $this->assertCount(18, $g['sectors']);
        $tpl = collect($g['data'])->firstWhere('key', 'restaurant-eclat');
        $this->assertSame('Restaurant & maquis · Éclat', $tpl['name']);
        $this->public();
        $this->get($tpl['preview_url'])->assertOk()->assertSee('Maquis Chez Tantie')->assertSee('La cuisine qui rassemble')->assertSee('Cocody')->assertSee('Aperçu du modèle');
        $this->get('/sites/modeles/restaurant-eclat?c='.$company->id)->assertForbidden();

        // Demande d'un modèle : le super admin est prévenu, crée le site
        $api()->postJson('/api/sites/requests', ['type' => 'template', 'template' => 'nimporte'])->assertStatus(422);
        $req = $api()->postJson('/api/sites/requests', ['type' => 'template', 'template' => 'restaurant-eclat', 'site_name' => 'Chez Tantie'])->assertCreated()->json('data');
        $this->assertSame(['submitted', 'SITE-'.now()->year.'-0001'], [$req['status'], $req['number']]);
        $api()->postJson('/api/sites/requests', ['type' => 'template', 'template' => 'restaurant-moderne'])->assertStatus(422); // déjà en cours
        $this->assertSame(1, AppNotification::where('user_id', $root->id)->where('title', 'like', 'Demande de site vitrine%')->count());
        $this->assertNull($api()->getJson('/api/sites/overview')->assertOk()->json('data.site'));
        $api()->putJson('/api/sites/site', ['name' => 'X'])->assertNotFound();
        $this->api($token)->getJson('/api/admin/sites')->assertForbidden();
        $d = $this->api($rootToken)->postJson('/api/admin/sites/requests/'.$req['id'].'/deliver')->assertOk()->json();
        $this->assertSame('delivered', $d['data']['status']);
        $site = Site::withoutGlobalScopes()->where('company_id', $company->id)->first();
        $this->assertSame(['maquis-chez-tantie', 'draft', 'Chez Tantie'], [$site->slug, $site->status, $site->name]);
        $this->assertSame(1, AppNotification::where('user_id', $admin->id)->where('title', 'Votre site est prêt')->count());

        // Brouillon : invisible sans le lien d'aperçu
        $this->public();
        $this->get('/site/maquis-chez-tantie')->assertNotFound();
        $this->get('/site/maquis-chez-tantie?apercu='.$site->preview_token)->assertOk()->assertSee('Aperçu — site non publié');

        // Personnalisation : thème, textes, sections, produits de l'ERP, photos
        $api()->putJson('/api/sites/site', ['theme' => ['primary' => 'rouge']])->assertStatus(422);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $pid = $this->uuid();
        $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'products', 'op' => 'upsert', 'record_id' => $pid,
            'data' => ['name' => 'Garba complet', 'sale_price' => 1500, 'type' => 'product', 'description' => 'Attiéké, thon frit, piment']]]])->assertOk();
        $r = $api()->putJson('/api/sites/site', [
            'theme' => ['primary' => '#0F7B6C', 'heading_font' => 'Lora', 'hero' => 'centered'],
            'content' => ['hero_title' => 'Le meilleur garba de Cocody', 'hours' => "Lun–Sam : 11 h – 23 h\nDim : 12 h – 22 h", 'cta_target' => 'whatsapp', 'whatsapp' => '0707123456',
                'services' => [['icon' => 'utensils', 'title' => 'Garba', 'text' => 'Le classique'], ['icon' => 'inconnu', 'title' => 'Alloco', 'text' => 'Bananes plantains'], ['title' => '', 'text' => '']],
                'testimonials' => [['name' => 'Koffi', 'role' => 'Client fidèle', 'text' => 'Le meilleur garba du quartier !']],
                'stats' => [['value' => '12 ans', 'label' => 'd\'expérience']], 'socials' => ['facebook' => 'facebook.com/cheztantie'], 'product_ids' => [$pid]],
            'sections' => ['about', 'products', 'testimonials', 'stats', 'contact', 'pirate'], 'seo' => ['title' => 'Chez Tantie — maquis à Cocody'],
        ])->assertOk()->json('data');
        $this->assertSame(['hero', 'about', 'products', 'testimonials', 'stats', 'contact'], $r['sections']);
        $this->assertSame(['#0F7B6C', 'Lora', 'centered'], [$r['theme']['primary'], $r['theme']['heading_font'], $r['theme']['hero']]);
        $this->assertSame('check', $r['content']['services'][1]['icon']);
        $this->assertCount(2, $r['content']['services']);
        $this->assertSame('https://facebook.com/cheztantie', $r['content']['socials']['facebook']);
        $this->assertSame('La cuisine qui rassemble', Templates::get('restaurant-eclat')['content']['hero_title']); // modèle intact
        $up = $api()->post('/api/sites/site/images', ['slot' => 'hero', 'file' => UploadedFile::fake()->image('salle.jpg', 1200, 800)])->assertOk()->json('data');
        $this->assertStringContainsString('/storage/sites/', $up['images']['hero']);
        $api()->post('/api/sites/site/images', ['slot' => 'gallery', 'file' => UploadedFile::fake()->image('plat.png')])->assertOk();
        $api()->post('/api/sites/site/images', ['slot' => 'gallery', 'file' => UploadedFile::fake()->create('virus.exe', 20)])->assertStatus(422);
        $gal = $api()->getJson('/api/sites/overview')->json('data.site.images.gallery');
        $this->assertCount(1, $gal);
        Storage::disk('public')->assertExists($gal[0]['path']);
        $api()->postJson('/api/sites/site/images/remove', ['path' => $gal[0]['path']])->assertOk();
        Storage::disk('public')->assertMissing($gal[0]['path']);

        // Publication : page publique avec les données de l'ERP
        $pub = $api()->postJson('/api/sites/site/publish', ['published' => true])->assertOk()->json('data');
        $this->assertSame('published', $pub['status']);
        $this->assertStringEndsWith('/site/maquis-chez-tantie', $pub['url']);
        $this->public();
        $page = $this->withHeader('Referer', 'https://www.facebook.com/somepost')->get('/site/maquis-chez-tantie')->assertOk();
        $page->assertSee('Le meilleur garba de Cocody')->assertSee('Garba complet')->assertSee('1 770 F CFA')->assertSee('Le meilleur garba du quartier')->assertSee('12 ans')
            ->assertSee('https://wa.me/2250707123456', false)->assertSee('Lun–Sam : 11 h – 23 h')->assertSee('family=Lora', false)->assertSee('<title>Chez Tantie — maquis à Cocody</title>', false)
            ->assertDontSee('Aperçu')->assertDontSee('id="galerie"', false);
        $this->flushHeaders();
        $this->get('/site/maquis-chez-tantie')->assertOk();
        $this->withHeader('User-Agent', 'Googlebot/2.1')->get('/site/maquis-chez-tantie')->assertOk(); // robots non comptés
        $this->flushHeaders();

        // Formulaire de contact : message, opportunité CRM, contact Marketing, équipe prévenue
        $this->post('/site/maquis-chez-tantie/contact', ['name' => 'Awa', 'message' => 'Bonjour, livrez-vous à Angré ?'])->assertRedirect()->assertRedirectContains('erreur=');
        $this->post('/site/maquis-chez-tantie/contact', ['name' => 'Robot', 'email' => 'r@x.ci', 'message' => 'Achetez des liens', 'website' => 'http://spam'])->assertRedirectContains('envoye=1');
        $this->assertSame(0, SiteMessage::withoutGlobalScopes()->count());
        $this->post('/site/maquis-chez-tantie/contact', ['name' => 'Awa Koné', 'email' => 'awa@mail.ci', 'phone' => '0102030405', 'subject' => 'Commande pour un anniversaire',
            'message' => 'Bonjour, je voudrais 30 plats de garba samedi.', 'consent' => '1'])->assertRedirect('/site/maquis-chez-tantie/?envoye=1#contact');
        $m = SiteMessage::withoutGlobalScopes()->first();
        $this->assertSame(['Awa Koné', 'Commande pour un anniversaire', true], [$m->name, $m->subject, $m->consent]);
        $lead = Lead::withoutGlobalScopes()->find($m->lead_id);
        $this->assertEquals(['Site web — Commande pour un anniversaire', 'website', 'awa@mail.ci'], [$lead->title, $lead->source, $lead->email]);
        $ct = Contact::withoutGlobalScopes()->where('email', 'awa@mail.ci')->first();
        $this->assertSame('confirmed', $ct->opt_in);
        $this->assertSame('Contacts du site web', DB::table('mk_lists')->whereIn('id', DB::table('mk_list_contact')->where('contact_id', $ct->id)->pluck('list_id'))->value('name'));
        $this->assertSame(1, AppNotification::where('user_id', $admin->id)->where('title', 'Nouveau message du site : Awa Koné')->count());
        $this->get('/site/maquis-chez-tantie/?envoye=1')->assertSee('Votre message a bien été envoyé');

        // Statistiques et messages dans l'ERP
        $o = $api()->getJson('/api/sites/overview')->assertOk()->json('data');
        $this->assertSame(3, $o['stats']['views']);
        $this->assertEquals([['source' => 'direct', 'views' => 2], ['source' => 'facebook.com', 'views' => 1]], $o['stats']['referrers']);
        $this->assertSame([1, 1], [$o['stats']['messages'], $o['site']['unread']]);
        $msgs = $api()->getJson('/api/sites/messages')->assertOk()->json('data');
        $api()->postJson('/api/sites/messages/'.$msgs[0]['id'].'/read')->assertOk();
        $this->assertSame(0, $api()->getJson('/api/sites/overview')->json('data.site.unread'));

        // Suspension par la plateforme
        $this->api($rootToken)->postJson('/api/admin/sites/'.$site->id.'/status', ['status' => 'suspended'])->assertOk();
        $this->public();
        $this->get('/site/maquis-chez-tantie')->assertNotFound();
        $api()->postJson('/api/sites/site/publish')->assertStatus(422)->assertJsonPath('message', 'Site suspendu par la plateforme : contactez le support.');
        $all = $this->api($rootToken)->getJson('/api/admin/sites')->assertOk()->json();
        $this->assertEquals(['Maquis Chez Tantie', 3, 1], [$all['sites'][0]['company'], $all['sites'][0]['views_30d'], $all['sites'][0]['messages']]);
    }

    public function test_subdomain_custom_domain_dns_verification_and_host_routing(): void
    {
        [, , $rootToken] = $this->makeCompany('Yomara Technologies', 'entreprise', superAdmin: true);
        [$company, , $token] = $this->makeCompany('Cabinet Aka & Associés');
        $o2 = $this->makeCompany('Autre');
        $req = $this->api($token)->postJson('/api/sites/requests', ['type' => 'template', 'template' => 'juridique-elegance'])->json('data');
        $this->api($rootToken)->putJson('/api/admin/sites/settings', ['base_domain' => 'sites.yomara.ci', 'server_ip' => '203.0.113.10', 'auto_template' => true])->assertOk();
        $this->api($rootToken)->postJson('/api/admin/sites/requests/'.$req['id'].'/deliver', ['template' => 'juridique-moderne'])->assertOk();
        $api = fn () => $this->api($token);
        $s = $api()->postJson('/api/sites/site/publish')->assertOk()->json('data');
        $this->assertSame(['https://cabinet-aka-associes.sites.yomara.ci', 'juridique-moderne'], [$s['url'], $s['template']]);

        // Sous-domaine de la plateforme
        $this->public();
        $this->get('http://cabinet-aka-associes.sites.yomara.ci/')->assertOk()->assertSee('Un conseil rigoureux');
        $this->get('http://inconnu.sites.yomara.ci/')->assertNotFound();
        $this->get('http://cabinet-aka-associes.sites.yomara.ci/robots.txt')->assertOk()->assertSee('User-agent');
        $this->get('http://localhost/')->assertRedirect('/app/'); // l'application n'est pas touchée
        $this->home();

        // Domaine personnel : enregistrements à créer, vérification DNS
        $api()->putJson('/api/sites/site/domain', ['domain' => 'pas un domaine'])->assertStatus(422);
        $api()->putJson('/api/sites/site/domain', ['domain' => 'client.sites.yomara.ci'])->assertStatus(422)->assertJsonPath('message', 'Ce domaine appartient à la plateforme.');
        SiteService::$resolver = fn () => [];
        $d = $api()->putJson('/api/sites/site/domain', ['domain' => 'https://www.Cabinet-Aka.ci/'])->assertOk()->json('data');
        $this->assertSame(['cabinet-aka.ci', 'pending'], [$d['custom_domain'], $d['domain_status']]);
        $this->assertSame(['TXT', 'A', 'CNAME'], array_column($d['dns'], 'type'));
        $this->assertSame(['_yomara', '@', 'www'], array_column($d['dns'], 'host'));
        $this->assertSame('cabinet-aka-associes.sites.yomara.ci', $d['dns'][2]['value']);
        $token24 = substr($d['dns'][0]['value'], strlen('yomara-site='));
        $this->get('http://cabinet-aka.ci/')->assertNotFound(); // pas encore vérifié
        $this->home();
        // TXT trouvé, pas encore pointé → vérifié
        SiteService::$resolver = fn ($h, $t) => $t === 'TXT' && $h === '_yomara.cabinet-aka.ci' ? [['txt' => 'yomara-site='.$token24]] : [];
        $this->assertSame('verified', $api()->postJson('/api/sites/site/domain/check')->json('data.domain_status'));
        // Pointé (A vers le serveur) → actif, l'adresse du site devient le domaine
        SiteService::$resolver = fn ($h, $t) => match (true) {
            $t === 'TXT' && $h === '_yomara.cabinet-aka.ci' => [['txt' => 'yomara-site='.$token24]],
            $t === 'A' && $h === 'cabinet-aka.ci' => [['ip' => '203.0.113.10']],
            default => [],
        };
        $this->artisan('erp:sites-dns')->assertSuccessful();
        $this->home();
        $s = Site::withoutGlobalScopes()->where('company_id', $company->id)->first();
        $this->assertSame('active', $s->domain_status);
        $this->assertSame('https://cabinet-aka.ci', $api()->getJson('/api/sites/overview')->json('data.site.url'));
        $this->public();
        $this->get('http://cabinet-aka.ci/')->assertOk()->assertSee('Cabinet Aka');
        $this->get('http://www.cabinet-aka.ci/')->assertOk();
        $this->get('http://cabinet-aka.ci/une-page')->assertRedirect('/');
        $this->post('http://cabinet-aka.ci/contact', ['name' => 'Yao', 'phone' => '0505050505', 'message' => 'Besoin d\'un conseil pour créer une SARL.'])->assertRedirect('/?envoye=1#contact');
        $this->assertSame(1, SiteMessage::withoutGlobalScopes()->where('site_id', $s->id)->count());
        $this->home();
        // Domaine déjà pris par un autre site
        $other = Site::withoutGlobalScopes()->create(['company_id' => $o2[0]->id, 'name' => 'Autre', 'slug' => 'autre', 'template' => 'agence-eclat', 'preview_token' => 'x']);
        app()->instance('tenant', $o2[0]);
        $this->expectException(\DomainException::class);
        app(SiteService::class)->setDomain($other, 'cabinet-aka.ci');
    }

    public function test_custom_site_estimate_quote_in_provider_erp_acceptance_and_delivery(): void
    {
        [$yomara, $root, $rootToken] = $this->makeCompany('Yomara Technologies', 'entreprise', superAdmin: true);
        [$company, $admin, $token] = $this->makeCompany('Clinique Les Palmiers');
        $company->forceFill(['email' => 'direction@palmiers.ci', 'phone' => '27 22 00 11 22'])->save();
        $api = fn () => $this->api($token);
        $needs = ['page_count' => 8, 'features' => ['booking', 'multilingual', 'seo', 'inconnue'], 'description' => 'Site de la clinique avec prise de rendez-vous par spécialité.', 'urgent' => true];
        // Estimation immédiate : base 150 000 + 3 pages × 20 000 + 75 000 + 80 000 + 75 000 = 440 000 ; express +25 % = 550 000 HT ; TVA 18 %
        $e = $api()->postJson('/api/sites/estimate', ['needs' => $needs])->assertOk()->json('data');
        $this->assertEquals([550000, 99000, 649000, 8], [$e['subtotal'], $e['tax'], $e['total'], $e['pages']]);
        $this->assertSame(['base', 'extra_page', 'booking', 'multilingual', 'seo', 'urgent'], array_column($e['lines'], 'key'));
        $api()->postJson('/api/sites/requests', ['type' => 'custom', 'needs' => []])->assertStatus(422);
        $req = $api()->postJson('/api/sites/requests', ['type' => 'custom', 'site_name' => 'Clinique Les Palmiers', 'sector' => 'sante', 'needs' => $needs])->assertCreated()->json('data');
        $this->assertEquals([649000, ['booking', 'multilingual', 'seo']], [$req['estimate'], $req['needs']['features']]);
        // Livraison refusée avant acceptation du devis
        $this->api($rootToken)->postJson('/api/admin/sites/requests/'.$req['id'].'/deliver')->assertStatus(422);

        // Devis émis dans l'ERP du prestataire, au nom du client (fiche client créée), avec une remise
        $q = $this->api($rootToken)->postJson('/api/admin/sites/requests/'.$req['id'].'/quote', ['note' => 'Délai : 10 jours ouvrés.', 'discount_percent' => 10])->assertOk()->json('data');
        $this->assertSame('quoted', $q['status']);
        $doc = SalesDocument::withoutGlobalScopes()->with('lines')->find($q['quote']['document_id']);
        $this->assertEquals([$yomara->id, 'quote', 'validated', $req['number']], [$doc->company_id, $doc->type, $doc->status, $doc->reference]);
        $this->assertEquals([495000, 89100, 584100], [(float) $doc->subtotal, (float) $doc->tax_total, (float) $doc->total]);
        $this->assertCount(6, $doc->lines);
        $this->assertStringStartsWith('DEV', $doc->number);
        $this->assertSame('Clinique Les Palmiers', DB::table('partners')->where('id', $doc->partner_id)->value('name'));
        $this->assertEquals([584100, $doc->number, 'Yomara Technologies'], [$q['quote']['total'], $q['quote']['number'], $q['quote']['provider']]);
        $this->assertSame(1, AppNotification::where('user_id', $admin->id)->where('title', 'like', 'Devis de votre site : 584 100 F')->count());
        // Devis révisé : nouvelles lignes, l'ancien devis est annulé
        $q2 = $this->api($rootToken)->postJson('/api/admin/sites/requests/'.$req['id'].'/quote', ['lines' => [['description' => 'Site clinique bilingue avec rendez-vous', 'quantity' => 1, 'unit_price' => 500000]]])->assertOk()->json('data');
        $this->assertEquals(590000, $q2['quote']['total']);
        $this->assertSame('cancelled', SalesDocument::withoutGlobalScopes()->find($doc->id)->status);
        $this->assertSame(1, DB::table('partners')->where('company_id', $yomara->id)->where('name', 'Clinique Les Palmiers')->count());

        // Le client voit le devis et l'accepte : la plateforme est prévenue
        $mine = $api()->getJson('/api/sites/overview')->assertOk()->json('data.requests.0');
        $this->assertEquals([590000, 'quoted'], [$mine['quote']['total'], $mine['status']]);
        $api()->postJson('/api/sites/requests/'.$req['id'].'/decide', ['accept' => true])->assertOk()->assertJsonPath('data.status', 'accepted');
        $api()->postJson('/api/sites/requests/'.$req['id'].'/decide', ['accept' => true])->assertStatus(422);
        $this->assertSame(1, AppNotification::where('user_id', $root->id)->where('title', 'Devis accepté : Clinique Les Palmiers')->count());
        $this->api($rootToken)->postJson('/api/admin/sites/requests/'.$req['id'].'/progress')->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->api($rootToken)->postJson('/api/admin/sites/requests/'.$req['id'].'/deliver', ['template' => 'sante-elegance'])->assertOk()->assertJsonPath('data.status', 'delivered');
        $site = Site::withoutGlobalScopes()->where('company_id', $company->id)->first();
        $this->assertSame(['sante-elegance', SiteRequest::withoutGlobalScopes()->find($req['id'])->id], [$site->template, $site->request_id]);

        // Refus d'un devis par un autre client
        [, , $t2] = $this->makeCompany('Garage Express');
        $r2 = $this->api($t2)->postJson('/api/sites/requests', ['type' => 'custom', 'needs' => ['features' => ['ecommerce']]])->json('data');
        $this->api($rootToken)->postJson('/api/admin/sites/requests/'.$r2['id'].'/quote')->assertOk();
        $this->api($t2)->postJson('/api/sites/requests/'.$r2['id'].'/decide', ['accept' => false, 'reason' => 'Trop cher pour le moment'])->assertOk()->assertJsonPath('data.status', 'refused');
        $this->assertStringContainsString('Trop cher', SiteRequest::withoutGlobalScopes()->find($r2['id'])->admin_note);
        $list = $this->api($rootToken)->getJson('/api/admin/sites')->assertOk()->json();
        $this->assertSame(['Garage Express', 'Clinique Les Palmiers'], collect($list['requests'])->pluck('company.name')->all());
        $this->assertSame('Yomara Technologies', $list['settings']['provider_name']);
    }
}
