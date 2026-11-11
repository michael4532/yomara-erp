<?php

namespace Tests;

use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Services\CompanySetup;
use App\Support\ModuleRegistry;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlatformSeeder::class);
        // le client de test annonce « en-us » : les pages publiques restent en français sauf demande explicite
        config(['erp.web_browser_locale' => false]);
        // Double authentification du super administrateur : testée dans TwoFactorTest, hors du chemin des autres tests
        config(['erp.two_factor.super_admin' => false]);
    }

    /** Crée une société + son administrateur, renvoie [company, user, token]. */
    protected function makeCompany(string $name = 'Acme', ?string $plan = 'entreprise', bool $superAdmin = false): array
    {
        ['company' => $c, 'user' => $u] = app(CompanySetup::class)->create(
            ['name' => $name],
            ['name' => 'Admin '.$name, 'email' => Str::slug($name).'-'.Str::random(4).'@test.ci', 'password' => 'secret123', 'is_super_admin' => $superAdmin],
            $plan ? Plan::where('code', $plan)->first() : null,
        );
        app(ModuleRegistry::class)->flush();
        $token = $u->createToken('test')->plainTextToken;

        return [$c, $u, $token];
    }

    protected function api(string $token, string $platform = 'windows'): static
    {
        app()->forgetInstance('tenant');
        app(ModuleRegistry::class)->flush();
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json', 'X-Client-Platform' => $platform]);
    }

    protected function uuid(): string
    {
        return (string) Str::uuid();
    }
}
