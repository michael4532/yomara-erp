<?php

namespace App\Support;

use App\Models\Company;
use App\Models\PlatformSetting;

/**
 * Modèles des e-mails envoyés aux clients et aux employés.
 *  - le super admin fixe le texte par défaut pour toutes les sociétés (Console › Modèles d'e-mails) ;
 *  - chaque société peut adapter le sien (Paramètres › Modèles d'e-mails) ;
 *  - tous partent dans la même mise en page : logo de la société, message, fiche du document, bouton, pied de page.
 * Variables entre accolades : {client}, {numero}, {montant}…
 */
class MailTemplates
{
    public const PLATFORM_KEY = 'mail_templates';

    /** Modèles : clé => [libellé, groupe, objet, message, variables]. */
    public static function definitions(): array
    {
        $doc = ['client', 'numero', 'montant', 'date', 'echeance', 'entreprise', 'lien'];

        return [
            'doc_invoice' => ['Facture', 'Documents',
                'Facture {numero} — {entreprise}',
                "Bonjour {client},\n\nVeuillez trouver ci-joint notre facture n° {numero} du {date}, d'un montant de {montant}, payable avant le {echeance}.\n\nNous restons à votre disposition pour toute question.\n\nCordialement,\n{entreprise}", $doc],
            'doc_quote' => ['Devis', 'Documents',
                'Devis {numero} — {entreprise}',
                "Bonjour {client},\n\nSuite à votre demande, veuillez trouver ci-joint notre devis n° {numero} d'un montant de {montant}.\n\nIl est valable jusqu'au {echeance}. N'hésitez pas à nous contacter pour toute précision.\n\nCordialement,\n{entreprise}", $doc],
            'doc_order' => ['Bon de commande', 'Documents',
                'Commande {numero} — {entreprise}',
                "Bonjour {client},\n\nVeuillez trouver ci-joint la confirmation de votre commande n° {numero} d'un montant de {montant}.\n\nMerci pour votre confiance.\n\nCordialement,\n{entreprise}", $doc],
            'doc_delivery' => ['Bon de livraison', 'Documents',
                'Bon de livraison {numero} — {entreprise}',
                "Bonjour {client},\n\nVeuillez trouver ci-joint le bon de livraison n° {numero} du {date}.\n\nCordialement,\n{entreprise}", $doc],
            'doc_credit' => ['Avoir', 'Documents',
                'Avoir {numero} — {entreprise}',
                "Bonjour {client},\n\nVeuillez trouver ci-joint notre avoir n° {numero} d'un montant de {montant}.\n\nCordialement,\n{entreprise}", $doc],
            'doc_receipt' => ['Reçu de paiement', 'Documents',
                'Reçu de paiement {numero} — {entreprise}',
                "Bonjour {client},\n\nNous accusons réception de votre paiement de {montant}. Veuillez trouver ci-joint le reçu n° {numero}.\n\nMerci pour votre règlement.\n\nCordialement,\n{entreprise}", $doc],
            'doc_other' => ['Autre document', 'Documents',
                '{document} {numero} — {entreprise}',
                "Bonjour {client},\n\nVeuillez trouver ci-joint le document {numero}.\n\nCordialement,\n{entreprise}", array_merge($doc, ['document'])],
            'employee_welcome' => ['Bienvenue à un nouvel employé', 'Équipe',
                'Votre compte {entreprise} est prêt',
                "Bonjour {prenom},\n\n{createur} vient de vous créer un compte {entreprise} sur Yomara ERP.\n\nVotre identifiant : {email}\nChoisissez votre mot de passe avec le bouton ci-dessous, puis connectez-vous sur ordinateur, sur le web ou avec l'application mobile.\n\nBienvenue dans l'équipe !", ['prenom', 'nom', 'email', 'entreprise', 'createur', 'role', 'lien']],
        ];
    }

    /** Exemple de valeurs pour l'aperçu. */
    public static function sample(?Company $c = null): array
    {
        return [
            'client' => 'Sandrine Kouassi', 'numero' => 'FAC-2026-00042', 'montant' => '179 400 F CFA', 'date' => now()->format('d/m/Y'),
            'echeance' => now()->addDays(30)->format('d/m/Y'), 'entreprise' => $c?->name ?? 'Votre entreprise', 'document' => 'Document',
            'prenom' => 'Koffi', 'nom' => 'Koffi Kouamé', 'email' => 'koffi@exemple.ci', 'createur' => 'Awa Diabaté', 'role' => 'Commercial',
            'lien' => url('/app/'),
        ];
    }

    /** Texte en vigueur : société > défaut du super admin > défaut d'origine. Renvoie [objet, message, personnalisé ?]. */
    public static function get(string $key, ?Company $c = null): array
    {
        $def = self::definitions()[$key] ?? self::definitions()['doc_other'];
        $platform = (array) (PlatformSetting::get(self::PLATFORM_KEY, [])[$key] ?? []);
        $own = $c ? (array) (($c->settings ?? [])['mail_templates'][$key] ?? []) : [];
        $subject = trim((string) ($own['subject'] ?? '')) ?: (trim((string) ($platform['subject'] ?? '')) ?: $def[2]);
        $body = trim((string) ($own['body'] ?? '')) ?: (trim((string) ($platform['body'] ?? '')) ?: $def[3]);

        return [$subject, $body, ! empty($own)];
    }

    public static function fill(string $text, array $vars): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $text);
    }

    /** Objet et message prêts à envoyer. */
    public static function render(string $key, array $vars, ?Company $c = null): array
    {
        [$s, $b] = self::get($key, $c);

        return [self::fill($s, $vars), self::fill($b, $vars)];
    }

    /** Clé de modèle d'après le type de document de vente. */
    public static function forDocType(?string $type): string
    {
        return match ($type) {
            'invoice', 'deposit' => 'doc_invoice', 'quote' => 'doc_quote', 'order' => 'doc_order', 'delivery' => 'doc_delivery',
            'credit_note', 'credit' => 'doc_credit', 'receipt' => 'doc_receipt', default => 'doc_other',
        };
    }

    /**
     * Mise en page commune : logo, message (texte simple ou HTML), fiche du document, bouton, pied de page avec © Yomara Technologies.
     * $opts : card => [libellé => valeur], button => [texte, lien], html => message déjà en HTML, signature => HTML.
     */
    public static function layout(?Company $c, string $message, array $opts = []): string
    {
        $accent = preg_match('/^#[0-9a-f]{6}$/i', (string) (($c?->settings ?? [])['brand_color'] ?? '')) ? $c->settings['brand_color'] : '#2E6BE6';
        $name = $c?->name ?: (string) config('erp.name', 'Yomara ERP');
        $logo = $c?->logo && preg_match('#^https?://#', (string) $c->logo) ? $c->logo : null;
        $head = $logo
            ? '<img src="'.e($logo).'" alt="'.e($name).'" height="44" style="height:44px;max-width:220px;border:0;display:block">'
            : '<span style="font-size:20px;font-weight:800;color:#0B1B3A;letter-spacing:-.01em">'.e($name).'</span>';
        $body = ! empty($opts['html']) ? $message : nl2br(e($message), false);
        $card = '';
        if (! empty($opts['card'])) {
            $rows = '';
            foreach ($opts['card'] as $k => $v) {
                if ((string) $v === '') {
                    continue;
                }
                $rows .= '<tr><td style="padding:7px 0;color:#5B6B85;font-size:14px">'.e($k).'</td><td style="padding:7px 0;text-align:right;font-weight:700;color:#0B1B3A;font-size:14px">'.e((string) $v).'</td></tr>';
            }
            $card = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:22px 0 4px;background:#F6F8FC;border:1px solid #E3E8F0;border-radius:12px"><tr><td style="padding:12px 18px">'
                .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0">'.$rows.'</table></td></tr></table>';
        }
        $button = '';
        if (! empty($opts['button'][1])) {
            $button = '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:22px 0 6px"><tr><td bgcolor="'.e($accent).'" style="background:'.e($accent).';border-radius:10px">'
                .'<a href="'.e($opts['button'][1]).'" style="display:inline-block;padding:13px 24px;color:#ffffff;font-weight:700;font-size:15px;text-decoration:none">'.e($opts['button'][0]).'</a></td></tr></table>';
        }
        $contact = implode(' · ', array_filter([$c?->address, $c?->city, $c?->phone, $c?->email]));

        return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="x-apple-disable-message-reformatting"><title>'.e($name).'</title></head>'
            .'<body style="margin:0;padding:0;background:#EEF2F8">'
            .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" bgcolor="#EEF2F8" style="background:#EEF2F8"><tr><td align="center" style="padding:28px 12px">'
            .'<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;font-family:Segoe UI,Helvetica,Arial,sans-serif">'
            .'<tr><td style="height:5px;background:'.e($accent).';font-size:0;line-height:0">&nbsp;</td></tr>'
            .'<tr><td style="padding:26px 32px 6px">'.$head.'</td></tr>'
            .'<tr><td style="padding:14px 32px 28px;font-size:15px;line-height:1.65;color:#1B2433">'.$body.$card.$button
            .(! empty($opts['signature']) ? '<div style="margin-top:22px">'.$opts['signature'].'</div>' : '').'</td></tr>'
            .'<tr><td style="padding:18px 32px;background:#F6F8FC;border-top:1px solid #E3E8F0;font-size:12px;line-height:1.6;color:#6B7686">'
            .'<b style="color:#0B1B3A">'.e($name).'</b>'.($contact ? '<br>'.e($contact) : '')
            .'<br><span style="color:#9AA4B5">Envoyé avec Yomara ERP · © '.date('Y').' Yomara Technologies</span></td></tr>'
            .'</table></td></tr></table></body></html>';
    }

    /** Aperçu d'un modèle (texte modifié ou en vigueur) avec des valeurs d'exemple. */
    public static function preview(string $key, ?Company $c, ?string $subject = null, ?string $body = null): array
    {
        [$s, $b] = self::get($key, $c);
        $vars = self::sample($c);
        $subject = self::fill($subject ?? $s, $vars);
        $text = self::fill($body ?? $b, $vars);
        $opts = str_starts_with($key, 'doc_')
            ? ['card' => ['Document' => $vars['numero'], 'Date' => $vars['date'], 'Montant' => $vars['montant'], 'Échéance' => $key === 'doc_invoice' ? $vars['echeance'] : ''],
                'button' => $key === 'doc_invoice' ? ['Payer en ligne', '#'] : null]
            : ['button' => ['Choisir mon mot de passe', '#']];

        return ['subject' => $subject, 'html' => self::layout($c, $text, $opts)];
    }
}
