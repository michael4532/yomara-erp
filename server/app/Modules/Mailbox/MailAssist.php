<?php

namespace App\Modules\Mailbox;

use App\Models\Company;
use App\Models\User;
use App\Modules\Ia\AiService;
use App\Modules\Mailbox\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fonctions « premium » de la boîte mail, incluses avec le module (équivalent des options payantes d'Outlook / Copilot) :
 * résumé, points clés et actions à faire, réponse rédigée, traduction. L'IA de la plateforme est utilisée
 * (ou la clé de la société) ; sans IA disponible, un résumé et des réponses types sont produits localement.
 */
class MailAssist
{
    public const ACTIONS = ['summary', 'reply', 'translate', 'tasks'];

    public const LANGS = ['fr' => 'français', 'en' => 'anglais', 'es' => 'espagnol', 'pt' => 'portugais', 'ar' => 'arabe', 'zh' => 'chinois', 'de' => 'allemand'];

    public function run(Company $c, User $u, Message $m, string $action, array $o = []): array
    {
        $text = mb_substr(MailInsights::plain($m), 0, 12000);
        $from = trim(($m->from_name ?: '').' <'.($m->from_email ?: '').'>');
        $base = "E-mail reçu de {$from}\nObjet : ".($m->subject ?: '(sans objet)')."\n\n".$text;
        $prompt = match ($action) {
            'summary' => "Résume cet e-mail en français en 2 à 4 puces courtes (qui demande quoi, pour quand, montants). Termine par une ligne « Réponse attendue : oui/non ».\n\n".$base,
            'tasks' => "Liste les actions concrètes à faire suite à cet e-mail (verbe à l'infinitif, échéance si citée), en puces. S'il n'y en a pas, réponds « Aucune action requise. »\n\n".$base,
            'translate' => 'Traduis cet e-mail en '.(self::LANGS[$o['lang'] ?? 'fr'] ?? 'français').". Rends uniquement la traduction (objet puis corps).\n\n".$base,
            'reply' => 'Rédige la réponse à cet e-mail au nom de '.$u->name.' ('.$c->name.'), ton '.($o['tone'] ?? 'professionnel et cordial').', en français sauf si l\'e-mail est dans une autre langue. '
                .(! empty($o['instructions']) ? 'Consignes : '.$o['instructions'].'. ' : '')
                ."Ne mets ni objet ni signature, rends uniquement le corps. N'invente aucun montant, date ou engagement.\n\n".$base,
        };
        $ai = app(AiService::class);
        $s = $ai->settings($c);
        if ($s['enabled'] && $s['configured'] && ($s['monthly_limit'] === 0 || $s['used'] < $s['monthly_limit'])) {
            try {
                $res = (new \App\Modules\Ia\AiClient($s['provider'], (string) $s['key'], $s['model'], $s['base_url']))
                    ->chat('Tu es l\'assistant de messagerie d\'une entreprise d\'Afrique de l\'Ouest. Réponses nettes, sans préambule.', [['role' => 'user', 'content' => $prompt]], [], null, 900);
                DB::table('ia_messages')->insert(['conversation_id' => null, 'company_id' => $c->id, 'user_id' => $u->id, 'role' => 'draft', 'content' => $res['text'],
                    'tools' => json_encode(['kind' => 'mail_'.$action]), 'tokens_in' => $res['tokens_in'], 'tokens_out' => $res['tokens_out'], 'created_at' => now(), 'updated_at' => now()]);

                return ['text' => trim($res['text']), 'ai' => true];
            } catch (\Throwable) {
                // repli local ci-dessous
            }
        }

        return ['text' => $this->local($c, $u, $m, $text, $action), 'ai' => false];
    }

    /** Sans IA : résumé extractif, actions repérées, réponse type selon la nature de la demande. */
    protected function local(Company $c, User $u, Message $m, string $text, string $action): string
    {
        $ins = rescue(fn () => MailInsights::analyze($m), [], false);
        $clean = preg_replace('/^(bonjour|bonsoir|madame|monsieur|cher|chère)[^,\n]{0,40},\s*/imu', '', $text);
        $sentences = array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+|\n+/u', (string) $clean)), fn ($x) => mb_strlen($x) > 20
            && ! preg_match('/^(cordialement|bien à vous|salutations|envoyé de|begin:|end:|[a-z]+:)/iu', $x)));
        // Nature principale : celle que l'objet évoque, sinon la première repérée
        $intents = $ins['intents'] ?? [];
        $subject = MailInsights::norm((string) $m->subject);
        $intent = collect($intents)->first(fn ($k) => collect(MailInsights::INTENTS[$k] ?? [])->contains(fn ($w) => str_contains($subject, $w))) ?? ($intents[0] ?? null);
        $label = ['quote' => 'une demande de devis', 'meeting' => 'une proposition de réunion', 'ticket' => 'un signalement de problème', 'funds' => 'une demande de fonds', 'lead' => 'une proposition de partenariat'][$intent] ?? null;
        $dates = array_slice(array_map(fn ($d) => $d['text'] ?? '', $ins['dates'] ?? []), 0, 2);

        return match ($action) {
            'summary' => implode("\n", array_filter([
                '• '.($m->from_name ?: $m->from_email).($label ? ' envoie '.$label : ' écrit').' : « '.Str::limit($m->subject ?: '', 80).' ».',
                ...array_map(fn ($x) => '• '.Str::limit($x, 160), array_slice($sentences, 0, 2)),
                $dates ? '• Dates citées : '.implode(', ', array_filter($dates)).'.' : null,
                ! empty($ins['amount']) ? '• Montant cité : '.number_format((float) $ins['amount'], 0, ',', ' ').' '.$c->currency.'.' : null,
                'Réponse attendue : '.(preg_match('/\?|merci de|pourriez|pouvez|svp|s\'il vous pla/iu', $text) ? 'oui' : 'non'),
            ])),
            'tasks' => ($t = array_values(array_filter($sentences, fn ($x) => preg_match('/\?|merci de|pourriez|pouvez|veuillez|svp|s\'il vous pla|besoin|urgent|avant le|d\'ici/iu', $x))))
                ? implode("\n", array_map(fn ($x) => '• '.Str::limit($x, 160), array_slice($t, 0, 5)))
                : 'Aucune action requise.',
            'translate' => 'La traduction nécessite l\'assistant IA (clé de la plateforme ou de votre société).',
            'reply' => "Bonjour".($m->from_name ? ' '.explode(' ', trim($m->from_name))[0] : '').",\n\n"
                .match ($intent) {
                    'quote' => 'Merci pour votre demande. Nous préparons votre devis et vous l\'adressons dans les meilleurs délais.',
                    'meeting' => 'Merci pour votre proposition. Le créneau'.($dates ? ' du '.$dates[0] : '').' me convient ; je vous envoie l\'invitation.',
                    'ticket' => 'Nous sommes désolés pour ce désagrément. Notre équipe prend en charge votre demande et revient vers vous rapidement.',
                    'funds' => 'Votre demande est bien reçue ; elle est transmise pour validation et nous vous tenons informé.',
                    default => 'Merci pour votre message, bien reçu. Je reviens vers vous très prochainement.',
                },
        };
    }
}
