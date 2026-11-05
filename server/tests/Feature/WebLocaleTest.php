<?php

namespace Tests\Feature;

use App\Support\HtmlTranslator;
use App\Support\Tr;
use Tests\TestCase;

class WebLocaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.mode' => 'saas', 'erp.web_browser_locale' => true]);
        $this->makeCompany('Yomara', 'entreprise', superAdmin: true);
    }

    public function test_public_site_in_english_by_choice_cookie_or_browser(): void
    {
        $fr = $this->withHeaders(['Accept-Language' => 'en-US,en;q=0.9,fr;q=0.8'])->get('/')->assertOk(); // le français dès qu'il est accepté
        $this->flushHeaders();
        $this->assertStringContainsString('lang="fr"', $fr->getContent());
        $this->assertStringContainsString('Créer mon entreprise', $fr->getContent());

        $en = $this->get('/?lang=en')->assertOk()->assertCookie('yl_lang', 'en');
        $html = $en->getContent();
        $this->assertStringContainsString('lang="en"', $html);
        $this->assertStringContainsString('Create my company', $html);
        $this->assertStringNotContainsString('>Créer mon entreprise<', $html);
        // pas de traduction « à moitié » par un gabarit trop court
        $this->assertStringNotContainsString('On logiciel', $html);

        $this->withCookie('yl_lang', 'en')->get('/tarifs')->assertOk()->assertSee('Pricing', false);
        $this->flushHeaders();
        $this->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])->get('/modules')->assertOk()->assertSee('All modules', false);
        $this->flushHeaders();
        $this->withHeaders(['Accept-Language' => 'fr-CI,fr;q=0.9'])->get('/contact')->assertOk()->assertSee('Contact', false);
        foreach (['/inscription', '/telecharger', '/contact'] as $p) {
            $this->get($p.'?lang=en')->assertOk();
        }
    }

    public function test_translator_helpers(): void
    {
        $this->assertSame('Your free trial ends in 3 days', Tr::f('Votre essai gratuit se termine dans {} jours', [3], 'en'));
        $this->assertSame('Votre essai gratuit se termine dans 3 jours', Tr::f('Votre essai gratuit se termine dans {} jours', [3], 'fr'));
        $this->assertSame('Choose Business', Tr::strict('Choisir Business', 'en'));
        $this->assertNull(Tr::strict('Le logiciel que personne ne connaît encore vraiment bien', 'en'));
        $out = HtmlTranslator::translate('<html lang="fr"><p>Tous les modules</p><input placeholder="Nom complet"><script>const a = "Tous les modules", b = \'Résultats\';</script></html>', 'en');
        $this->assertStringContainsString('<p>All modules</p>', $out);
        $this->assertStringContainsString('placeholder="Full name"', $out);
        $this->assertStringContainsString('"All modules"', $out);
        $this->assertStringContainsString("'Results'", $out);
    }
}
