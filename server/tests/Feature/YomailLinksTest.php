<?php

namespace Tests\Feature;

use App\Modules\Mailbox\Composer;
use App\Modules\Mailbox\LinkTracking;
use App\Modules\Mailbox\Models\Account;
use App\Modules\Mailbox\Models\Folder;
use App\Modules\Mailbox\Models\Message;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** YoMail : lien intelligent /yomail, lien de l'application sous les e-mails, suivi des clics. */
class YomailLinksTest extends TestCase
{
    public function test_yomail_smart_link_by_device(): void
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/131.0')->get('/yomail')
            ->assertRedirect(url('/app/').'?ecran=mailbox');
        $and = $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 15; SM-S921B) Chrome/131.0 Mobile')->get('/yomail')->assertOk()->getContent();
        $this->assertStringContainsString('intent://mail#Intent;scheme=yomara;package=com.yomaratechnologies.yomara_erp', $and);
        $this->assertStringContainsString('play.google.com', urldecode($and));
        $ios = $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Mobile/15E148')->get('/yomail')->assertOk()->getContent();
        $this->assertStringContainsString('yomara://mail', $ios);
        $this->assertStringContainsString('apps.apple.com', $ios);
        $this->assertStringContainsString('?ecran=mailbox', $ios);
    }

    public function test_click_tracking_footer_and_stats(): void
    {
        [$company, $user, $token] = $this->makeCompany('Liens SARL');
        app()->instance('tenant', $company);
        $a = Account::withoutGlobalScopes()->create(['company_id' => $company->id, 'user_id' => $user->id, 'provider' => 'imap', 'email' => 'awa@liens.ci', 'display_name' => 'Awa', 'status' => 'paused', 'settings' => []]);
        DB::table('mb_settings')->updateOrInsert(['company_id' => $company->id], ['app_link_footer' => true, 'created_at' => now(), 'updated_at' => now()]);

        $payload = ['to' => [['email' => 'client@exemple.ci']], 'subject' => 'Votre devis', 'track_clicks' => true,
            'html' => '<p>Voir <a href="https://exemple.ci/devis?id=5&amp;x=1">le devis</a>, <a href="mailto:a@b.ci">écrire</a>, <a href="https://exemple.ci/unsubscribe">désinscription</a></p>'];
        $mail = LinkTracking::apply($a, Composer::build($a, $payload, $user), $payload, 'outbox-1');
        $this->assertStringContainsString('Envoyé avec YoMail', $mail['html']);
        $this->assertStringContainsString(url('/yomail'), $mail['html']);
        $this->assertStringContainsString('href="mailto:a@b.ci"', $mail['html']);
        $this->assertStringContainsString('href="https://exemple.ci/unsubscribe"', $mail['html']);
        preg_match('#href="([^"]*/y/c/[^"]+)"#', $mail['html'], $m);
        $tracked = html_entity_decode($m[1]);
        // Même envoi rejoué : même jeton
        $again = LinkTracking::apply($a, Composer::build($a, $payload, $user), $payload, 'outbox-1');
        $this->assertStringContainsString(explode('?', $tracked)[0], $again['html']);

        $path = parse_url($tracked, PHP_URL_PATH).'?'.parse_url($tracked, PHP_URL_QUERY);
        $this->get($path)->assertRedirect('https://exemple.ci/devis?id=5&x=1');
        $this->get($path)->assertRedirect();
        // Adresse modifiée par un tiers : refusée (pas de redirection ouverte)
        $evil = preg_replace('/u=[^&]+/', 'u='.rtrim(strtr(base64_encode('https://pirate.example'), '+/', '-_'), '='), $path);
        $this->get($evil)->assertNotFound();

        $folder = Folder::withoutGlobalScopes()->create(['account_id' => $a->id, 'remote_id' => 'Sent', 'name' => 'Envoyés', 'role' => 'sent']);
        $msg = Message::withoutGlobalScopes()->create(['company_id' => $company->id, 'account_id' => $a->id, 'folder_id' => $folder->id, 'remote_id' => 's1', 'subject' => 'Votre devis',
            'message_id' => $again['message_id'], 'from_email' => 'awa@liens.ci', 'date' => now()]);
        $stats = $this->api($token)->getJson('/api/mailbox/messages/'.$msg->id.'/clicks')->assertOk()->json('data');
        $this->assertSame(2, $stats['clicks']);
        $this->assertSame([['https://exemple.ci/devis?id=5&x=1', 2]], array_map(fn ($l) => [$l['url'], $l['clicks']], $stats['links']));

        // Sans suivi demandé : liens d'origine
        $plain = LinkTracking::apply($a, Composer::build($a, ['track_clicks' => false] + $payload, $user), ['track_clicks' => false] + $payload, 'outbox-2');
        $this->assertStringNotContainsString('/y/c/', $plain['html']);
    }

    public function test_settings_toggle(): void
    {
        [, , $token] = $this->makeCompany('Reglages SARL');
        $this->api($token)->putJson('/api/mailbox/settings', ['app_link_footer' => true, 'track_clicks' => true])->assertOk()
            ->assertJsonPath('data.app_link_footer', true)->assertJsonPath('data.track_clicks', true)->assertJsonPath('data.app_link', url('/yomail'));
    }
}
