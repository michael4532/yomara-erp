<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Bibliothèques prêtes à l'emploi : relances prospect (CRM), e-mails et messages SMS / WhatsApp (Marketing). */
class TemplateLibrariesTest extends TestCase
{
    public function test_prospect_followup_library_is_copied_then_editable(): void
    {
        [, , $t] = $this->makeCompany('Agence Kanvas');
        $api = fn () => $this->api($t);
        $lib = $api()->getJson('/api/crm/sequences/library')->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(12, count($lib));
        $this->assertEqualsCanonicalizing(['Situations', 'Métiers'], array_values(array_unique(array_column($lib, 'category'))));
        $quote = collect($lib)->firstWhere('key', 'after_quote');
        $this->assertSame(['whatsapp', 'email', 'call', 'email'], array_column($quote['steps'], 'channel'));

        // Copie du modèle dans les relances de la société : nom, description et étapes repris
        $s = $api()->postJson('/api/crm/sequences', ['preset' => 'after_quote'])->assertCreated()->json('data');
        $this->assertSame('Après l\'envoi d\'un devis', $s['name']);
        $this->assertCount(4, $s['steps']);
        $this->assertStringContainsString('{{prenom}}', $s['steps'][0]['text']);
        // Le client l'adapte à sa façon
        $s2 = $api()->putJson('/api/crm/sequences/'.$s['id'], ['name' => 'Relance devis Kanvas', 'steps' => array_slice($s['steps'], 0, 2)])->assertOk()->json('data');
        $this->assertSame('Relance devis Kanvas', $s2['name']);
        $this->assertCount(2, $s2['steps']);
        // Modèle inconnu : refusé comme une relance vide
        $api()->postJson('/api/crm/sequences', ['preset' => 'inexistant'])->assertStatus(422);
    }

    public function test_marketing_layouts_and_text_messages(): void
    {
        [, , $t] = $this->makeCompany('Boutique Awa');
        $api = fn () => $this->api($t);
        $r = $api()->getJson('/api/marketing/layouts')->assertOk()->json();
        $cats = array_unique(array_column($r['data'], 'category'));
        $this->assertContains('Prospection', $cats);
        $this->assertContains('Fidélisation', $cats);
        $this->assertGreaterThanOrEqual(20, count($r['data']));
        $this->assertGreaterThanOrEqual(12, count($r['texts']));

        // Modèle e-mail de prospection
        $mail = $api()->postJson('/api/marketing/templates', ['name' => 'Relance devis', 'channel' => 'email', 'layout' => 'quote_followup'])->assertCreated()->json();
        $this->assertSame('Votre devis est prêt, {{prenom}}', $mail['subject']);
        $this->assertNotEmpty($mail['blocks']);
        // Message WhatsApp prêt à l'emploi, puis modifié par le client
        $wa = $api()->postJson('/api/marketing/templates', ['name' => 'Relance WhatsApp', 'channel' => 'whatsapp', 'layout' => 'txt_quote_followup'])->assertCreated()->json();
        $this->assertStringContainsString('{{prenom}}', $wa['text']);
        $api()->putJson('/api/marketing/templates/'.$wa['id'], ['text' => 'Bonjour {{prenom}}, notre offre tient toujours !'])->assertOk();
        $this->assertSame('Bonjour {{prenom}}, notre offre tient toujours !', $api()->getJson('/api/marketing/templates/'.$wa['id'])->assertOk()->json('text'));
    }
}
