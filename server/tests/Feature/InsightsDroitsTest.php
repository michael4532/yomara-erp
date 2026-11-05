<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/** Tableau de bord : un commercial voit son chiffre d'affaires, pas la marge, les dépenses ni la trésorerie. */
class InsightsDroitsTest extends TestCase
{
    public function test_finance_figures_hidden_from_sales_staff(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Droits SARL');
        $boss = $this->api($token)->getJson('/api/dashboard/insights')->assertOk()->json();
        $this->assertTrue($boss['finance']);
        $this->assertNotNull($boss['kpis']['margin']);

        $sales = User::create(['company_id' => $company->id, 'name' => 'Koffi', 'email' => 'koffi@droits.ci', 'password' => 'secret123',
            'role_id' => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Commercial')->value('id')]);
        $t = $sales->createToken('t')->plainTextToken;
        $this->flushHeaders();
        $d = $this->api($t)->getJson('/api/dashboard/insights')->assertOk()->json();
        $this->assertFalse($d['finance']);
        $this->assertNull($d['kpis']['margin']);
        $this->assertNull($d['kpis']['expenses']);
        $this->assertNull($d['kpis']['cash']);
        $this->assertNotNull($d['kpis']['revenue']);
    }
}
