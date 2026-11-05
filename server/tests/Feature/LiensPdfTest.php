<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LiensPdfTest extends TestCase
{
    protected string $t;

    protected function setUp(): void
    {
        parent::setUp();
        [, , $this->t] = $this->makeCompany('Ivoire Spot');
    }

    public function test_short_link_redirects_counts_and_protects(): void
    {
        $l = $this->api($this->t)->postJson('/api/liens/links', ['target_url' => 'ivoirespot.com/agenda', 'slug' => 'Agenda Nov', 'utm' => ['utm_source' => 'whatsapp']])->assertCreated()->json();
        $this->assertSame('agenda-nov', $l['slug']);
        $this->api($this->t)->postJson('/api/liens/links', ['target_url' => 'https://x.ci', 'slug' => 'agenda-nov'])->assertUnprocessable(); // alias pris
        $this->api($this->t)->postJson('/api/liens/links', ['target_url' => 'http://127.0.0.1/admin'])->assertUnprocessable();
        $this->api($this->t)->postJson('/api/liens/links', ['target_url' => 'javascript:alert(1)'])->assertUnprocessable();

        $ua = ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Mobile Safari', 'CF-IPCountry' => 'CI', 'Referer' => 'https://www.facebook.com/'];
        $this->withHeaders($ua)->get('/l/agenda-nov')->assertRedirect('https://ivoirespot.com/agenda?utm_source=whatsapp');
        $this->withHeaders($ua)->get('/l/agenda-nov?q=1')->assertRedirect();
        $this->withHeaders(['User-Agent' => 'WhatsApp/2.23'])->get('/l/agenda-nov')->assertRedirect(); // aperçu : non compté
        $this->flushHeaders();
        $row = DB::table('lk_links')->where('slug', 'agenda-nov')->first();
        $this->assertSame(2, (int) $row->clicks_count);
        $this->assertSame(1, (int) $row->unique_count);
        $s = $this->api($this->t)->getJson('/api/liens/stats?link='.$l['id'])->assertOk()->json();
        $this->assertSame(1, $s['qr_scans']);
        $this->assertSame(2, $s['countries']['CI']);
        $this->assertSame(2, $s['devices']['mobile']);
        $this->assertSame(2, $s['referrers']['facebook.com']);

        // Mot de passe et limite de visites
        $this->api($this->t)->putJson('/api/liens/links/'.$l['id'], ['target_url' => 'https://ivoirespot.com', 'password' => 'secret', 'max_clicks' => 3])->assertOk();
        $this->get('/l/agenda-nov')->assertOk()->assertSee('Lien protégé');
        $this->post('/l/agenda-nov', ['password' => 'faux'])->assertStatus(422);
        $this->post('/l/agenda-nov', ['password' => 'secret'])->assertRedirect('https://ivoirespot.com');
        $this->withHeaders($ua)->get('/l/agenda-nov')->assertStatus(410); // 3 visites atteintes

        // Isolation : une autre société ne voit ni ne modifie ce lien
        [, , $other] = $this->makeCompany('Autre');
        $this->api($other)->putJson('/api/liens/links/'.$l['id'], ['target_url' => 'https://evil.example'])->assertUnprocessable();
        $this->api($other)->getJson('/api/liens/stats?link='.$l['id'])->assertNotFound();
    }

    public function test_smartlink_device_routing_and_platform_page(): void
    {
        $this->api($this->t)->postJson('/api/liens/links', ['kind' => 'smart', 'slug' => 'appli', 'target_url' => 'https://yomara.ci',
            'rules' => ['devices' => ['ios' => 'https://apps.apple.com/app/1', 'android' => 'https://play.google.com/store/apps/details?id=ci.y'], 'countries' => ['SN' => 'https://yomara.sn']]])->assertCreated();
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Linux; Android 14) Mobile'])->get('/l/appli')->assertRedirect('https://play.google.com/store/apps/details?id=ci.y');
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)'])->get('/l/appli')->assertRedirect('https://apps.apple.com/app/1');
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0)', 'CF-IPCountry' => 'SN'])->get('/l/appli')->assertRedirect('https://yomara.sn');
        $this->flushHeaders();
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0)'])->get('/l/appli')->assertRedirect('https://yomara.ci');

        $p = $this->api($this->t)->postJson('/api/liens/links', ['kind' => 'page', 'title' => 'Nouveau single', 'slug' => 'single', 'page' => ['subtitle' => 'Disponible partout',
            'buttons' => [['url' => 'https://open.spotify.com/track/1'], ['url' => 'https://audiomack.com/a/song/b', 'label' => 'Audiomack'], ['url' => 'https://youtu.be/x']]]])->assertCreated()->json();
        $this->assertSame('spotify', $p['page']['buttons'][0]['platform']);
        $ua = ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Mobile Safari'];
        $this->withHeaders($ua)->get('/l/single')->assertOk()->assertSee('Nouveau single')->assertSee('Écouter')->assertSee('og:title', false);
        $this->withHeaders($ua)->get('/l/single/b/1')->assertRedirect('https://audiomack.com/a/song/b');
        $this->withHeaders($ua)->get('/l/single/b/9')->assertNotFound();
        $s = $this->api($this->t)->getJson('/api/liens/stats?link='.$p['id'])->json();
        $this->assertSame('Audiomack', $s['buttons'][0]['label']);
        $this->assertEquals(100, $s['ctr']);
    }

    public function test_qr_codes_of_any_type_and_dynamic(): void
    {
        $png = $this->api($this->t)->postJson('/api/liens/qrs/render', ['type' => 'wifi', 'payload' => ['ssid' => 'Boutique', 'password' => 'p@ss;1', 'security' => 'WPA'], 'style' => ['fg' => '#123456', 'dots' => 'dots']])
            ->assertOk()->json();
        $this->assertSame('WIFI:T:WPA;S:Boutique;P:p@ss\;1;;', $png['data']);
        $this->assertStringStartsWith('data:image/png;base64,', $png['png']);
        $v = $this->api($this->t)->postJson('/api/liens/qrs', ['name' => 'Carte Michael', 'type' => 'vcard', 'payload' => ['first_name' => 'Michael', 'last_name' => 'Tanon', 'phone' => '+225 07 00 00 00 00', 'company' => 'Yomara']])
            ->assertCreated()->json();
        $this->assertStringContainsString('TEL;TYPE=CELL:+2250700000000', $v['data']);
        $this->api($this->t)->get('/api/liens/qrs/'.$v['id'].'/svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertSame("\x89PNG", substr($this->api($this->t)->get('/api/liens/qrs/'.$v['id'].'/png?size=400')->getContent(), 0, 4));

        // QR dynamique : passe par un lien suivi, les scans sont comptés
        $d = $this->api($this->t)->postJson('/api/liens/qrs', ['name' => 'Menu', 'type' => 'url', 'payload' => ['url' => 'https://menu.ci'], 'dynamic' => true])->assertCreated()->json();
        $this->assertTrue($d['dynamic']);
        $this->assertStringContainsString('/l/'.$d['link']['slug'].'?q=1', $d['data']);
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone)'])->get('/l/'.$d['link']['slug'].'?q=1')->assertRedirect('https://menu.ci');
        $this->assertSame(1, collect($this->api($this->t)->getJson('/api/liens/qrs')->json('data'))->firstWhere('id', $d['id'])['scans']);
        $this->api($this->t)->postJson('/api/liens/qrs/render', ['type' => 'url', 'payload' => ['url' => 'javascript:x']])->assertUnprocessable();
    }

    protected function pdf(int $pages = 3): UploadedFile
    {
        $p = new \TCPDF();
        $p->setPrintHeader(false);
        $p->setPrintFooter(false);
        for ($i = 1; $i <= $pages; $i++) {
            $p->AddPage();
            $p->SetFont('helvetica', '', 20);
            $p->Cell(0, 20, 'Page '.$i, 0, 1);
        }

        return UploadedFile::fake()->createWithContent('rapport.pdf', $p->Output('', 'S'));
    }

    protected function pageCount(string $bytes): int
    {
        $f = tempnam(sys_get_temp_dir(), 't');
        file_put_contents($f, $bytes);
        $n = (new \setasign\Fpdi\Tcpdf\Fpdi())->setSourceFile(\App\Modules\Pdf\PdfNormalizer::normalize($f, $f.'.n.pdf'));
        @unlink($f);

        return $n;
    }

    public function test_pdf_tools(): void
    {
        $r = $this->api($this->t)->post('/api/pdf/merge', ['files' => [$this->pdf(2), $this->pdf(3)]])->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertSame(5, $this->pageCount($r->getContent()));

        $r = $this->api($this->t)->post('/api/pdf/organize', ['files' => [$this->pdf(4)], 'options' => json_encode(['order' => '4,1-2', 'rotations' => ['1' => 90]])])->assertOk();
        $this->assertSame(3, $this->pageCount($r->getContent()));

        $r = $this->api($this->t)->post('/api/pdf/split', ['files' => [$this->pdf(5)], 'options' => json_encode(['every' => 2])])->assertOk();
        $this->assertSame('application/zip', $r->headers->get('Content-Type'));

        $this->api($this->t)->post('/api/pdf/watermark', ['files' => [$this->pdf(2)], 'options' => json_encode(['text' => 'CONFIDENTIEL', 'mode' => 'tile', 'opacity' => .2])])->assertOk();
        $this->api($this->t)->post('/api/pdf/numbers', ['files' => [$this->pdf(2)], 'options' => json_encode(['position' => 'bottom-right'])])->assertOk();
        $sig = UploadedFile::fake()->image('signature.png', 300, 100);
        $this->api($this->t)->post('/api/pdf/annotate', ['files' => [$this->pdf(1)], 'images' => [$sig], 'options' => json_encode(['items' => [
            ['type' => 'text', 'page' => 1, 'x' => 10, 'y' => 80, 'text' => 'Lu et approuvé, Abidjan', 'size' => 12], ['type' => 'image', 'page' => 1, 'x' => 60, 'y' => 82, 'width' => 25, 'image_index' => 0]]])])->assertOk();

        // Éditeur visuel : remplacer un texte, masquer, surligner, formes, dessin (signature), image, suppression et rotation de pages
        $r = $this->api($this->t)->post('/api/pdf/edit', ['files' => [$this->pdf(3)], 'images' => [UploadedFile::fake()->image('cachet.png', 200, 200)], 'options' => json_encode([
            'items' => [
                ['type' => 'text', 'page' => 1, 'x' => .1, 'y' => .1, 'w' => .5, 'h' => .03, 'text' => 'Montant : 250 000 F CFA', 'size' => .018, 'bold' => true, 'bg' => '#FFFFFF', 'color' => '#0A1633'],
                ['type' => 'whiteout', 'page' => 1, 'x' => .1, 'y' => .3, 'w' => .3, 'h' => .02],
                ['type' => 'highlight', 'page' => 1, 'x' => .1, 'y' => .4, 'w' => .4, 'h' => .02],
                ['type' => 'rect', 'page' => 1, 'x' => .5, 'y' => .5, 'w' => .2, 'h' => .1, 'color' => '#E5484D'],
                ['type' => 'ellipse', 'page' => 1, 'x' => .2, 'y' => .5, 'w' => .2, 'h' => .1],
                ['type' => 'arrow', 'page' => 1, 'x' => .1, 'y' => .7, 'x2' => .4, 'y2' => .75],
                ['type' => 'ink', 'page' => 3, 'strokes' => [[[.6, .8], [.62, .82], [.66, .79], [.7, .83]], [[.75, .8]]], 'color' => '#1D4ED8'],
                ['type' => 'image', 'page' => 3, 'x' => .1, 'y' => .8, 'w' => .15, 'h' => .1, 'image_index' => 0, 'keep_ratio' => true],
                ['type' => 'image', 'page' => 1, 'x' => .1, 'y' => .8, 'w' => .1, 'h' => .1, 'image' => '/etc/passwd'], // chemin client ignoré
            ],
            'pages' => ['delete' => [2], 'rotate' => ['3' => 90]],
        ])])->assertOk();
        $this->assertSame(2, $this->pageCount($r->getContent()));
        $this->api($this->t)->post('/api/pdf/edit', ['files' => [$this->pdf(1)], 'options' => json_encode(['items' => [], 'pages' => ['delete' => [1]]])])->assertUnprocessable();
        // le filigrane n'accepte plus de chemin d'image fourni par le client
        $this->api($this->t)->post('/api/pdf/watermark', ['files' => [$this->pdf(1)], 'options' => json_encode(['text' => 'x', 'image' => '/etc/hostname'])])->assertOk();

        $enc = $this->api($this->t)->post('/api/pdf/protect', ['files' => [$this->pdf(1)], 'options' => json_encode(['password' => 'Abidjan2026', 'allow' => ['print']])])->assertOk()->getContent();
        $this->assertStringContainsString('/Encrypt', $enc);
        $this->assertStringContainsString('/AESV3', $enc); // AES-256
        $this->api($this->t)->post('/api/pdf/watermark', ['files' => [UploadedFile::fake()->createWithContent('p.pdf', $enc)], 'options' => json_encode(['text' => 'x'])])->assertUnprocessable();
        $this->api($this->t)->post('/api/pdf/merge', ['files' => [UploadedFile::fake()->createWithContent('faux.pdf', '<html>pas un pdf</html>')]])->assertUnprocessable();

        $img = $this->api($this->t)->post('/api/pdf/images', ['images' => [UploadedFile::fake()->image('a.jpg', 800, 600), UploadedFile::fake()->image('b.png', 600, 900)]])->assertOk()->getContent();
        $this->assertSame(2, $this->pageCount($img));
        $this->assertSame(3, $this->api($this->t)->post('/api/pdf/info', ['files' => [$this->pdf(3)]])->assertOk()->json('pages'));
    }
}
