<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Domaine court des liens : yma.test/abc12 ne montre ni l'adresse du logiciel ni la destination (masquage). */
class LiensDomaineCourtTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('LINKS_DOMAIN=yma.test');
        $_ENV['LINKS_DOMAIN'] = $_SERVER['LINKS_DOMAIN'] = 'yma.test';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('LINKS_DOMAIN');
        unset($_ENV['LINKS_DOMAIN'], $_SERVER['LINKS_DOMAIN']);
        parent::tearDown();
    }

    public function test_short_domain_links(): void
    {
        [, , $token] = $this->makeCompany('Court SARL');
        $l = $this->api($token)->postJson('/api/liens/links', ['kind' => 'short', 'title' => 'Inscription', 'target_url' => 'https://selab.ci/fr/visitor-registration', 'cloak' => false])->assertSuccessful()->json();
        $this->assertSame('https://yma.test/'.$l['slug'], $l['short_url']);
        $this->assertSame(5, strlen($l['slug']));
        // le domaine court sert le lien à la racine
        $this->get('http://yma.test/'.$l['slug'])->assertRedirect('https://selab.ci/fr/visitor-registration');
        $this->get('http://yma.test/')->assertRedirect();
        // un lien vers le domaine court lui-même est refusé (boucle)
        $this->api($token)->postJson('/api/liens/links', ['kind' => 'short', 'target_url' => 'https://yma.test/'.$l['slug']])->assertStatus(422);
    }
}
