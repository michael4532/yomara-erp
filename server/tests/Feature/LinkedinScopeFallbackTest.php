<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Modules\Social\SocialOAuth;
use Tests\TestCase;

/** LinkedIn refuse les droits « pages entreprise » : la connexion repart aussitôt pour le profil seul. */
class LinkedinScopeFallbackTest extends TestCase
{
    public function test_unauthorized_org_scope_retries_without_it(): void
    {
        [$c] = $this->makeCompany('Démo LinkedIn');
        PlatformSetting::put('social.apps', ['linkedin' => ['client_id' => 'cid', 'client_secret' => encrypt('sec'), 'organizations' => true]]);
        $u = User::where('company_id', $c->id)->first();
        $first = app(SocialOAuth::class)->authorizeUrl($c, $u, 'linkedin');
        $this->assertStringContainsString('w_organization_social', urldecode($first));
        parse_str(parse_url($first, PHP_URL_QUERY), $q);

        $r = $this->get('/oauth/social/linkedin/retour?'.http_build_query(['state' => $q['state'], 'error' => 'unauthorized_scope_error',
            'error_description' => 'Scope "w_organization_social" is not authorized for your application']));
        $r->assertRedirect();
        $to = urldecode($r->headers->get('Location'));
        $this->assertStringStartsWith('https://www.linkedin.com/oauth/v2/authorization', $to);
        $this->assertStringContainsString('w_member_social', $to);
        $this->assertStringNotContainsString('w_organization_social', $to);

        // Lien déjà utilisé : plus de nouvelle tentative, message d'erreur
        $this->get('/oauth/social/linkedin/retour?'.http_build_query(['state' => $q['state'], 'error' => 'unauthorized_scope_error', 'error_description' => 'organization']))
            ->assertStatus(422)->assertSee('refusé la connexion');
    }
}
