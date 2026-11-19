<?php

namespace Tests\Feature;

use App\Support\Localites;
use Tests\TestCase;

/** Villes et communes saisies sans accents ou en majuscules : ramenées au nom officiel pour les statistiques. */
class LocalitesTest extends TestCase
{
    public function test_canonical_names(): void
    {
        $this->assertSame('Bouaké', Localites::canonical('bouake'));
        $this->assertSame('Yopougon', Localites::canonical('  YOPOUGON '));
        $this->assertSame('Port-Bouët', Localites::canonical('port bouet'));
        $this->assertSame('San-Pédro', Localites::canonical('San Pedro'));
        $this->assertSame("M'Bahiakro", Localites::canonical('m’bahiakro'));
        $this->assertNull(Localites::canonical('Lyon'));
    }
}
