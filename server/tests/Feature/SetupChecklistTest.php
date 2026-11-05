<?php

namespace Tests\Feature;

use Tests\TestCase;

class SetupChecklistTest extends TestCase
{
    public function test_super_admin_setup_checklist(): void
    {
        config(['erp.mode' => 'saas']);
        [, , $admin] = $this->makeCompany('Yomara', 'entreprise', superAdmin: true);
        [, , $client] = $this->makeCompany('Client SARL', 'starter');
        $r = $this->api($admin)->getJson('/api/admin/setup')->assertOk()->json();
        $this->assertGreaterThanOrEqual(0, $r['progress']);
        $keys = collect($r['sections'])->pluck('items')->flatten(1)->pluck('key')->all();
        foreach (['migrations', 'cron', 'smtp', 'online', 'plans', 'contact', 'ai', 'social'] as $k) {
            $this->assertContains($k, $keys);
        }
        $this->flushHeaders();
        $this->api($client)->getJson('/api/admin/setup')->assertForbidden();
    }
}
