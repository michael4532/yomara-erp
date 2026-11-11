<?php

namespace Tests\Feature;

use App\Modules\Mailbox\Mime;
use App\Support\HtmlSanitizer;
use App\Support\Utf8Html;
use Tests\TestCase;

class SpecialCharactersTest extends TestCase
{
    public function test_accents_survive_html_cleaning_whatever_the_declared_charset(): void
    {
        // HTML d'Outlook : <meta> en Windows-1252 alors que le texte a déjà été converti en UTF-8
        $outlook = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=windows-1252"></head><body><p>Bien reçu, envoyé à été — « OK » 🙂</p></body></html>';
        $this->assertStringContainsString('Bien reçu, envoyé à été — « OK » 🙂', Mime::sanitize($outlook));
        $this->assertStringContainsString('Bien reçu, envoyé à été — « OK » 🙂', HtmlSanitizer::clean($outlook));
        $this->assertStringContainsString('Déjà payé', HtmlSanitizer::clean('<p>Déjà payé</p>'));

        // Message brut en ISO-8859-1 (sujet encodé, corps quoted-printable, <meta charset>)
        $raw = "From: a@b.ci\r\nTo: c@d.ci\r\nSubject: =?iso-8859-1?Q?Bien_re=E7u?=\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=iso-8859-1\r\n"
            ."Content-Transfer-Encoding: quoted-printable\r\n\r\n<html><head><meta charset=3D\"iso-8859-1\"></head><body>Bien re=E7u, envoy=E9</body></html>\r\n";
        $p = Mime::parse($raw);
        $this->assertSame('Bien reçu', $p['subject']);
        $this->assertStringContainsString('Bien reçu, envoyé', Mime::sanitize($p['html']));

        // Texte non UTF-8 (Windows-1252) converti
        $this->assertSame('café', Utf8Html::toUtf8("caf\xE9"));

        // Réparation des messages déjà abîmés, sans toucher aux textes corrects
        $this->assertSame('Bien reçu, envoyé à la banque', Utf8Html::fixMojibake('Bien reÃ§u, envoyÃ© Ã la banque'));
        $this->assertSame('Été ’ ok', Utf8Html::fixMojibake('Ã‰tÃ© â€™ ok'));
        $this->assertSame('Déjà vu et reçu', Utf8Html::fixMojibake('Déjà vu et reÃ§u'));
        foreach (['naïve café', 'Âge 100 €', 'ÉLÉMENT Ü', ''] as $ok) {
            $this->assertSame($ok, Utf8Html::fixMojibake($ok));
        }
    }
}
