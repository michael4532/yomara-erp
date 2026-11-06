<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\ImportExport\Sheet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Audit des modules E à M : chaque test vérifie que l'attaque corrigée ne passe plus. */
class SecurityModulesEtoMTest extends TestCase
{
    protected function limitedUser($company, array $permissions): string
    {
        $role = Role::create(['company_id' => $company->id, 'name' => 'Limité '.uniqid(), 'permissions' => $permissions]);
        $u = User::create(['company_id' => $company->id, 'role_id' => $role->id, 'name' => 'Limité', 'email' => uniqid('lim').'@test.ci', 'password' => 'secret123', 'is_active' => true]);

        return $u->createToken('t')->plainTextToken;
    }

    public function test_fne_base_url_cannot_target_internal_network(): void
    {
        [, , $token] = $this->makeCompany('FNE Sûre');
        $api = $this->api($token);
        foreach (['http://169.254.169.254/latest/meta-data', 'http://127.0.0.1:8080/ws', 'http://localhost/ws'] as $bad) {
            $api->putJson('/api/fne/settings', ['environment' => 'prod', 'base_url' => $bad])->assertStatus(422);
        }
        $api->putJson('/api/fne/settings', ['environment' => 'prod', 'base_url' => 'https://fne.dgi.gouv.ci/ws'])->assertOk();
    }

    public function test_import_requires_the_business_permission_of_each_type(): void
    {
        [$company, , $token] = $this->makeCompany('Import Sûr');
        $lim = $this->api($this->limitedUser($company, ['import_export.import']));
        $this->assertSame([], $lim->getJson('/api/import_export/entities')->assertOk()->json('data.imports'));
        $path = sys_get_temp_dir().'/'.uniqid().'_stock.xlsx';
        Sheet::write($path, ['Article', 'Quantité'], [['COCA33', 1000]]);
        $lim = $this->api($this->limitedUser($company, ['import_export.import']));
        $lim->post('/api/import_export/analyze', ['entity' => 'stock', 'file' => new UploadedFile($path, 'stock.xlsx', null, null, true)])->assertForbidden();
        $lim = $this->api($this->limitedUser($company, ['import_export.import']));
        $lim->get('/api/import_export/template/invoices')->assertForbidden();

        // un import analysé par l'administrateur ne peut pas être lancé par un utilisateur sans le droit du module
        $a = $this->api($token)->post('/api/import_export/analyze', ['entity' => 'stock', 'file' => new UploadedFile($path, 'stock.xlsx', null, null, true)])->assertOk()->json('data');
        $lim = $this->api($this->limitedUser($company, ['import_export.import', 'core.products.manage']));
        $lim->postJson("/api/import_export/jobs/{$a['job_id']}/run", ['mapping' => ['product' => 0, 'quantity' => 1], 'mode' => 'upsert', 'key' => 'sku'])->assertForbidden();
        // articles : la colonne de stock demande le droit de gérer le stock
        $p = $this->api($token)->post('/api/import_export/analyze', ['entity' => 'products', 'file' => new UploadedFile($path, 'p.xlsx', null, null, true)])->assertOk()->json('data');
        $lim = $this->api($this->limitedUser($company, ['import_export.import', 'core.products.manage']));
        $lim->postJson("/api/import_export/jobs/{$p['job_id']}/run", ['mapping' => ['name' => 0, 'stock' => 1], 'mode' => 'upsert', 'key' => 'name'])->assertForbidden();
        $this->assertSame(0, DB::table('stock_moves')->where('company_id', $company->id)->count());
    }

    public function test_property_management_refuses_foreign_owner_and_treasury(): void
    {
        [, , $tokenA] = $this->makeCompany('Immo A');
        [$companyB, , $tokenB] = $this->makeCompany('Immo B');
        $pB = $this->api($tokenB)->postJson('/api/immobilier/properties', ['name' => 'Villa B', 'owner_name' => 'Secret Owner', 'owner_phone' => '0700000000', 'owner_email' => 'secret@b.ci'])
            ->assertCreated()->json('data');
        $api = fn () => $this->api($tokenA);
        $s = $api()->getJson('/api/immobilier/statement?owner_id='.$pB['owner_id'])->assertOk()->json('data');
        $this->assertNull($s['owner']);
        $this->assertStringNotContainsString('Secret Owner', json_encode($s));
        $api()->postJson('/api/immobilier/payouts', ['owner_id' => $pB['owner_id'], 'period_from' => '2026-01-01', 'period_to' => '2026-01-31', 'amount' => 1000])->assertStatus(422);
        $api()->getJson('/api/immobilier/rents?month=nimporte')->assertStatus(422);
        $acc = (string) \Illuminate\Support\Str::uuid();
        DB::table('tr_accounts')->insert(['id' => $acc, 'company_id' => $companyB->id, 'name' => 'Caisse B', 'kind' => 'cash', 'created_at' => now(), 'updated_at' => now()]);
        $api()->postJson('/api/immobilier/rents/999/pay', ['treasury_account_id' => $acc])->assertStatus(422)->assertJsonValidationErrors('treasury_account_id');
        $api()->postJson('/api/ecommerce/orders/999/pay', ['treasury_account_id' => $acc])->assertStatus(422)->assertJsonValidationErrors('treasury_account_id');
    }

    public function test_fleet_and_funds_refuse_ids_of_another_company(): void
    {
        [, , $tokenA] = $this->makeCompany('Parc A');
        [, $adminB, $tokenB] = $this->makeCompany('Parc B');
        $vB = $this->api($tokenB)->postJson('/api/flotte/records/vehicles', ['title' => 'Prado B', 'plate' => 'B-1', 'kind' => 'pickup', 'odometer' => 1000])->assertCreated()->json('data');
        $start = now()->addDay()->setTime(8, 0);
        $trip = $this->api($tokenA)->postJson('/api/flotte/records/trips', ['title' => 'Visite', 'planned_start' => $start->toDateTimeString(), 'planned_end' => $start->copy()->addHours(2)->toDateTimeString()])
            ->assertCreated()->json('data');
        $this->api($tokenA)->postJson("/api/flotte/trips/{$trip['id']}/approve", ['vehicle_id' => $vB['id']])->assertStatus(422);
        $this->assertSame('available', DB::table('fl_vehicles')->where('id', $vB['id'])->value('status'));

        $this->api($tokenA)->postJson('/api/fonds/requests', ['title' => 'Achat', 'approver_id' => $adminB->id, 'lines' => [['description' => 'X', 'quantity' => 1, 'unit_amount' => 1000]]])
            ->assertStatus(422)->assertJsonValidationErrors('approver_id');
    }

    public function test_link_password_is_rate_limited(): void
    {
        [, , $token] = $this->makeCompany('Liens Sûrs');
        $this->api($token)->postJson('/api/liens/links', ['target_url' => 'https://ivoirespot.com', 'slug' => 'prive', 'password' => 'bon-mot'])->assertCreated();
        for ($i = 0; $i < 10; $i++) {
            $this->post('/l/prive', ['password' => 'faux'.$i])->assertStatus(422);
        }
        $this->post('/l/prive', ['password' => 'bon-mot'])->assertStatus(429);
    }
}
