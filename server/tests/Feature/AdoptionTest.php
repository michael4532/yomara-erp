<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Tableau de bord d'adoption : statut, modules utilisés, modules payés inutilisés, réservé au super administrateur. */
class AdoptionTest extends TestCase
{
    public function test_adoption_report(): void
    {
        [, , $saToken] = $this->makeCompany('Editeur', 'entreprise', true);
        [$active, $au, $aToken] = $this->makeCompany('Boutique Active');
        [$sleepy, $su] = $this->makeCompany('Boutique Endormie');
        [$fresh] = $this->makeCompany('Nouveau Client');

        // Société active : des ventes saisies (synchronisation) et un écran de la compta ouvert
        $p = $this->api($aToken)->postJson('/api/core/partners', ['name' => 'Client', 'type' => 'customer'])->assertCreated()->json('data.id');
        $this->api($aToken)->postJson('/api/ventes/documents', ['type' => 'quote', 'partner_id' => $p, 'date' => now()->toDateString(), 'status' => 'draft',
            'lines' => [['id' => $this->uuid(), 'description' => 'X', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 0]]])->assertCreated();
        $this->api($aToken)->getJson('/api/compta/dashboard');
        DB::table('platform_activity')->insertOrIgnore(['day' => now()->toDateString(), 'user_id' => $au->id, 'company_id' => $active->id, 'platform' => 'web', 'first_at' => now(), 'last_at' => now(), 'hits' => 5]);
        // Société endormie : dernière activité il y a 20 jours, un module payant activé il y a un mois et jamais ouvert
        DB::table('platform_activity')->insert(['day' => now()->subDays(20)->toDateString(), 'user_id' => $su->id, 'company_id' => $sleepy->id, 'platform' => 'web', 'first_at' => now()->subDays(20), 'last_at' => now()->subDays(20), 'hits' => 1]);
        DB::table('modules')->where('key', 'paie')->update(['price_monthly' => 25000]);
        DB::table('company_modules')->updateOrInsert(['company_id' => $sleepy->id, 'module_key' => 'paie'], ['enabled' => true, 'source' => 'purchase', 'created_at' => now()->subDays(30), 'updated_at' => now()]);
        // L'inscription récente n'a aucune activité
        DB::table('companies')->where('id', $fresh->id)->update(['created_at' => now()->subDays(2)]);

        $this->api($aToken)->getJson('/api/admin/adoption')->assertForbidden();
        $r = $this->api($saToken)->getJson('/api/admin/adoption')->assertOk()->json();
        $rows = collect($r['companies'])->keyBy('name');
        $this->assertArrayNotHasKey('Editeur', $rows->all()); // l'éditeur n'est pas un client

        $a = $rows['Boutique Active'];
        $this->assertSame('active', $a['status']);
        $used = collect($a['modules_used'])->pluck('key')->all();
        $this->assertContains('ventes', $used);
        $this->assertContains('compta', $used);
        $this->assertGreaterThan(0, $a['records_30d']);

        $s = $rows['Boutique Endormie'];
        $this->assertSame('risk', $s['status']);
        $this->assertSame(20, $s['idle_days']);
        $this->assertContains('paie', collect($s['idle_paid_modules'])->pluck('key')->all());
        $this->assertStringContainsString('20 jours', $s['advice']);

        $this->assertSame('new', $rows['Nouveau Client']['status']);
        $this->assertGreaterThan($s['score'], $a['score']);
        $this->assertSame(1, $r['kpis']['risk']);
        $this->assertNotEmpty(collect($r['modules'])->firstWhere('key', 'ventes'));
    }
}
