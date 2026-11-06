<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Models\User;
use App\Modules\Agenda\Models\Event;
use Tests\TestCase;

/** Contrôles de sécurité des modules A à D : isolation des sociétés, contournements de circuit, cumuls. */
class AuditModulesADTest extends TestCase
{
    private function pusher(callable $api): callable
    {
        return fn (string $entity, array $data, ?string $id = null) => $api()->postJson('/api/sync/push', ['mutations' => [[
            'id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data,
        ]]])->assertOk()->json('results.0');
    }

    public function test_foreign_ids_are_refused_or_hidden_across_companies(): void
    {
        [, $adminB] = $this->makeCompany('Autre Société');
        $foreignPartner = $this->api($this->makeCompany('Troisième')[2])->postJson('/api/core/partners', ['name' => 'Client secret', 'type' => 'supplier'])->json('data.id');
        [$company, $admin, $token] = $this->makeCompany('Société A');
        $api = fn () => $this->api($token);

        // Analytique : la liste des utilisateurs ne montre que ceux de la société
        $ids = collect($api()->getJson('/api/analytique/users')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($admin->id));
        $this->assertFalse($ids->contains($adminB->id));

        // Comptabilité : tiers d'une autre société refusé dans une écriture et dans les comptes mémorisés
        $api()->getJson('/api/compta/setup')->assertOk();
        $acc = \App\Modules\Compta\Models\Account::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('code')->pluck('id');
        $journal = \App\Modules\Compta\Models\Journal::withoutGlobalScopes()->where('company_id', $company->id)->value('id');
        $api()->postJson('/api/compta/entries', ['journal_id' => $journal, 'date' => now()->toDateString(), 'label' => 'Test', 'lines' => [
            ['account_id' => $acc[0], 'debit' => 100, 'partner_id' => $foreignPartner], ['account_id' => $acc[1], 'credit' => 100]]])->assertStatus(422);
        $api()->postJson('/api/compta/rules', ['scope' => 'supplier', 'ref_id' => $foreignPartner, 'account_code' => '6011'])->assertStatus(422);

        // Approvisionnement : responsable d'une location et fournisseur d'un dossier d'import contrôlés
        $api()->postJson('/api/appro/rentals', ['title' => 'Grue', 'start_date' => now()->toDateString(), 'end_date' => now()->addDays(3)->toDateString(), 'responsible_id' => $adminB->id])->assertStatus(422);
        $api()->postJson('/api/appro/imports', ['title' => 'Import', 'partner_id' => $foreignPartner])->assertStatus(422);

        // Agenda : un utilisateur d'une autre société n'est jamais invité (ni notifié)
        $push = $this->pusher($api);
        $ev = $this->uuid();
        $r = $push('ag_events', ['title' => 'Réunion', 'starts_at' => now()->addDay()->format('Y-m-d 10:00:00'), 'ends_at' => now()->addDay()->format('Y-m-d 11:00:00'), 'attendee_ids' => [$adminB->id]], $ev);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $this->assertSame([], Event::withoutGlobalScopes()->find($ev)->attendee_ids);
        $this->assertFalse(AppNotification::where('user_id', $adminB->id)->where('ref', $ev)->exists());

        // Agenda : déplacer un élément inexistant ne crée rien
        $api()->putJson('/api/agenda/overlay/task/'.$this->uuid(), ['starts_at' => now()->toDateString()])->assertNotFound();
    }

    public function test_repeated_lines_cannot_bypass_limits_and_edited_orders_lose_approval(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Quincaillerie');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $push = $this->pusher($api);

        // Billetterie : un même tarif répété sur plusieurs lignes est cumulé
        $ev = $this->uuid();
        $this->assertSame('applied', $push('ev_events', ['name' => 'Salon', 'start_date' => now()->toDateString(), 'end_date' => now()->addDay()->toDateString(), 'status' => 'open', 'ticketing_enabled' => true], $ev)['status']);
        $type = $push('ev_ticket_types', ['event_id' => $ev, 'name' => 'Entrée', 'price' => 1000, 'quantity' => 5])['record'];
        $r = $push('ev_ticket_orders', ['event_id' => $ev, 'buyer_name' => 'X', 'payment_method' => 'cash', 'items' => [['type_id' => $type['id'], 'quantity' => 3], ['type_id' => $type['id'], 'quantity' => 3]]]);
        $this->assertSame('rejected', $r['status']);
        $this->assertStringContainsString('Plus que 5', $r['message']);

        // Achats : bon de commande approuvé puis gonflé → nouvelle approbation nécessaire
        $settings = $company->settings;
        $settings['purchase_approval_limit'] = 1000;
        $company->forceFill(['settings' => $settings])->save();
        $buyer = User::create(['company_id' => $company->id, 'name' => 'Acheteur', 'email' => 'acheteur@q.ci', 'password' => 'secret123',
            'role_id' => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Acheteur')->value('id')]);
        $bapi = fn () => $this->api($buyer->createToken('b')->plainTextToken);
        $sup = $api()->postJson('/api/core/partners', ['name' => 'Fournisseur', 'type' => 'supplier'])->json('data.id');
        $line = fn (float $qty) => ['id' => $this->uuid(), 'description' => 'Ciment', 'quantity' => $qty, 'unit_price' => 500, 'tax_rate' => 0];
        $po = $bapi()->postJson('/api/achats/documents', ['type' => 'purchase_order', 'partner_id' => $sup, 'date' => now()->toDateString(), 'lines' => [$line(4)]])->assertCreated()->json('data');
        $api()->postJson("/api/achats/documents/{$po['id']}/approve")->assertOk();
        $bapi()->putJson("/api/achats/documents/{$po['id']}", ['lines' => [$line(400)]])->assertOk();
        $bapi()->postJson("/api/achats/documents/{$po['id']}/validate")->assertStatus(422);

        // Réception : la même ligne répétée ne dépasse pas la quantité commandée
        $po = $api()->postJson("/api/achats/documents/{$po['id']}/validate")->assertOk()->json('data');
        $ol = $po['lines'][0]['id'];
        $api()->postJson('/api/achats/receipts', ['order_id' => $po['id'], 'date' => now()->toDateString(),
            'lines' => [['order_line_id' => $ol, 'quantity' => 300], ['order_line_id' => $ol, 'quantity' => 300]]])->assertStatus(422);
    }
}
