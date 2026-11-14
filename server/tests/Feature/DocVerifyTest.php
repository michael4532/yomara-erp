<?php

namespace Tests\Feature;

use App\Support\DocVerify;
use Tests\TestCase;

/** Code QR d'authenticité : la facture en ligne, signée, impossible à deviner. */
class DocVerifyTest extends TestCase
{
    public function test_qr_link_shows_online_invoice(): void
    {
        [$company, , $token] = $this->makeCompany('Akwaba Vérif');
        $id = $this->uuid();
        $r = $this->api($token)->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $id,
            'data' => ['type' => 'invoice', 'date' => now()->toDateString(), 'status' => 'validated', 'partner_name' => 'Sandrine Kouassi',
                'lines' => [['id' => $this->uuid(), 'description' => 'Conception du site', 'quantity' => 1, 'unit_price' => 100000, 'tax_rate' => 18]]]]]])->assertOk()->json('results.0');
        $this->assertSame('applied', $r['status'], json_encode($r));

        $url = DocVerify::url($company->id, $id);
        $path = parse_url($url, PHP_URL_PATH);
        $page = $this->get($path)->assertOk();
        $page->assertSee('Authentique')->assertSee('Sandrine Kouassi')->assertSee('Conception du site')->assertSee('118 000');

        // Lien forgé ou document d'une autre société : refusé
        $this->get('/v/'.$id.'/'.str_repeat('0', 20))->assertNotFound()->assertSee('pas authentique', false);
        $this->get('/v/'.$this->uuid().'/'.DocVerify::token((string) $company->id, $this->uuid()))->assertNotFound();

        // Le lien part avec le document vers l'application (code QR imprimé)
        $pulled = collect($this->api($token)->getJson('/api/sync/pull?cursor=0&limit=2000')->json('changes.sales_documents.upserts') ?? [])->firstWhere('id', $id);
        $this->assertSame($url, $pulled['verify_url'] ?? null);
    }
}
