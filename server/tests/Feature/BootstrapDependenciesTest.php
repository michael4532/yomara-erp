<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Compte limité à la Billetterie (borne d'accueil) : il reçoit aussi les événements dont la billetterie dépend. */
class BootstrapDependenciesTest extends TestCase
{
    public function test_dependency_tables_are_synced(): void
    {
        [$company, $user, $token] = $this->makeCompany('Salon');
        foreach (['evenements', 'billetterie'] as $k) {
            \App\Models\CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => $k], ['enabled' => true]);
        }
        app(\App\Support\ModuleRegistry::class)->flush();
        $user->forceFill(['modules_denied' => ['evenements']])->save();
        $r = $this->api($token)->getJson('/api/bootstrap')->assertOk();
        $this->assertNotContains('evenements', $r->json('enabled_modules'));
        $this->assertContains('ev_events', $r->json('sync_entities'));
        $this->assertContains('ev_tickets', $r->json('sync_entities'));
    }
}
