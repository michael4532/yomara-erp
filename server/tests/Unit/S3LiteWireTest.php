<?php

namespace Tests\Unit;

use App\Storage\S3Lite;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Ce qui part réellement sur le réseau doit être exactement ce qui a été signé (sinon R2, B2… répondent 403). */
class S3LiteWireTest extends TestCase
{
    public function test_signed_headers_are_sent_once_and_unchanged(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $c = new S3Lite('https://s3.us-east-005.backblazeb2.com', 'us-east-005', 'b', 'AK', 'SK');
        $c->put('yomara-test/a.txt', 'hello', 'text/plain');
        $c->get('yomara-test/a.txt');
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->header('Content-Type') === ['text/plain'] && $r->body() === 'hello'
            && str_contains($r->header('Authorization')[0], 'SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date'));
        Http::assertSent(fn ($r) => $r->method() === 'GET' && ! str_contains($r->header('Authorization')[0], 'content-type'));
    }

    /** Signature identique à celle d'AWS (botocore) pour la même requête. */
    public function test_signature_matches_reference(): void
    {
        $c = new S3Lite('https://s3.us-east-005.backblazeb2.com', 'us-east-005', 'yomara-erp-documents', 'AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY');
        $c->fixedNow = '20261114T230000Z';
        $h = $c->signedHeaders('PUT', 'yomara-test/abc.txt', 'hello', ['Content-Type' => 'text/plain']);
        $this->assertStringEndsWith('Signature=7384f6008fb0ce403927c91f1cd7a218c229c759a97253e23b9b5eeec806f874', $h['Authorization']);
    }
}
