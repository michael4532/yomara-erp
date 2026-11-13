<?php

namespace App\Platform;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\ModuleOrder;
use App\Models\User;
use App\Store\ModuleStore;
use App\Support\Tr;
use Illuminate\Support\Facades\Log;

/**
 * Prévient l'éditeur (super administrateurs : notification dans l'app + e-mail) et les clients (notification + e-mail)
 * des étapes d'une commande : passée, paiement déclaré, payée / activée, refusée.
 */
class PlatformNotifier
{
    public static function superAdmins()
    {
        return User::withoutGlobalScopes()->where('is_super_admin', true)->where('is_active', true)->get(['id', 'company_id', 'name', 'email']);
    }

    /** Notification dans l'app à chaque super administrateur, et e-mail à l'adresse de l'éditeur + aux super administrateurs. */
    public static function admins(string $title, string $body, string $ref, array $rows = [], ?string $cta = null): void
    {
        $admins = self::superAdmins();
        foreach ($admins->groupBy('company_id') as $companyId => $users) {
            rescue(fn () => AppNotification::send((int) $companyId, $users->pluck('id'), 'store', $title, $body, 'admin_store', $ref), null, false);
        }
        $to = collect([ModuleStore::contact()['email'] ?? null])->merge($admins->pluck('email'))->filter()->map(fn ($e) => mb_strtolower(trim($e)))->unique()->values();
        $html = self::layout($title, '<p style="margin:0 0 16px;font-size:15px;line-height:1.6">'.e($body).'</p>'.self::table($rows)
            .'<p style="margin:22px 0 0"><a href="'.e(url('/app/?ecran='.($cta ?? 'admin_store'))).'" style="display:inline-block;background:#0A1633;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:10px">Ouvrir la console</a></p>');
        foreach ($to as $email) {
            try {
                ClientComms::mail($email, null, '['.config('erp.name', 'Yomara ERP').'] '.$title, $html, $title."\n\n".$body);
            } catch (\Throwable $e) {
                Log::warning('E-mail éditeur non envoyé : '.$e->getMessage());
            }
        }
    }

    /** Notification aux gestionnaires de la société cliente (et à l'auteur de la commande) + e-mail, dans la langue de chacun. */
    public static function client(Company $company, ?int $userId, string $title, string $body, array $rows = [], string $ref = '', array $attachments = []): void
    {
        $managers = User::withoutGlobalScopes()->where('company_id', $company->id)->where('is_active', true)->get()
            ->filter(fn ($u) => $u->id === $userId || $u->hasPermission('core.settings.manage'));
        rescue(fn () => AppNotification::send($company->id, $managers->pluck('id'), 'store', $title, $body, 'store', $ref), null, false);
        $to = [];
        foreach ($managers as $u) {
            if ($u->email) {
                $to[mb_strtolower(trim($u->email))] ??= in_array($u->locale, Tr::LOCALES, true) ? $u->locale : 'fr';
            }
        }
        if ($company->email) {
            $to[mb_strtolower(trim($company->email))] ??= $managers->first()?->locale ?: 'fr';
        }
        foreach (array_slice($to, 0, 5, true) as $email => $loc) {
            $t = fn (string $s) => $loc === 'fr' ? $s : (Tr::strict($s, $loc) ?? $s);
            $tRows = [];
            foreach ($rows as $k => $v) {
                $tRows[$t((string) $k)] = $t((string) $v);
            }
            $html = self::layout($t($title), '<p style="margin:0 0 16px;font-size:15px;line-height:1.6">'.e($t('Bonjour,')).'</p><p style="margin:0 0 16px;font-size:15px;line-height:1.6">'.e($t($body)).'</p>'.self::table($tRows)
                .'<p style="margin:22px 0 0"><a href="'.e(url('/app/')).'" style="display:inline-block;background:#2E6BE6;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:10px">'.e($t('Ouvrir '.config('erp.name', 'Yomara ERP'))).'</a></p>'
                .'<p style="margin:16px 0 0;font-size:13px;color:#64748B">'.e($t('Le logiciel se met à jour tout seul en quelques secondes. Sinon, touchez le bouton « Actualiser » en haut de l\'écran.')).'</p>', $loc);
            try {
                ClientComms::mail($email, null, $t($title), $html, $t($title)."\n\n".$t($body), [], $attachments);
            } catch (\Throwable $e) {
                Log::warning('E-mail client non envoyé : '.$e->getMessage());
            }
        }
    }

    protected static function table(array $rows): string
    {
        if (! $rows) {
            return '';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F7F9FD;border:1px solid #E5E9F2;border-radius:12px;font-size:14.5px">'
            .collect($rows)->map(fn ($v, $k) => '<tr><td style="padding:10px 14px;color:#64748B;border-bottom:1px solid #E5E9F2">'.e($k).'</td><td style="padding:10px 14px;font-weight:700;border-bottom:1px solid #E5E9F2">'.e((string) $v).'</td></tr>')->implode('')
            .'</table>';
    }

    protected static function layout(string $title, string $inner, string $lang = 'fr'): string
    {
        $name = e(ModuleStore::contact()['name'] ?? config('erp.name', 'Yomara ERP'));

        return '<!doctype html><html lang="'.$lang.'"><body style="margin:0;background:#F1F4FA;font-family:Segoe UI,Roboto,Arial,sans-serif;color:#0B1530">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:26px 12px"><tr><td align="center">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:16px;overflow:hidden">'
            .'<tr><td style="background:#0A1633;padding:22px 28px;color:#fff"><div style="font-size:13px;color:#FFC531;font-weight:700">'.$name.'</div><div style="font-size:21px;font-weight:800;margin-top:4px">'.e($title).'</div></td></tr>'
            .'<tr><td style="padding:24px 28px">'.$inner.'</td></tr></table></td></tr></table></body></html>';
    }

    protected static function money(ModuleOrder $o): string
    {
        return number_format((float) $o->amount, 0, ',', ' ').' '.($o->currency === 'XOF' ? 'FCFA' : $o->currency);
    }

    // ------------------------------------------------------------------ étapes d'une commande

    public static function orderPlaced(ModuleOrder $o): void
    {
        $c = Company::find($o->company_id);
        $gw = $o->gateway;
        $offline = $gw && ! $gw->isOnline();
        self::admins($offline ? 'Nouvelle commande à encaisser' : 'Nouvelle commande', ($c?->name ?? 'Une société').' a commandé « '.$o->label().' »'
            .($offline ? ' avec un paiement hors ligne ('.$gw->label.') : vérifiez le paiement puis validez la commande.' : ' et est en train de payer en ligne.'),
            (string) $o->id, ['Commande' => $o->number, 'Société' => $c?->name, 'Produit' => $o->label(), 'Formule' => ModuleOrder::BILLINGS[$o->billing] ?? $o->billing, 'Montant' => self::money($o), 'Paiement' => $gw?->label ?? '—']);
    }

    public static function paymentDeclared(ModuleOrder $o): void
    {
        $c = Company::find($o->company_id);
        self::admins('Paiement déclaré à vérifier', ($c?->name ?? 'Une société').' indique avoir payé « '.$o->label().' ». Vérifiez la référence puis validez la commande.',
            (string) $o->id, ['Commande' => $o->number, 'Société' => $c?->name, 'Montant' => self::money($o), 'Référence du paiement' => $o->payer_reference]);
    }

    /** Reçu PDF de la commande, en pièce jointe (vide si la génération échoue : l'e-mail part quand même). */
    public static function receipt(ModuleOrder $o): array
    {
        try {
            return [['name' => OrderReceipt::filename($o), 'data' => OrderReceipt::pdf($o), 'mime' => 'application/pdf']];
        } catch (\Throwable $e) {
            Log::warning('Reçu non généré pour '.$o->number.' : '.$e->getMessage());

            return [];
        }
    }

    public static function activated(ModuleOrder $o): void
    {
        $c = Company::find($o->company_id);
        if (! $c) {
            return;
        }
        $until = $o->period_end ? 'jusqu\'au '.$o->period_end->format('d/m/Y') : 'sans date de fin';
        self::client($c, $o->user_id, '« '.$o->label().' » est activé', 'Bonne nouvelle : « '.$o->label().' » est maintenant actif pour '.$c->name.', '.$until.'. Il apparaît dans votre menu. Votre reçu de paiement est joint à cet e-mail.',
            ['Commande' => $o->number, 'Produit' => $o->label(), 'Montant' => self::money($o), 'Paiement' => $o->paymentLabel(), 'Valable' => $until], (string) $o->id, self::receipt($o));
        self::admins('Commande payée et activée', '« '.$o->label().' » est activé pour '.$c->name.' ('.self::money($o).').', (string) $o->id,
            ['Commande' => $o->number, 'Société' => $c->name, 'Montant' => self::money($o), 'Valable' => $until]);
    }

    public static function rejected(ModuleOrder $o, string $reason): void
    {
        if ($c = Company::find($o->company_id)) {
            self::client($c, $o->user_id, 'Commande '.$o->number.' non validée', 'Votre commande « '.$o->label().' » n\'a pas pu être validée : '.$reason.'. Contactez-nous si vous avez bien payé.',
                ['Commande' => $o->number, 'Montant' => self::money($o)], (string) $o->id);
        }
    }

    /** Activation manuelle par l'éditeur (geste commercial, paiement reçu hors plateforme…). */
    /** Activation manuelle avec encaissement direct : e-mail de confirmation du paiement, reçu PDF joint. */
    public static function grantedWithReceipt(Company $c, ModuleOrder $o): void
    {
        $until = $o->period_end ? 'jusqu\'au '.$o->period_end->format('d/m/Y') : 'sans date de fin';
        $paid = (float) $o->amount > 0;
        self::client($c, null, $paid ? 'Paiement reçu : « '.$o->label().' » est activé' : '« '.$o->label().' » est activé',
            ($paid ? 'Nous confirmons la réception de votre paiement de '.self::money($o).' ('.$o->paymentLabel().'). ' : '')
            .'« '.$o->label().' » est maintenant actif pour '.$c->name.', '.$until.'. Il apparaît dans votre menu. Votre reçu est joint à cet e-mail.',
            ['Reçu' => $o->number, 'Produit' => $o->label(), 'Formule' => ModuleOrder::BILLINGS[$o->billing] ?? $o->billing, 'Montant' => $paid ? self::money($o) : 'Offert', 'Paiement' => $o->paymentLabel(), 'Valable' => $until],
            (string) $o->id, self::receipt($o));
    }

    public static function grantedByAdmin(Company $c, string $what, ?string $until): void
    {
        self::client($c, null, '« '.$what.' » est activé', '« '.$what.' » vient d\'être activé pour '.$c->name.($until ? ' jusqu\'au '.$until : '').' par notre équipe. Il apparaît dans votre menu.', ['Produit' => $what, 'Valable' => $until ?: 'sans date de fin']);
    }
}
