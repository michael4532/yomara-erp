<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Module;
use App\Models\ModuleOrder;
use App\Models\PaymentGateway;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Retours des premiers tests réels : commandes en double, notifications, Paystack, activation manuelle, mot de passe. */
class StoreFixesTest extends TestCase
{
    protected string $sa;

    protected int $saUser;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.mode' => 'saas']);
        // Ces tests achètent le CRM : on le sort du socle gratuit (sinon il est compris d'office dans toutes les formules)
        \App\Models\Plan::where('code', 'gratuit')->get()->each->update(['modules' => ['ventes']]);
        [, $u, $this->sa] = $this->makeCompany('Yomara', 'entreprise', superAdmin: true);
        $this->saUser = $u->id;
        Module::where('key', 'crm')->update(['billing_mode' => 'both', 'price_monthly' => 10000]);
        Plan::where('code', 'starter')->update(['modules' => ['ventes']]);
    }

    protected function bank(): int
    {
        return $this->api($this->sa)->postJson('/api/admin/payment-gateways', ['driver' => 'bank_transfer', 'label' => 'Virement', 'instructions' => 'RIB'])->json('id');
    }

    public function test_offline_order_cannot_be_placed_twice_and_admin_is_notified(): void
    {
        $bank = $this->bank();
        [$c, , $t] = $this->makeCompany('Boutique', 'starter');
        $this->api($t)->postJson('/api/store/orders', ['module' => 'crm', 'billing' => 'monthly', 'gateway_id' => $bank])->assertCreated();
        for ($i = 0; $i < 3; $i++) {
            $this->api($t)->postJson('/api/store/orders', ['module' => 'crm', 'billing' => 'monthly', 'gateway_id' => $bank])->assertStatus(422);
        }
        $this->assertSame(1, ModuleOrder::where('company_id', $c->id)->where('module_key', 'crm')->count());
        $this->assertTrue(AppNotification::where('user_id', $this->saUser)->where('title', 'Nouvelle commande à encaisser')->exists());

        // Validée par l'éditeur : client prévenu, puis plus de nouvelle commande tant que le module est actif
        $id = ModuleOrder::where('company_id', $c->id)->value('id');
        $this->api($this->sa)->postJson('/api/admin/store/orders/'.$id.'/approve')->assertOk();
        $this->assertTrue(AppNotification::where('company_id', $c->id)->where('title', 'like', '%est activé')->exists());
        $this->api($t)->postJson('/api/store/orders', ['module' => 'crm', 'billing' => 'monthly', 'gateway_id' => $bank])->assertStatus(422);
        // Module compris dans la formule : rien à acheter
        $this->api($t)->postJson('/api/store/orders', ['module' => 'ventes', 'billing' => 'monthly', 'gateway_id' => $bank])->assertStatus(422);
    }

    public function test_online_gateway_of_editor_company_is_offered_in_store(): void
    {
        $editor = \App\Services\SiteTraffic::editorCompany();
        PaymentGateway::create(['company_id' => $editor->id, 'driver' => 'paystack', 'label' => 'Paystack', 'mode' => 'live', 'credentials' => ['secret_key' => 'sk_live_x'], 'enabled' => true]);
        PaymentGateway::create(['company_id' => $editor->id, 'driver' => 'bank_transfer', 'label' => 'Virement de la société', 'mode' => 'live', 'enabled' => true]);
        [, , $other] = $this->makeCompany('Autre', 'entreprise');
        PaymentGateway::create(['company_id' => \App\Models\User::where('email', 'like', 'autre-%')->value('company_id'), 'driver' => 'stripe', 'label' => 'Stripe client', 'mode' => 'live', 'enabled' => true]);
        [, , $t] = $this->makeCompany('Client', 'starter');
        $labels = collect($this->api($t)->getJson('/api/store')->json('payment_methods'))->pluck('label');
        $this->assertTrue($labels->contains('Paystack'));
        $this->assertFalse($labels->contains('Virement de la société')); // ses moyens hors ligne servent à ses propres clients
        $this->assertFalse($labels->contains('Stripe client'));
    }

    public function test_admin_grants_module_and_plan_manually(): void
    {
        Mail::fake();
        [$c, , $t] = $this->makeCompany('Cadeau', 'starter');
        $stamp = $this->api($t)->postJson('/api/presence/ping')->json('stamp');
        $this->api($t)->getJson('/api/admin/companies')->assertForbidden();
        $this->api($this->sa)->postJson('/api/admin/companies/'.$c->id.'/grant', ['type' => 'module', 'key' => 'crm', 'months' => 3, 'note' => 'Payé au bureau'])->assertOk();
        $this->assertTrue($c->fresh()->hasModule('crm'));
        $this->assertSame(1, ModuleOrder::where('company_id', $c->id)->where('status', 'paid')->where('number', 'like', 'MAN-%')->count());
        $this->assertTrue(AppNotification::where('company_id', $c->id)->where('title', 'like', '%CRM%activé%')->orWhere(fn ($q) => $q->where('company_id', $c->id)->where('title', 'like', '%activé'))->exists());
        // L'app voit que ses modules ont changé (rechargement automatique du menu)
        $this->assertNotSame($stamp, $this->api($t)->postJson('/api/presence/ping')->json('stamp'));

        $pro = Plan::where('code', 'professional')->first();
        $this->api($this->sa)->postJson('/api/admin/companies/'.$c->id.'/grant', ['type' => 'plan', 'plan_id' => $pro->id, 'months' => 12])->assertOk();
        $c->refresh();
        $this->assertSame($pro->id, $c->plan_id);
        $this->assertTrue($c->subscription_ends_at->gt(now()->addMonths(11)));
    }

    public function test_welcome_mail_has_no_password_and_reset_link_works(): void
    {
        Mail::fake();
        $html = view('landing.mail-welcome', ['appName' => 'Yomara ERP', 'name' => 'Awa', 'email' => 'awa@b.ci', 'company' => 'B', 'plan' => null, 'trialDays' => 14, 'url' => 'https://x',
            'currency' => 'XOF', 'platforms' => [], 'downloads' => [], 'contact' => ['name' => '', 'phone' => '', 'email' => '']])->render();
        $this->assertStringNotContainsString('Secret123', $html);

        [, $u] = $this->makeCompany('Oubli', 'starter');
        $this->postJson('/api/auth/forgot', ['email' => 'inconnu@x.ci'])->assertOk(); // même réponse : pas d'énumération
        $this->postJson('/api/auth/forgot', ['email' => $u->email])->assertOk();
        $row = DB::table('password_reset_tokens')->where('email', mb_strtolower($u->email))->first();
        $this->assertNotNull($row);
        $this->get('/mot-de-passe/'.str_repeat('a', 64).'?email='.urlencode($u->email))->assertSee('expiré');
        // Le jeton en clair n'est jamais stocké : on simule le lien reçu
        $token = str_repeat('b', 64);
        DB::table('password_reset_tokens')->where('email', mb_strtolower($u->email))->update(['token' => \Illuminate\Support\Facades\Hash::make($token)]);
        $u->createToken('vieux');
        $this->post('/mot-de-passe/'.$token, ['email' => $u->email, 'password' => 'Nouveau2026', 'password_confirmation' => 'Nouveau2026'])->assertOk()->assertSee('changé');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Nouveau2026', $u->fresh()->password));
        $this->assertSame(0, $u->tokens()->count());
        $this->post('/mot-de-passe/'.$token, ['email' => $u->email, 'password' => 'Autre20266', 'password_confirmation' => 'Autre20266'])->assertStatus(422); // usage unique
    }

    public function test_trial_offer_reminders_and_disabled_modules(): void
    {
        Mail::fake();
        // Offre spéciale : 30 jours jusqu'à la fin du mois, puis retour à la durée standard
        $this->api($this->sa)->putJson('/api/admin/trial-offer', ['standard_days' => 14, 'promo_days' => 30, 'promo_until' => now()->addDays(10)->toDateString(), 'promo_label' => 'Offre de lancement'])
            ->assertOk()->assertJsonPath('days', 30);
        $this->get('/')->assertSee('Offre de lancement')->assertSee('30 jours d');
        $this->post('/inscription', ['company_name' => 'Essai SARL', 'name' => 'Ama', 'email' => 'ama@essai.ci', 'password' => 'MotDePasse1', 'password_confirmation' => 'MotDePasse1', 'plan' => 'business', 'terms' => '1'])->assertRedirect();
        $c = \App\Models\Company::where('name', 'Essai SARL')->first();
        $this->assertSame('trial', $c->status);
        $this->assertEqualsWithDelta(30, now()->diffInDays($c->trial_ends_at), 1);
        $this->assertTrue(AppNotification::where('company_id', $c->id)->where('title', 'like', 'Bienvenue%')->where('body', 'like', '%Modules inclus%')->exists());

        // Rappel une seule fois par palier
        $this->travel(24)->days();
        $this->assertSame(1, \App\Platform\SubscriptionLifecycle::remind());
        $this->assertSame(0, \App\Platform\SubscriptionLifecycle::remind());
        $this->assertTrue(AppNotification::where('company_id', $c->id)->where('title', 'like', 'Votre essai gratuit se termine%')->exists());
        $token = \App\Models\User::where('email', 'ama@essai.ci')->first()->createToken('t')->plainTextToken;
        $this->assertSame('trial', $this->api($token)->getJson('/api/bootstrap')->json('subscription.kind'));

        // Échéance : formule gratuite, modules perdus listés (notification + bandeau)
        $this->travel(8)->days();
        $this->artisan('erp:plans-downgrade')->assertSuccessful();
        $n = AppNotification::where('company_id', $c->id)->where('title', 'Votre essai gratuit est terminé')->first();
        $this->assertNotNull($n);
        $this->assertStringContainsString('Modules :', (string) $n->body);
        $b = $this->api($token)->getJson('/api/bootstrap')->json('subscription');
        $this->assertSame('expired', $b['kind']);
        $this->assertNotEmpty($b['modules']);
    }
}
