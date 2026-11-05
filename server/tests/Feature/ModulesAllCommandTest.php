<?php

namespace Tests\Feature;

use App\Models\CompanyModule;
use App\Models\Module;
use Tests\TestCase;

class ModulesAllCommandTest extends TestCase
{
    public function test_modules_all_enables_every_module_without_expiry(): void
    {
        [$company] = $this->makeCompany('Démo', null);
        $key = Module::where('is_core', false)->value('key');
        CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => $key], ['enabled' => false, 'expires_at' => now()->subDay()]);
        Module::where('key', $key)->update(['is_active' => false]);

        $this->artisan('erp:modules-all', ['company' => $company->id])->assertSuccessful();

        app(\App\Support\ModuleRegistry::class)->flush();
        $this->assertSame(Module::count(), count($company->fresh()->enabledModules()));
        $this->assertNull(CompanyModule::where(['company_id' => $company->id, 'module_key' => $key])->value('expires_at'));
    }
}
