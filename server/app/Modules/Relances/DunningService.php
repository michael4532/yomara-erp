<?php

namespace App\Modules\Relances;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\Company;
use App\Modules\Core\Models\Partner;
use App\Modules\Core\Models\TradeDocumentLine;
use App\Modules\Relances\Models\Reminder;
use App\Modules\Ventes\Models\SalesDocument;
use App\Payments\InvoicePayments;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Relances clients par paliers : chaque jour, les factures échues non réglées
 * sont regroupées par client ; le palier atteint (ex. J+3, J+15, J+30) déclenche
 * un message unique (e-mail + WhatsApp ou SMS) listant toutes ses factures en retard,
 * avec le lien de paiement en ligne. Un palier n'est jamais envoyé deux fois pour une facture.
 */
class DunningService
{
    public const VARIABLES = ['{client}', '{societe}', '{factures}', '{total}', '{jours}', '{lien_paiement}', '{telephone}'];

    public function __construct(private MessagingService $messaging, private InvoicePayments $pay) {}

    public static function defaultLevels(): array
    {
        return [
            ['days' => 3, 'name' => 'Rappel courtois', 'channels' => ['email', 'whatsapp', 'sms'],
                'subject' => 'Rappel : facture(s) arrivée(s) à échéance',
                'body' => "Bonjour {client},\n\nSauf erreur de notre part, les factures suivantes sont arrivées à échéance :\n{factures}\n\nTotal restant dû : {total}\n\nVous pouvez les régler en ligne : {lien_paiement}\nSi votre paiement est déjà en route, merci de ne pas tenir compte de ce message.\n\nCordialement,\n{societe}"],
            ['days' => 15, 'name' => 'Relance', 'channels' => ['email', 'whatsapp', 'sms'],
                'subject' => 'Relance : factures impayées',
                'body' => "Bonjour {client},\n\nMalgré notre précédent rappel, les factures suivantes restent impayées (jusqu'à {jours} jours de retard) :\n{factures}\n\nTotal restant dû : {total}\n\nMerci de procéder au règlement sous 8 jours : {lien_paiement}\nPour toute question, contactez-nous au {telephone}.\n\n{societe}"],
            ['days' => 30, 'name' => 'Dernier rappel avant mise en demeure', 'channels' => ['email', 'whatsapp', 'sms'],
                'subject' => 'Dernier rappel avant mise en demeure',
                'body' => "Bonjour {client},\n\nNous restons sans règlement des factures suivantes, échues depuis {jours} jours :\n{factures}\n\nTotal restant dû : {total}\n\nSans paiement ou prise de contact sous 8 jours, nous serons contraints d'engager une procédure de recouvrement et de suspendre nos livraisons.\nRègler en ligne : {lien_paiement}\n\n{societe} — {telephone}"],
        ];
    }

    public static function defaultBefore(): array
    {
        return ['enabled' => true, 'days' => 3, 'channels' => ['email', 'whatsapp', 'sms'], 'subject' => 'Échéance à venir',
            'body' => "Bonjour {client},\n\nPetit rappel : les échéances suivantes arrivent bientôt :\n{factures}\n\nTotal : {total}\n\nVous pouvez régler dès maintenant en ligne : {lien_paiement}\nMerci de votre confiance,\n{societe}"];
    }

    public function settings(Company $company): array
    {
        $s = (array) data_get($company->settings, 'relances', []);
        $before = array_replace(self::defaultBefore(), (array) ($s['before'] ?? []));
        $before['days'] = max(1, min(30, (int) $before['days']));
        $before['enabled'] = (bool) $before['enabled'];
        $levels = array_values(array_filter((array) ($s['levels'] ?? self::defaultLevels()), fn ($l) => isset($l['days'], $l['name'])));
        usort($levels, fn ($a, $b) => $a['days'] <=> $b['days']);

        return [
            'enabled' => (bool) ($s['enabled'] ?? true),
            'min_amount' => (float) ($s['min_amount'] ?? 1000),
            'skip_weekends' => (bool) ($s['skip_weekends'] ?? true),
            'reply_to' => $s['reply_to'] ?? null,
            'levels' => $levels,
            'before' => $before,
        ];
    }

    /** Factures échues non réglées (client non suspendu). */
    public function overdue(Company $company, ?string $partnerId = null): Collection
    {
        $s = $this->settings($company);

        // Échéancier : une facture est en retard dès qu'une de ses échéances est passée sans être réglée
        return SalesDocument::where('status', 'validated')->where('type', 'invoice')->where('payment_status', '!=', 'paid')
            ->where(fn ($q) => $q->where('due_date', '<', now()->toDateString())->orWhereNotNull('schedule'))->whereNotNull('partner_id')
            ->when($partnerId, fn ($q) => $q->where('partner_id', $partnerId))
            ->orderBy('due_date')->get()
            ->filter(fn ($d) => $d->overdueAmount() >= max(0.01, $partnerId ? 0.01 : $s['min_amount']))
            ->sortBy(fn ($d) => $this->dueOf($d)->toDateString())->values();
    }

    /** Échéance de référence pour la relance : première échéance non réglée. */
    public function dueOf(SalesDocument $d): \Carbon\Carbon
    {
        return $d->firstUnpaidDue() ?? $d->due_date ?? $d->date;
    }

    public function lateDays(SalesDocument $d): int
    {
        return max(0, (int) $this->dueOf($d)->diffInDays(now()->startOfDay(), false));
    }

    public function levelFor(array $levels, int $days): int
    {
        $lvl = 0;
        foreach ($levels as $i => $l) {
            if ($days >= (int) $l['days']) {
                $lvl = $i + 1;
            }
        }

        return $lvl;
    }

    /** Tâche quotidienne. */
    public function run(Company $company): array
    {
        $s = $this->settings($company);
        $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        if (! $s['enabled'] || ! $s['levels'] || ($s['skip_weekends'] && now()->isWeekend())) {
            return $out;
        }
        $paused = Partner::where('dunning_paused', true)->pluck('id')->all();
        $up = $this->upcoming($company, $paused);
        $out['sent'] += $up['sent'];
        $out['failed'] += $up['failed'];
        foreach ($this->overdue($company)->groupBy('partner_id') as $partnerId => $docs) {
            if (in_array($partnerId, $paused, true)) {
                $out['skipped']++;

                continue;
            }
            $targets = $docs->mapWithKeys(fn ($d) => [$d->id => $this->levelFor($s['levels'], $this->lateDays($d))]);
            $need = $docs->filter(fn ($d) => $targets[$d->id] > (int) $d->dunning_level);
            if ($need->isEmpty()) {
                continue;
            }
            $level = $need->max(fn ($d) => $targets[$d->id]);
            $r = $this->send($company, Partner::find($partnerId), $docs, $level);
            if (! $r) {
                $out['skipped']++;

                continue;
            }
            if ($r->status === 'sent') {
                foreach ($need as $d) {
                    $d->forceFill(['dunning_level' => $targets[$d->id], 'last_dunning_at' => now()])->saveQuietly();
                }
                $out['sent']++;
            } else {
                $out['failed']++;
            }
        }

        return $out;
    }

    /**
     * Rappels avant échéance : chaque échéance non réglée qui tombe dans les N prochains jours, une seule fois,
     * un message par client (toutes ses échéances proches).
     */
    public function upcoming(Company $company, array $paused = []): array
    {
        $s = $this->settings($company);
        $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        if (! $s['before']['enabled']) {
            return $out;
        }
        $from = now()->addDay()->toDateString();
        $to = now()->addDays($s['before']['days'])->toDateString();
        $done = DB::table('rl_upcoming')->where('company_id', $company->id)->where('due_date', '>=', $from)->get()->map(fn ($r) => $r->document_id.'|'.$r->due_date)->flip();
        $due = [];
        foreach (SalesDocument::where('status', 'validated')->where('type', 'invoice')->where('payment_status', '!=', 'paid')->whereNotNull('partner_id')
            ->where('due_date', '>=', $from)->get() as $d) {
            foreach ($d->unpaidInstallments() as $i) {
                if ($i['date'] >= $from && $i['date'] <= $to && ! isset($done[$d->id.'|'.$i['date']]) && $i['amount'] >= max(0.01, $s['min_amount'])) {
                    $due[$d->partner_id][] = ['doc' => $d, 'date' => $i['date'], 'amount' => $i['amount']];
                }
            }
        }
        foreach ($due as $pid => $items) {
            $p = Partner::find($pid);
            if (! $p || in_array($pid, $paused, true)) {
                $out['skipped']++;

                continue;
            }
            $r = $this->sendUpcoming($company, $p, $items);
            if (! $r) {
                $out['skipped']++;

                continue;
            }
            if ($r->status === 'sent') {
                foreach ($items as $it) {
                    DB::table('rl_upcoming')->insertOrIgnore(['company_id' => $company->id, 'document_id' => $it['doc']->id, 'due_date' => $it['date'], 'reminder_id' => $r->id, 'created_at' => now(), 'updated_at' => now()]);
                }
                $out['sent']++;
            } else {
                $out['failed']++;
            }
        }

        return $out;
    }

    private function sendUpcoming(Company $company, Partner $partner, array $items): ?Reminder
    {
        $s = $this->settings($company);
        $cfg = $s['before'];
        $channels = $this->channelsFor($company, $partner, (array) $cfg['channels']);
        if (! $channels) {
            return null;
        }
        $lines = [];
        $rows = '';
        $totals = [];
        $summary = [];
        $firstLink = null;
        foreach ($items as $it) {
            $d = $it['doc'];
            $link = InvoicePayments::url($this->pay->link($d));
            $firstLink ??= $link;
            $totals[$d->currency] = ($totals[$d->currency] ?? 0) + $it['amount'];
            $date = \Carbon\Carbon::parse($it['date'])->format('d/m/Y');
            $lines[] = '• '.$d->number.' : '.$this->money($it['amount'], $d->currency).' le '.$date.' — '.$link;
            $rows .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #e3e8f1">'.e($d->number).'</td><td style="padding:6px 10px;border-bottom:1px solid #e3e8f1">'.$date.'</td>'
                .'<td style="padding:6px 10px;border-bottom:1px solid #e3e8f1;text-align:right;font-weight:600">'.e($this->money($it['amount'], $d->currency)).'</td>'
                .'<td style="padding:6px 10px;border-bottom:1px solid #e3e8f1"><a href="'.e($link).'" style="color:#1f6feb">Payer</a></td></tr>';
            $summary[] = ['id' => $d->id, 'number' => $d->number, 'balance' => $it['amount'], 'currency' => $d->currency, 'days' => -\Carbon\Carbon::parse($it['date'])->diffInDays(now()->startOfDay()), 'due' => $it['date']];
        }
        $total = implode(' + ', array_map(fn ($c, $v) => $this->money($v, $c), array_keys($totals), $totals));
        $vars = ['{client}' => $partner->name, '{societe}' => $company->name, '{total}' => $total, '{lien_paiement}' => count($items) === 1 ? $firstLink : 'voir les liens ci-dessus', '{telephone}' => (string) ($company->phone ?? '')];
        $body = (string) $cfg['body'];
        $text = $this->replace(str_replace('{factures}', implode("\n", $lines), $body), $vars);
        $table = '<table style="border-collapse:collapse;width:100%;font-size:14px;margin:10px 0"><tr style="background:#0b1b3a;color:#fff"><th style="padding:6px 10px;text-align:left">Facture</th><th style="padding:6px 10px;text-align:left">Échéance</th><th style="padding:6px 10px;text-align:right">Montant</th><th></th></tr>'.$rows.'</table>';
        $parts = explode('{factures}', e($body), 2);
        $htmlBody = nl2br($this->replace($parts[0], array_map('e', $vars))).(isset($parts[1]) ? $table.nl2br($this->replace($parts[1], array_map('e', $vars))) : '');
        $htmlBody = preg_replace('#(https?://[^\s<]+)#', '<a href="$1" style="color:#1f6feb">$1</a>', $htmlBody);
        $html = '<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;color:#1b2333;line-height:1.5">'.$htmlBody.'</div>';
        $subject = $this->replace((string) $cfg['subject'], ['{client}' => $partner->name, '{societe}' => $company->name]);
        [$results, $ok] = $this->deliver($company, $partner, $channels, $subject, $html, $text, $s['reply_to']);

        return Reminder::create(['partner_id' => $partner->id, 'level' => 0, 'level_name' => 'Rappel avant échéance', 'documents' => $summary, 'channels' => $results, 'status' => $ok ? 'sent' : 'failed', 'manual' => false]);
    }

    private function channelsFor(Company $company, Partner $partner, array $wanted): array
    {
        $available = $this->messaging->available($company);
        $channels = [];
        if (in_array('email', $wanted, true) && $partner->email && ($available['email'] ?? null)) {
            $channels[] = 'email';
        }
        foreach (['whatsapp', 'sms'] as $ch) { // un seul message sur le téléphone
            if (in_array($ch, $wanted, true) && $partner->phone && ($available[$ch] ?? null)) {
                $channels[] = $ch;
                break;
            }
        }

        return $channels;
    }

    private function deliver(Company $company, Partner $partner, array $channels, string $subject, string $html, string $text, ?string $replyTo): array
    {
        $results = [];
        $ok = false;
        foreach ($channels as $ch) {
            $m = new Message(to: $ch === 'email' ? $partner->email : $partner->phone, subject: $subject.' — '.$company->name, html: $html, text: $text, toName: $partner->name, replyTo: $replyTo ?: $company->email);
            try {
                $res = $this->messaging->send($company, $ch, $m, 'relance');
                $results[$ch] = $res->ok ? 'ok' : ($res->error ?? 'échec');
                $ok = $ok || $res->ok;
            } catch (\Throwable $e) {
                $results[$ch] = mb_substr($e->getMessage(), 0, 200);
            }
        }

        return [$results, $ok];
    }

    /** Relance immédiate d'un client (bouton « Relancer maintenant »). */
    public function sendNow(Company $company, Partner $partner, ?int $level = null): Reminder
    {
        $s = $this->settings($company);
        $docs = $this->overdue($company, $partner->id);
        if ($docs->isEmpty()) {
            throw new \DomainException('Ce client n\'a aucune facture échue à relancer.');
        }
        $maxDays = (int) $docs->max(fn ($d) => $this->lateDays($d));
        $level ??= max(1, $this->levelFor($s['levels'], $maxDays));
        $level = min(max(1, $level), max(1, count($s['levels'])));
        $r = $this->send($company, $partner, $docs, $level, true);
        if (! $r) {
            throw new \DomainException('Aucun moyen de joindre ce client : renseignez son e-mail ou son téléphone, et configurez un canal d\'envoi.');
        }
        if ($r->status === 'sent') {
            foreach ($docs as $d) {
                if ($d->dunning_level < $level) {
                    $d->forceFill(['dunning_level' => $level, 'last_dunning_at' => now()])->saveQuietly();
                }
            }
        }

        return $r;
    }

    private function send(Company $company, Partner $partner, Collection $docs, int $level, bool $manual = false): ?Reminder
    {
        $s = $this->settings($company);
        $cfg = $s['levels'][$level - 1] ?? end($s['levels']);
        $available = $this->messaging->available($company);
        $wanted = (array) ($cfg['channels'] ?? ['email', 'whatsapp', 'sms']);
        $channels = [];
        if (in_array('email', $wanted, true) && $partner->email && ($available['email'] ?? null)) {
            $channels[] = 'email';
        }
        foreach (['whatsapp', 'sms'] as $ch) { // un seul message sur le téléphone
            if (in_array($ch, $wanted, true) && $partner->phone && ($available[$ch] ?? null)) {
                $channels[] = $ch;
                break;
            }
        }
        if (! $channels) {
            return null;
        }

        [$text, $html, $summary] = $this->render($company, $partner, $docs, (string) ($cfg['body'] ?? ''));
        $subject = $this->replace((string) ($cfg['subject'] ?? 'Factures impayées'), ['{client}' => $partner->name, '{societe}' => $company->name]);
        $results = [];
        $ok = false;
        foreach ($channels as $ch) {
            $m = new Message(to: $ch === 'email' ? $partner->email : $partner->phone, subject: $subject.' — '.$company->name, html: $html, text: $text, toName: $partner->name, replyTo: $s['reply_to'] ?: $company->email);
            try {
                $res = $this->messaging->send($company, $ch, $m, 'relance');
                $results[$ch] = $res->ok ? 'ok' : ($res->error ?? 'échec');
                $ok = $ok || $res->ok;
            } catch (\Throwable $e) {
                $results[$ch] = mb_substr($e->getMessage(), 0, 200);
            }
        }

        return Reminder::create([
            'partner_id' => $partner->id, 'level' => $level, 'level_name' => $cfg['name'] ?? 'Relance', 'documents' => $summary,
            'channels' => $results, 'status' => $ok ? 'sent' : 'failed', 'manual' => $manual, 'user_id' => $manual ? auth()->id() : null,
        ]);
    }

    public function money(float $v, ?string $currency): string
    {
        $cur = strtoupper((string) ($currency ?: 'XOF'));
        $dec = TradeDocumentLine::decimalsFor($cur);
        $sym = in_array($cur, ['XOF', 'XAF'], true) ? 'F CFA' : $cur;

        return number_format($v, $dec, ',', ' ').' '.$sym;
    }

    /** @return array{0: string, 1: string, 2: array} texte, HTML, résumé des factures */
    public function render(Company $company, Partner $partner, Collection $docs, string $body): array
    {
        $lines = [];
        $rows = '';
        $summary = [];
        $totals = [];
        $firstLink = null;
        $maxDays = 0;
        foreach ($docs as $d) {
            $days = $this->lateDays($d);
            $due = $this->dueOf($d);
            $maxDays = max($maxDays, $days);
            $bal = $d->overdueAmount() ?: $d->balance();
            $link = InvoicePayments::url($this->pay->link($d));
            $firstLink ??= $link;
            $totals[$d->currency] = ($totals[$d->currency] ?? 0) + $bal;
            $lines[] = '• '.$d->number.' du '.$d->date->format('d/m/Y').' : '.$this->money($bal, $d->currency).' (échue le '.$due->format('d/m/Y').', '.$days.' j) — '.$link;
            $rows .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #e3e8f1">'.e($d->number).'</td><td style="padding:6px 10px;border-bottom:1px solid #e3e8f1">'.$d->date->format('d/m/Y').'</td>'
                .'<td style="padding:6px 10px;border-bottom:1px solid #e3e8f1">'.$due->format('d/m/Y').' ('.$days.' j)</td>'
                .'<td style="padding:6px 10px;border-bottom:1px solid #e3e8f1;text-align:right;font-weight:600">'.e($this->money($bal, $d->currency)).'</td>'
                .'<td style="padding:6px 10px;border-bottom:1px solid #e3e8f1"><a href="'.e($link).'" style="color:#1f6feb">Payer</a></td></tr>';
            $summary[] = ['id' => $d->id, 'number' => $d->number, 'balance' => $bal, 'currency' => $d->currency, 'days' => $days];
        }
        $total = implode(' + ', array_map(fn ($c, $v) => $this->money($v, $c), array_keys($totals), $totals));
        $vars = [
            '{client}' => $partner->name, '{societe}' => $company->name, '{total}' => $total, '{jours}' => (string) $maxDays,
            '{lien_paiement}' => count($docs) === 1 ? $firstLink : 'voir les liens ci-dessus', '{telephone}' => (string) ($company->phone ?? ''),
        ];
        $text = $this->replace(str_replace('{factures}', implode("\n", $lines), $body), $vars);
        $table = '<table style="border-collapse:collapse;width:100%;font-size:14px;margin:10px 0"><tr style="background:#0b1b3a;color:#fff"><th style="padding:6px 10px;text-align:left">Facture</th><th style="padding:6px 10px;text-align:left">Date</th><th style="padding:6px 10px;text-align:left">Échéance</th><th style="padding:6px 10px;text-align:right">Reste dû</th><th></th></tr>'.$rows.'</table>';
        $parts = explode('{factures}', e($body), 2);
        $htmlBody = nl2br($this->replace($parts[0], array_map('e', $vars))).(isset($parts[1]) ? $table.nl2br($this->replace($parts[1], array_map('e', $vars))) : '');
        $htmlBody = preg_replace('#(https?://[^\s<]+)#', '<a href="$1" style="color:#1f6feb">$1</a>', $htmlBody);
        $html = '<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;color:#1b2333;line-height:1.5">'.$htmlBody.'</div>';

        return [$text, $html, $summary];
    }

    private function replace(string $s, array $vars): string
    {
        return strtr($s, $vars);
    }

    /** Balance âgée des créances clients. */
    public function aging(Company $company): array
    {
        $today = now()->startOfDay();
        $rows = [];
        $last = Reminder::selectRaw('partner_id, MAX(created_at) at')->groupBy('partner_id')->pluck('at', 'partner_id');
        $invoices = SalesDocument::where('status', 'validated')->where('type', 'invoice')->where('payment_status', '!=', 'paid')->whereNotNull('partner_id')->get();
        foreach ($invoices->groupBy('partner_id') as $pid => $docs) {
            $p = Partner::withTrashed()->find($pid);
            $b = ['not_due' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'over90' => 0.0];
            $count = 0;
            foreach ($docs as $d) {
                $late = false;
                foreach ($d->unpaidInstallments() as $i) { // chaque échéance dans sa tranche
                    $due = \Carbon\Carbon::parse($i['date']);
                    $days = $due->lt($today) ? (int) $due->diffInDays($today) : 0;
                    $key = match (true) { $days <= 0 => 'not_due', $days <= 30 => 'd30', $days <= 60 => 'd60', $days <= 90 => 'd90', default => 'over90' };
                    $b[$key] += $d->toBase((float) $i['amount']);
                    $late = $late || $days > 0;
                }
                $count += $late ? 1 : 0;
            }
            $total = array_sum($b);
            if ($total < 0.01) {
                continue;
            }
            $rows[] = ['partner_id' => $pid, 'name' => $p?->name ?? '—', 'email' => $p?->email, 'phone' => $p?->phone, 'paused' => (bool) $p?->dunning_paused]
                + array_map(fn ($v) => round($v, 2), $b)
                + ['total' => round($total, 2), 'overdue' => round($total - $b['not_due'], 2), 'overdue_count' => $count,
                    'level' => (int) $docs->max('dunning_level'), 'last_reminder' => $last[$pid] ?? null];
        }
        usort($rows, fn ($a, $b) => $b['overdue'] <=> $a['overdue']);
        $tot = [];
        foreach (['not_due', 'd30', 'd60', 'd90', 'over90', 'total', 'overdue'] as $k) {
            $tot[$k] = round(array_sum(array_column($rows, $k)), 2);
        }

        return ['rows' => $rows, 'totals' => $tot, 'currency' => $company->currency];
    }
}
