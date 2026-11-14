<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Modules\Social\SocialOAuth;
use Tests\TestCase;

/** Applications Meta récentes (Facebook Login for Business) : la connexion passe par config_id au lieu de scope. */
class MetaConfigIdTest extends TestCase
{
    public function test_meta_uses_config_id_when_set(): void
    {
        [$c] = $this->makeCompany('Démo Meta');
        $u = User::where('company_id', $c->id)->first();
        PlatformSetting::put('social.apps', ['meta' => ['client_id' => '111', 'client_secret' => encrypt('s')]]);
        $q = [];
        parse_str(parse_url(app(SocialOAuth::class)->authorizeUrl($c, $u, 'meta'), PHP_URL_QUERY), $q);
        $this->assertStringContainsString('pages_manage_posts', $q['scope']);
        $this->assertArrayNotHasKey('config_id', $q);

        PlatformSetting::put('social.apps', ['meta' => ['client_id' => '111', 'client_secret' => encrypt('s'), 'config_id' => '1648560790262871']]);
        parse_str(parse_url(app(SocialOAuth::class)->authorizeUrl($c, $u, 'meta'), PHP_URL_QUERY), $q);
        $this->assertSame('1648560790262871', $q['config_id']);
        $this->assertArrayNotHasKey('scope', $q);
    }
}
