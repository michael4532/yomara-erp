<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/** Mentions légales du site : identité de l'éditeur lue sur la fiche de sa société. */
class LegalNoticeTest extends TestCase
{
    public function test_legal_notice_shows_editor_identity_from_company_record(): void
    {
        [$company] = $this->makeCompany('Yomara Technologies', 'entreprise', true);
        $company->forceFill(['trade_register' => 'CI-ABJ-03-2026-B13-16786', 'tax_id' => '2612345 A', 'address' => 'Yopougon Maroc, Antenne', 'city' => 'Abidjan', 'country' => 'CI',
            'settings' => array_merge((array) $company->settings, ['legal_form' => 'SARLU', 'capital' => 1000000, 'legal_director' => 'Michael Tanon'])])->save();

        $page = $this->get('/mentions-legales')->assertOk()->getContent();
        foreach (['Yomara Technologies', 'SARLU', '1 000 000 F CFA', 'CI-ABJ-03-2026-B13-16786', '2612345 A', 'Yopougon Maroc, Antenne, Abidjan', 'Michael Tanon'] as $s) {
            $this->assertStringContainsString($s, $page);
        }
        $this->get('/conditions')->assertOk()->assertSee('mentions-legales', false)->assertSee('RCCM CI-ABJ-03-2026-B13-16786');
    }

    public function test_legal_notice_works_without_company_details(): void
    {
        $this->get('/mentions-legales')->assertOk()->assertSee('Mentions légales');
    }
}
