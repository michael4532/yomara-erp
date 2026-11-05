<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Fidelite\LoyaltyService;
use App\Modules\Fidelite\Models\LoyaltyCard;
use App\Modules\Fidelite\Models\LoyaltyTransaction;
use Tests\TestCase;

class FideliteTest extends TestCase
{
    public function test_points_tiers_redeem_gift_cards_expiry_rights_accounting_and_portal(): void
    {
        [$company, , $token] = $this->makeCompany('Boutique Chic');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/bootstrap')->assertOk()->assertJsonFragment(['lc_cards']);
        $api()->putJson('/api/company', ['settings' => ['fid_per_amount' => 1000, 'fid_point_value' => 10, 'fid_min_redeem' => 50, 'fid_welcome_points' => 20,
            'fid_tiers' => [['name' => 'Argent', 'min' => 300, 'multiplier' => 1.5]]]])->assertOk();
        $client = $api()->postJson('/api/core/partners', ['name' => 'Mme Aya Konan', 'type' => 'customer', 'phone' => '07 11 22 33 44'])->json('data.id');
        $invoice = function (float $ttc) use ($api, $client) {
            $d = $api()->postJson('/api/ventes/documents', ['type' => 'invoice', 'partner_id' => $client, 'date' => now()->toDateString(),
                'lines' => [['description' => 'Robe', 'quantity' => 1, 'unit_price' => $ttc, 'tax_rate' => 0]]])->json('data');

            return $api()->postJson("/api/ventes/documents/{$d['id']}/validate")->assertOk()->json('data');
        };
        $card = fn () => LoyaltyCard::withoutGlobalScopes()->where('partner_id', $client)->first();

        // Gains : carte créée au premier achat, bonus de bienvenue, niveau Argent (× 1,5)
        $invoice(59000);
        $this->assertSame(79, $card()->points);
        $big = $invoice(300000);
        $this->assertSame([379, 'Argent'], [$card()->points, $card()->tier]);
        $small = $invoice(10000);
        $this->assertSame(394, $card()->points);

        // Paiement en points : 1 point = 10 F
        $pay = fn (array $p) => $api()->postJson('/api/ventes/payments', $p + ['date' => now()->toDateString()]);
        $pay(['document_id' => $small['id'], 'amount' => 10000, 'method' => 'loyalty'])->assertStatus(422)->assertJsonFragment(['message' => 'Points insuffisants : 394 points disponibles, 1000 nécessaires.']);
        $p = $pay(['document_id' => $small['id'], 'amount' => 2000, 'method' => 'loyalty'])->assertCreated()->json('data');
        $this->assertSame($card()->number, $p['reference']);
        $this->assertSame(394 - 200 - 3, $card()->points); // et pas de points sur la part payée en points
        $this->assertSame(394 - 3, $card()->earned_12m); // l'utilisation ne fait pas baisser le niveau, le gain retiré si
        $api()->deleteJson("/api/ventes/payments/{$p['id']}")->assertOk();
        $this->assertSame([394, 394], [$card()->points, $card()->earned_12m]);
        // Annulation d'une facture : ses points sont retirés
        $api()->postJson("/api/ventes/documents/{$big['id']}/cancel")->assertOk();
        $this->assertSame([94, 94], [$card()->points, $card()->earned_12m]);

        // Cartes cadeaux
        $gift = $api()->postJson('/api/fidelite/gift-cards', ['initial_amount' => 25000, 'recipient_name' => 'Fatou', 'partner_id' => $client])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $gift['code']);
        $this->assertSame(25000.0, (float) $gift['balance']);
        $pay(['document_id' => $small['id'], 'amount' => 5000, 'method' => 'gift_card', 'reference' => 'XXXX-0000-YYYY'])->assertStatus(422);
        $gp = $pay(['document_id' => $small['id'], 'amount' => 10000, 'method' => 'gift_card', 'reference' => strtolower($gift['code'])])->assertCreated()->json('data');
        $this->assertSame(15000.0, (float) $api()->getJson("/api/fidelite/gift-cards/{$gift['id']}")->json('data.balance'));
        $other = $invoice(20000);
        $pay(['document_id' => $other['id'], 'amount' => 20000, 'method' => 'gift_card', 'reference' => $gift['code']])->assertStatus(422)->assertJsonFragment(['message' => 'Solde de la carte cadeau insuffisant : 15 000 restants.']);
        $api()->deleteJson("/api/ventes/payments/{$gp['id']}")->assertOk();
        $this->assertSame(25000.0, (float) $api()->getJson("/api/fidelite/gift-cards/{$gift['id']}")->json('data.balance'));
        $api()->putJson("/api/fidelite/gift-cards/{$gift['id']}", ['initial_amount' => 99999])->assertOk()->assertJsonPath('data.initial_amount', 25000);

        // Écriture comptable du paiement en points : escompte accordé (673)
        $p2 = $pay(['document_id' => $other['id'], 'amount' => 500, 'method' => 'loyalty'])->assertCreated();
        $rows = collect($api()->getJson('/api/compta/balance?to='.now()->addDay()->toDateString())->assertOk()->json('data.rows'));
        $this->assertSame(500.0, (float) ($rows->firstWhere('code', '673')['balance'] ?? 0));

        // Ajustement manuel, solde jamais négatif
        $pts = $card()->points;
        $api()->postJson('/api/fidelite/transactions', ['card_id' => $card()->id, 'type' => 'bonus', 'points' => 50, 'note' => 'Geste commercial'])->assertCreated();
        $this->assertSame($pts + 50, $card()->points);
        $api()->postJson('/api/fidelite/transactions', ['card_id' => $card()->id, 'type' => 'adjust', 'points' => -100000, 'note' => 'Erreur'])->assertStatus(422);

        // Expiration des points échus
        LoyaltyTransaction::withoutGlobalScopes()->where('card_id', $card()->id)->where('remaining', '>', 0)->update(['expires_at' => now()->subDay()->toDateString()]);
        app()->instance('tenant', $company->fresh());
        $this->assertGreaterThan(0, app(LoyaltyService::class)->expire($company->fresh()));
        $this->assertSame(0, $card()->points);

        // La caisse inscrit un client (bonus de bienvenue) mais ne touche pas aux points
        $cashier = User::create(['company_id' => $company->id, 'name' => 'Caisse', 'email' => 'caisse@chic.ci', 'password' => 'secret123',
            'role_id' => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Caissier')->value('id')]);
        $capi = fn () => $this->api($cashier->createToken('c')->plainTextToken);
        $newc = $capi()->postJson('/api/core/partners', ['name' => 'M. Traoré', 'type' => 'customer', 'phone' => '0505050505'])->json('data.id');
        $c2 = $capi()->postJson('/api/fidelite/cards', ['partner_id' => $newc])->assertCreated()->json('data');
        $this->assertSame(20, LoyaltyCard::withoutGlobalScopes()->find($c2['id'])->points);
        $capi()->postJson('/api/fidelite/cards', ['partner_id' => $newc])->assertStatus(422);
        $capi()->postJson('/api/fidelite/transactions', ['card_id' => $c2['id'], 'type' => 'bonus', 'points' => 500, 'note' => 'x'])->assertForbidden();
        $this->assertSame($c2['number'], $capi()->getJson('/api/fidelite/lookup?q=05 05 05 05 05')->assertOk()->json('card.number'));

        // Statistiques et portail client
        $this->assertSame(2, $api()->getJson('/api/fidelite/stats')->assertOk()->json('members'));
        $url = $api()->postJson("/api/portail/partners/$client/invite", ['channel' => 'none'])->json('url');
        $this->flushHeaders();
        app()->forgetInstance('tenant');
        $this->get(parse_url($url, PHP_URL_PATH).'/fidelite')->assertOk()->assertSee($card()->number)->assertSee('Argent')->assertSee($gift['code']);
    }
}
