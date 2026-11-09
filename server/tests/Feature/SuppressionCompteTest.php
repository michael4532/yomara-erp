<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Google Play : politique de confidentialité publique, demande de suppression sans l'application et depuis l'application. */
class SuppressionCompteTest extends TestCase
{
    public function test_pages_publiques(): void
    {
        $this->get('/confidentialite')->assertOk()->assertSee('Politique de confidentialité')->assertSee('/suppression-compte');
        // Exigences YouTube API : conditions YouTube, règles Google, révocation de l'accès
        $this->get('/confidentialite')->assertSee('https://www.youtube.com/t/terms', false)->assertSee('https://policies.google.com/privacy', false)->assertSee('https://myaccount.google.com/permissions', false);
        $this->get('/conditions')->assertOk()->assertSee('id="services-connectes"', false)->assertSee('https://www.youtube.com/t/terms', false);
        $this->get('/sitemap.xml')->assertSee('/conditions', false);
        $this->get('/suppression-compte')->assertOk()->assertSee('Supprimer mon compte');
    }

    public function test_demande_web_enregistree(): void
    {
        Mail::fake();
        [, $u] = $this->makeCompany('Web Del');
        $this->post('/suppression-compte', ['email' => strtoupper($u->email), 'scope' => 'company', 'message' => 'Fermeture'])->assertRedirect('/suppression-compte');
        $r = DB::table('landing_requests')->where('type', 'suppression')->first();
        $this->assertSame(strtolower($u->email), $r->email);
        $this->assertStringContainsString('Toute mon entreprise', $r->message);
        $this->assertSame('Web Del', $r->company);
        $this->assertTrue($u->fresh()->is_active); // une demande web ne désactive rien : l'équipe vérifie d'abord
    }

    public function test_suppression_depuis_l_application(): void
    {
        Mail::fake();
        [, $u, $token] = $this->makeCompany('App Del');
        $this->api($token)->postJson('/api/me/delete-account', ['password' => 'mauvais', 'scope' => 'user'])->assertStatus(422);
        $this->assertTrue($u->fresh()->is_active);
        $this->api($token)->postJson('/api/me/delete-account', ['password' => 'secret123', 'scope' => 'user'])->assertOk()->assertJson(['signed_out' => true]);
        $this->assertFalse($u->fresh()->is_active);
        $this->assertSame(0, $u->tokens()->count());
        $this->assertSame(1, DB::table('landing_requests')->where('type', 'suppression')->where('email', $u->email)->count());
        $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'secret123'])->assertStatus(422);
    }

    public function test_entreprise_et_super_admin(): void
    {
        Mail::fake();
        [, $u, $token] = $this->makeCompany('Co Del');
        $this->api($token)->postJson('/api/me/delete-account', ['password' => 'secret123', 'scope' => 'company'])->assertOk()->assertJson(['signed_out' => false]);
        $this->assertTrue($u->fresh()->is_active);
        [, , $st] = $this->makeCompany('Editeur', 'entreprise', true);
        $this->api($st)->postJson('/api/me/delete-account', ['password' => 'secret123', 'scope' => 'user'])->assertStatus(422);
    }
}
