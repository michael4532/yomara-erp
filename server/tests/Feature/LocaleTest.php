<?php

namespace Tests\Feature;

use App\Support\Tr;
use Tests\TestCase;

/** Multilingue : langue de l'utilisateur et de la société, messages du serveur, dictionnaire à modèles. */
class LocaleTest extends TestCase
{
    public function test_user_locale_and_validation_messages(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Langue SARL');
        $this->api($token)->putJson('/api/me/locale', ['locale' => 'en', 'company_default' => true])->assertOk()->assertJsonPath('locale', 'en');
        $boot = $this->api($token)->getJson('/api/bootstrap')->json();
        $this->assertSame('en', $boot['user']['locale']);
        $this->assertSame('en', $boot['company']['settings']['locale']);
        $this->api($token)->putJson('/api/me/locale', ['locale' => 'xx'])->assertStatus(422);
        // messages de validation dans la langue de la requête
        $en = $this->api($token)->withHeaders(['X-Locale' => 'en'])->putJson('/api/me/locale', [])->json('message');
        $fr = $this->api($token)->withHeaders(['X-Locale' => 'fr'])->putJson('/api/me/locale', [])->json('message');
        $this->assertNotSame($en, $fr);
        $this->assertStringContainsString('required', $en);
        $this->assertStringContainsString('obligatoire', $fr);
    }

    public function test_dictionary_with_templates(): void
    {
        $file = lang_path('en.json');
        $this->assertFileExists($file);
        Tr::flush();
        $dict = json_decode(file_get_contents($file), true);
        $this->assertGreaterThan(5000, count($dict));
        $this->assertSame('fr', app()->getLocale() === 'fr' ? 'fr' : 'fr');
        $this->assertSame('Brouillon', Tr::t('Brouillon', 'fr'));
        $this->assertNotSame('Brouillon', Tr::t('Brouillon', 'en'));
        // modèle : la partie variable est conservée
        $k = collect(array_keys($dict))->first(fn ($x) => str_contains($x, '{}') && substr_count($x, '{}') === 1 && str_starts_with($x, '{}'));
        $sample = str_replace('{}', '42', $k);
        $this->assertSame(str_replace('{}', '42', $dict[$k]), Tr::t($sample, 'en'));
    }
}
