<?php

namespace Tests\Feature;

use Tests\TestCase;

class AppSmartLinkTest extends TestCase
{
    public function test_phones_and_mac_go_to_their_store(): void
    {
        $ua = [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148' => 'apps.apple.com',
            'Mozilla/5.0 (Linux; Android 15; SM-S921B) AppleWebKit/537.36 Chrome/131.0 Mobile Safari/537.36' => 'play.google.com',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Safari/605.1.15' => 'apps.apple.com',
        ];
        foreach ($ua as $agent => $host) {
            $r = $this->withHeader('User-Agent', $agent)->get('/application-mobile');
            $r->assertRedirect();
            $this->assertStringContainsString($host, $r->headers->get('Location'));
        }
    }

    public function test_pc_gets_store_linux_and_open_source_choice(): void
    {
        $win = $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/131.0')->get('/application-mobile');
        $win->assertOk()->assertSee('Microsoft Store')->assertSee('Linux')->assertSee('open source')->assertSee('apps.microsoft.com', false);
        $this->assertLessThan(strpos($win->getContent(), '>Linux'), strpos($win->getContent(), '>Microsoft Store'));

        $lin = $this->withHeader('User-Agent', 'Mozilla/5.0 (X11; Linux x86_64) Firefox/133.0')->get('/application-mobile');
        $lin->assertOk();
        $this->assertLessThan(strpos($lin->getContent(), '>Microsoft Store'), strpos($lin->getContent(), '>Linux'));
    }
}
