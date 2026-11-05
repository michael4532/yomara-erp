<?php

namespace Tests\Feature;

use App\Services\ProductionCheck;
use Tests\TestCase;

/** Contrôle de production : réservé au super admin, témoin de la tâche cron, commande erp:doctor. */
class ProductionCheckTest extends TestCase
{
    public function test_production_check_endpoint_heartbeat_and_command(): void
    {
        @unlink(storage_path(ProductionCheck::HEARTBEAT));
        [, , $token] = $this->makeCompany('Plateforme', 'entreprise', true);
        [, , $other] = $this->makeCompany('Client SARL');
        $this->api($other)->getJson('/api/admin/system/checks')->assertForbidden();

        $d = $this->api($token)->getJson('/api/admin/system/checks')->assertOk()->json('data');
        $cron = collect($d['checks'])->firstWhere('group', 'Tâches planifiées');
        $this->assertSame('fail', $cron['state']);
        $this->assertFalse($d['ready']);
        $this->assertSame('ok', collect($d['checks'])->firstWhere('label', 'Clé de l\'application (APP_KEY)')['state']);

        ProductionCheck::beat();
        $cron = collect($this->api($token)->getJson('/api/admin/system/checks')->json('data.checks'))->firstWhere('group', 'Tâches planifiées');
        $this->assertSame('ok', $cron['state']);
        $this->assertStringStartsWith('Dernier passage de la tâche cron', $cron['label']);

        $this->artisan('erp:doctor')->expectsOutputToContain('Tâches planifiées')->assertExitCode(1); // débogage actif en test
        @unlink(storage_path(ProductionCheck::HEARTBEAT));
    }
}
