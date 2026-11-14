<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\MailTemplates;
use Tests\TestCase;

/** Modèles d'e-mails (défaut plateforme, société), mise en page, bienvenue des nouveaux employés. */
class MailTemplatesTest extends TestCase
{
    protected function sent(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()->all();
    }

    public function test_platform_default_company_override_and_preview(): void
    {
        [, , $admin] = $this->makeCompany('Yomara', 'entreprise', superAdmin: true);
        [$c, , $t] = $this->makeCompany('Boutique Awa');

        $this->api($admin)->putJson('/api/admin/mail-templates', ['templates' => ['doc_invoice' => ['subject' => 'Votre facture {numero}', 'body' => "Bonjour {client},\nmerci !"]]])->assertOk();
        $this->assertSame('Votre facture FAC-1', MailTemplates::render('doc_invoice', ['numero' => 'FAC-1'], $c->fresh())[0]);

        // La société adapte son modèle ; « rétablir » revient au défaut du super admin
        $this->api($t)->putJson('/api/mail-templates', ['templates' => ['doc_invoice' => ['subject' => 'Facture {numero} de {entreprise}', 'body' => 'Corps']]])->assertOk();
        $eff = $this->api($t)->getJson('/api/mail-templates/effective')->assertOk()->json('data.doc_invoice');
        $this->assertSame('Facture {numero} de {entreprise}', $eff['subject']);
        $this->api($t)->putJson('/api/mail-templates', ['templates' => ['doc_invoice' => ['reset' => true]]])->assertOk();
        $this->assertSame('Votre facture {numero}', $this->api($t)->getJson('/api/mail-templates/effective')->json('data.doc_invoice.subject'));

        $p = $this->api($t)->postJson('/api/mail-templates/preview', ['key' => 'doc_invoice'])->assertOk()->json('data');
        $this->assertStringContainsString('Boutique Awa', $p['html']);
        $this->assertStringContainsString('FAC-2026-00042', $p['html']);
        $this->assertStringContainsString('© '.date('Y').' Yomara Technologies', $p['html']);
        // Un employé sans droit de paramétrage ne modifie pas les modèles
        $this->api($t)->getJson('/api/mail-templates')->assertOk();
    }

    public function test_document_email_uses_branded_layout(): void
    {
        $mails = new \Illuminate\Mail\Transport\ArrayTransport;
        \App\Messaging\Providers\Smtp::$transportFactory = fn () => $mails;
        [$c, , $t] = $this->makeCompany('Mise En Page SARL');
        $this->api($t)->putJson('/api/messaging/channels/email', ['provider' => 'smtp', 'settings' => [
            'host' => 'mail.mep.ci', 'port' => '465', 'encryption' => 'ssl', 'username' => 'f@mep.ci', 'password' => 'S3cret!pass', 'from_email' => 'f@mep.ci', 'from_name' => 'MEP',
        ]])->assertOk();
        $this->api($t)->postJson('/api/messaging/send', ['channel' => 'email', 'to' => 'client@exemple.ci', 'subject' => 'Facture FAC-9', 'message' => "Bonjour,\nci-joint.",
            'doc_label' => 'Facture', 'doc_number' => 'FAC-9', 'doc_total' => '50 000 F CFA', 'pay_url' => 'https://pay.exemple.ci/x'])->assertOk();
        $html = (string) $mails->messages()->last()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('Mise En Page SARL', $html);
        $this->assertStringContainsString('FAC-9', $html);
        $this->assertStringContainsString('Payer en ligne', $html);
        $this->assertStringContainsString('Yomara Technologies', $html);
    }

    public function test_new_employee_gets_welcome_mail_and_can_choose_password(): void
    {
        config(['mail.default' => 'array']);
        [$c, $owner, $t] = $this->makeCompany('Equipe SARL');
        $role = \App\Models\Role::where('company_id', $c->id)->first();
        $this->api($t)->postJson('/api/users', ['name' => 'Koffi Kouamé', 'email' => 'koffi@equipe.ci', 'password' => 'Provisoire1', 'role_id' => $role->id])->assertCreated();
        $u = User::where('email', 'koffi@equipe.ci')->first();
        $msg = collect($this->sent())->first(fn ($m) => str_contains((string) $m->getOriginalMessage()->getHtmlBody(), 'Choisir mon mot de passe'));
        $this->assertNotNull($msg);
        $html = (string) $msg->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('Equipe SARL', $html);
        $this->assertTrue(\App\Models\AppNotification::where('user_id', $u->id)->where('type', 'welcome')->exists());

        preg_match('#href="([^"]*/bienvenue/'.$u->id.'[^"]*)"#', $html, $m);
        $link = html_entity_decode($m[1]);
        $path = parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY);
        $this->get($path)->assertOk()->assertSee('koffi@equipe.ci');
        $this->post($path, ['password' => 'MonMotDePasse9', 'password_confirmation' => 'MonMotDePasse9'])->assertOk();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('MonMotDePasse9', $u->fresh()->password));
        // Lien déjà utilisé : refusé
        $this->get($path)->assertStatus(410);
        // Lien modifié : signature invalide
        $this->get(str_replace('bienvenue/'.$u->id, 'bienvenue/'.$owner->id, $path))->assertForbidden();
    }
}
