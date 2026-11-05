<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Favoris et menu enregistrés par utilisateur : retrouvés au démarrage et après une reconnexion. */
class UiPrefsTest extends TestCase
{
    public function test_ui_prefs_persist_per_user(): void
    {
        [, , $token] = $this->makeCompany('Prefs SARL');
        $api = fn () => $this->api($token);
        $this->assertSame([], (array) $api()->getJson('/api/bootstrap')->json('user.ui_prefs'));
        $api()->putJson('/api/me/ui-prefs', ['prefs' => ['nav_favorites' => ['ventes', 'stock', 'crm', 'compta', 'paie', 'rh'], 'nav_rail' => true, 'Bad Key' => ['x']]])->assertOk();
        $api()->putJson('/api/me/ui-prefs', ['prefs' => ['nav_open' => ['ventes']]])->assertOk();
        $p = $api()->getJson('/api/bootstrap')->json('user.ui_prefs');
        $this->assertSame(['ventes', 'stock', 'crm', 'compta', 'paie', 'rh'], $p['nav_favorites']);
        $this->assertTrue($p['nav_rail']);
        $this->assertSame(['ventes'], $p['nav_open']);
        $this->assertArrayNotHasKey('Bad Key', $p);
        $api()->putJson('/api/me/ui-prefs', ['prefs' => ['nav_rail' => null]])->assertOk();
        $this->assertArrayNotHasKey('nav_rail', $api()->getJson('/api/bootstrap')->json('user.ui_prefs'));
    }
}
