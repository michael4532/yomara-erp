<?php

namespace App\Modules\Relances;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\Sequence;
use App\Models\User;
use App\Modules\Core\Models\Partner;
use App\Modules\Relances\Models\RecoveryAction;
use App\Modules\Relances\Models\RecoveryCase;
use App\Modules\Relances\Models\RecoveryInstallment;
use App\Modules\Ventes\Models\SalesDocument;
use App\Modules\Ventes\Models\SalesPayment;
use App\Services\EntityWriter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dossiers de recouvrement (contentieux) : quand les relances automatiques ne suffisent plus.
 * Ouvert → promesse → échéancier → mise en demeure → huissier → procédure ; recouvré, passé en perte ou clôturé.
 */
class RecoveryService
{
    public const STATUSES = [
        'open' => 'Ouvert', 'promise' => 'Promesse de paiement', 'plan' => 'Échéancier en cours', 'notice' => 'Mise en demeure', 'bailiff' => 'Chez l\'huissier',
        'litigation' => 'Procédure judiciaire', 'recovered' => 'Recouvré', 'written_off' => 'Passé en perte', 'closed' => 'Clôturé',
    ];

    public const OPEN = ['open', 'promise', 'plan', 'notice', 'bailiff', 'litigation'];

    public const ACTIONS = [
        'call' => 'Appel téléphonique', 'visit' => 'Visite', 'email' => 'E-mail', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'letter' => 'Courrier', 'note' => 'Note',
        'promise' => 'Promesse de paiement', 'payment' => 'Encaissement', 'plan' => 'Échéancier négocié', 'notice' => 'Mise en demeure', 'bailiff' => 'Transmission à l\'huissier',
        'litigation' => 'Procédure judiciaire', 'fees' => 'Frais de recouvrement', 'write_off' => 'Passage en perte', 'close' => 'Clôture', 'reopen' => 'Réouverture', 'broken' => 'Promesse non tenue',
    ];

    public function __construct(private DunningService $dunning, private MessagingService $messaging) {}

    public function settings(Company $c): array
    {
        $s = (array) data_get($c->settings, 'recovery', []);

        return ['min_days' => (int) ($s['min_days'] ?? 60), 'notice_days' => (int) ($s['notice_days'] ?? 8),
            'notice_text' => $s['notice_text'] ?? "Malgré nos relances, les factures ci-dessous restent impayées à ce jour. Nous vous mettons en demeure de régler la somme de {montant} dans un délai de {delai} jours à compter de la réception de la présente.\n\nÀ défaut de paiement dans ce délai, nous engagerons, sans autre avis, une procédure de recouvrement forcé dont les frais resteront à votre charge."];
    }

    public function saveSettings(Company $c, array $d): array
    {
        $settings = $c->settings ?? [];
        foreach (['min_days', 'notice_days', 'notice_text'] as $k) {
            if (array_key_exists($k, $d)) {
                data_set($settings, 'recovery.'.$k, $d[$k]);
            }
        }
        $c->update(['settings' => $settings]);

        return $this->settings($c->fresh());
    }

    protected function assert(bool $ok, string $msg): void
    {
        if (! $ok) {
            throw new \DomainException($msg);
        }
    }

    // ------------------------------------------------------------------ dossiers

    /** Clients à mettre en recouvrement : factures échues depuis plus de N jours, sans dossier ouvert. */
    public function candidates(Company $c): array
    {
        $min = $this->settings($c)['min_days'];
        $inCase = DB::table('rc_cases')->where('company_id', $c->id)->whereIn('status', self::OPEN)->pluck('partner_id')->all();
        $rows = [];
        foreach ($this->dunning->overdue($c)->groupBy('partner_id') as $pid => $docs) {
            if (in_array($pid, $inCase, true)) {
                continue;
            }
            $old = $docs->filter(fn ($d) => $this->dunning->lateDays($d) >= $min);
            if ($old->isEmpty()) {
                continue;
            }
            $p = Partner::find($pid);
            $rows[] = ['partner_id' => $pid, 'name' => $p?->name, 'phone' => $p?->phone, 'invoices' => $docs->count(), 'amount' => round($docs->sum(fn ($d) => $d->balance()), 2),
                'oldest_days' => $docs->map(fn ($d) => $this->dunning->lateDays($d))->max(), 'paused' => (bool) $p?->dunning_paused];
        }
        usort($rows, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return $rows;
    }

    /** Ouvre un dossier sur les factures impayées du client (toutes, ou celles choisies). */
    public function open(Company $c, User $u, string $partnerId, ?array $invoiceIds, ?int $assignee, ?string $note): RecoveryCase
    {
        $p = Partner::find($partnerId);
        $this->assert((bool) $p, 'Client introuvable.');
        $this->assert(! RecoveryCase::where('partner_id', $partnerId)->whereIn('status', self::OPEN)->exists(), 'Un dossier est déjà ouvert pour ce client.');
        // Par défaut : les factures échues ; sur choix, n'importe quelle facture impayée du client
        $docs = $invoiceIds
            ? SalesDocument::where('partner_id', $partnerId)->where('status', 'validated')->where('type', 'invoice')->where('payment_status', '!=', 'paid')->whereIn('id', $invoiceIds)->orderBy('due_date')->get()
            : $this->dunning->overdue($c, $partnerId);
        $docs = $docs->filter(fn ($d) => $d->balance() > 0.004)->values();
        $this->assert($docs->isNotEmpty(), 'Aucune facture échue impayée pour ce client.');

        return DB::transaction(function () use ($c, $u, $p, $docs, $assignee, $note) {
            $case = RecoveryCase::create(['company_id' => $c->id, 'number' => Sequence::next($c->id, 'recovery.case', 'REC'), 'partner_id' => $p->id, 'status' => 'open',
                'assigned_to' => $assignee ?: $u->id, 'opened_at' => now()->toDateString(), 'amount_initial' => round($docs->sum(fn ($d) => $d->balance()), 2),
                'note' => $note ? trim($note) : null, 'dunning_was_paused' => (bool) $p->dunning_paused, 'next_action_at' => now()->toDateString()]);
            foreach ($docs as $d) {
                DB::table('rc_case_invoices')->insert(['company_id' => $c->id, 'case_id' => $case->id, 'document_id' => $d->id, 'amount_at_open' => $d->balance()]);
            }
            // Les relances automatiques s'arrêtent : le dossier prend le relais
            $p->forceFill(['dunning_paused' => true])->save();
            $this->log($case, $u->id, 'note', 'Dossier ouvert : '.$docs->count().' facture(s), '.$this->money($case->amount_initial, $c).'.');
            if ($case->assigned_to !== $u->id) {
                AppNotification::send($c->id, [$case->assigned_to], 'relances', 'Dossier de recouvrement confié', $case->number.' — '.$p->name.' : '.$this->money($case->amount_initial, $c), 'relances', (string) $case->id, $u->id);
            }

            return $case;
        });
    }

    protected function log(RecoveryCase $case, ?int $userId, string $type, ?string $body = null, array $extra = []): RecoveryAction
    {
        return RecoveryAction::create(['company_id' => $case->company_id, 'case_id' => $case->id, 'user_id' => $userId, 'type' => $type, 'date' => $extra['date'] ?? now()->toDateString(),
            'body' => $body, 'amount' => $extra['amount'] ?? null, 'promise_date' => $extra['promise_date'] ?? null, 'promise_status' => $extra['promise_status'] ?? null, 'data' => $extra['data'] ?? null]);
    }

    /** Factures du dossier avec leur reste à payer. */
    public function invoices(RecoveryCase $case)
    {
        $ids = DB::table('rc_case_invoices')->where('case_id', $case->id)->pluck('amount_at_open', 'document_id');

        return SalesDocument::whereIn('id', $ids->keys())->orderBy('due_date')->orderBy('date')->get()->map(fn ($d) => [
            'id' => $d->id, 'number' => $d->number, 'date' => $d->date?->toDateString(), 'due_date' => $d->due_date?->toDateString(), 'total' => (float) $d->total,
            'balance' => $d->status === 'cancelled' ? 0.0 : round($d->balance(), 2), 'at_open' => (float) $ids[$d->id], 'days' => $this->dunning->lateDays($d), 'cancelled' => $d->status === 'cancelled',
        ]);
    }

    public function balance(RecoveryCase $case): float
    {
        return round($this->invoices($case)->sum('balance'), 2);
    }

    /** Recalcule le recouvré, l'échéancier et les promesses ; clôt le dossier soldé. */
    public function refresh(RecoveryCase $case): RecoveryCase
    {
        if (! in_array($case->status, self::OPEN, true)) {
            return $case;
        }
        $balance = $this->balance($case);
        $recovered = round(max(0, $case->amount_initial - $balance - $case->written_off), 2);
        $case->recovered = $recovered;
        // Échéancier : ce qui est encaissé depuis sa mise en place couvre les échéances dans l'ordre
        $plan = RecoveryAction::where('case_id', $case->id)->where('type', 'plan')->latest('id')->first();
        if ($plan) {
            $pool = max(0, $recovered - (float) ($plan->data['recovered_before'] ?? 0));
            foreach (RecoveryInstallment::where('case_id', $case->id)->orderBy('due_date')->get() as $i) {
                $paid = min($i->amount, $pool);
                $pool -= $paid;
                $i->forceFill(['paid' => round($paid, 2)])->save();
            }
        }
        foreach (RecoveryAction::where('case_id', $case->id)->where('type', 'promise')->where('promise_status', 'pending')->get() as $p) {
            if ($recovered - (float) ($p->data['recovered_before'] ?? 0) + 0.005 >= (float) $p->amount) {
                $p->forceFill(['promise_status' => 'kept'])->save();
            }
        }
        if ($balance <= 0.004) {
            $case->status = 'recovered';
            $case->closed_at = now();
            $this->log($case, null, 'close', 'Dossier soldé : '.$this->money($recovered, Company::find($case->company_id)).' recouvrés.');
            $this->releaseDunning($case);
        }
        $case->save();

        return $case;
    }

    protected function releaseDunning(RecoveryCase $case): void
    {
        if (! $case->dunning_was_paused) {
            Partner::whereKey($case->partner_id)->first()?->forceFill(['dunning_paused' => false])->save();
        }
    }

    // ------------------------------------------------------------------ actions

    /** Ajoute une action au dossier (opération de recouvrement). */
    public function act(Company $c, User $u, RecoveryCase $case, string $type, array $d): RecoveryCase
    {
        $this->assert(array_key_exists($type, self::ACTIONS) && ! in_array($type, ['broken', 'close', 'reopen'], true), 'Action inconnue.');
        $this->assert(in_array($case->status, self::OPEN, true), 'Ce dossier est clos.');
        $body = isset($d['body']) ? trim((string) $d['body']) ?: null : null;
        $date = isset($d['date']) ? Carbon::parse($d['date'])->toDateString() : now()->toDateString();

        DB::transaction(function () use ($c, $u, $case, $type, $d, $body, $date) {
            $case = $this->refresh($case);
            switch ($type) {
                case 'promise':
                    $amount = (float) ($d['amount'] ?? 0);
                    $this->assert($amount > 0 && ! empty($d['promise_date']), 'Indiquez le montant promis et la date.');
                    $this->log($case, $u->id, 'promise', $body, ['date' => $date, 'amount' => round($amount, 2), 'promise_date' => Carbon::parse($d['promise_date'])->toDateString(),
                        'promise_status' => 'pending', 'data' => ['recovered_before' => $case->recovered]]);
                    if ($case->status === 'open') {
                        $case->status = 'promise';
                    }
                    $case->next_action_at = Carbon::parse($d['promise_date'])->addDay()->toDateString();
                    break;
                case 'payment':
                    $amount = round((float) ($d['amount'] ?? 0), 2);
                    $balance = $this->balance($case);
                    $this->assert($amount > 0, 'Indiquez le montant encaissé.');
                    $this->assert($amount <= $balance + 0.005, 'Le montant dépasse le reste à recouvrer ('.$this->money($balance, $c).').');
                    $method = in_array($d['method'] ?? '', ['cash', 'mobile_money', 'card', 'bank_transfer', 'cheque', 'other'], true) ? $d['method'] : 'cash';
                    $left = $amount;
                    $paid = [];
                    foreach ($this->invoices($case)->where('balance', '>', 0) as $inv) {
                        if ($left <= 0.004) {
                            break;
                        }
                        $x = round(min($left, $inv['balance']), 2);
                        $res = app(EntityWriter::class)->upsert(SalesPayment::class, (string) Str::uuid(), ['document_id' => $inv['id'], 'date' => $date, 'amount' => $x, 'method' => $method,
                            'reference' => $d['reference'] ?? $case->number, 'notes' => 'Recouvrement '.$case->number, 'treasury_account_id' => $d['treasury_account_id'] ?? null]);
                        $this->assert($res['status'] === EntityWriter::APPLIED, 'Encaissement impossible : '.json_encode($res['errors'] ?? $res['message'] ?? $res));
                        $paid[] = ['number' => $inv['number'], 'amount' => $x];
                        $left = round($left - $x, 2);
                    }
                    $this->log($case, $u->id, 'payment', $body, ['date' => $date, 'amount' => $amount, 'data' => ['method' => $method, 'reference' => $d['reference'] ?? null, 'invoices' => $paid]]);
                    break;
                case 'plan':
                    $count = (int) ($d['count'] ?? 0);
                    $this->assert($count >= 2 && $count <= 36 && ! empty($d['first_date']), 'Échéancier : de 2 à 36 échéances, avec la date de la première.');
                    $balance = $this->balance($case);
                    $every = in_array($d['every'] ?? 'month', ['week', 'month'], true) ? $d['every'] : 'month';
                    RecoveryInstallment::where('case_id', $case->id)->delete();
                    $each = $balance >= $count * 5000 ? floor($balance / $count / 1000) * 1000 : floor($balance / $count); // mensualités rondes
                    $first = Carbon::parse($d['first_date']);
                    for ($i = 0; $i < $count; $i++) {
                        RecoveryInstallment::create(['company_id' => $c->id, 'case_id' => $case->id, 'due_date' => ($every === 'week' ? $first->copy()->addWeeks($i) : $first->copy()->addMonthsNoOverflow($i))->toDateString(),
                            'amount' => $i === $count - 1 ? round($balance - $each * ($count - 1), 2) : $each]);
                    }
                    $this->log($case, $u->id, 'plan', $body ?? $count.' échéance(s) de '.$this->money($each, $c).' à partir du '.$first->format('d/m/Y').'.',
                        ['date' => $date, 'amount' => $balance, 'data' => ['count' => $count, 'every' => $every, 'first_date' => $first->toDateString(), 'recovered_before' => $case->recovered]]);
                    $case->status = 'plan';
                    $case->next_action_at = $first->toDateString();
                    break;
                case 'notice':
                    $days = max(1, (int) ($d['days'] ?? $this->settings($c)['notice_days']));
                    $case->notice_deadline = Carbon::parse($date)->addDays($days)->toDateString();
                    $case->status = 'notice';
                    $case->next_action_at = $case->notice_deadline;
                    $sent = false;
                    if (! empty($d['send'])) {
                        $sent = $this->sendNotice($c, $case, $days);
                    }
                    $this->log($case, $u->id, 'notice', $body, ['date' => $date, 'amount' => $this->balance($case), 'data' => ['days' => $days, 'deadline' => $case->notice_deadline, 'sent' => $sent]]);
                    break;
                case 'bailiff':
                    $this->assert(! empty($d['bailiff']), 'Indiquez l\'huissier (étude, nom).');
                    $case->bailiff = Str::limit(trim((string) $d['bailiff']), 150, '');
                    $case->status = 'bailiff';
                    if ((float) ($d['amount'] ?? 0) > 0) {
                        $case->fees = round($case->fees + (float) $d['amount'], 2);
                    }
                    $this->log($case, $u->id, 'bailiff', $body, ['date' => $date, 'amount' => (float) ($d['amount'] ?? 0) ?: null, 'data' => ['bailiff' => $case->bailiff, 'reference' => $d['reference'] ?? null]]);
                    break;
                case 'litigation':
                    $case->status = 'litigation';
                    $this->log($case, $u->id, 'litigation', $body, ['date' => $date, 'data' => ['reference' => $d['reference'] ?? null]]);
                    break;
                case 'fees':
                    $amount = round((float) ($d['amount'] ?? 0), 2);
                    $this->assert($amount > 0, 'Indiquez le montant des frais.');
                    $case->fees = round($case->fees + $amount, 2);
                    $this->log($case, $u->id, 'fees', $body, ['date' => $date, 'amount' => $amount]);
                    break;
                case 'write_off':
                    $this->writeOff($c, $u, $case, $body);
                    break;
                default:
                    $this->assert((bool) $body, 'Décrivez l\'action (résultat de l\'appel, de la visite…).');
                    $this->log($case, $u->id, $type, $body, ['date' => $date, 'data' => array_filter(['outcome' => $d['outcome'] ?? null])]);
            }
            if (! empty($d['next_action_at'])) {
                $case->next_action_at = Carbon::parse($d['next_action_at'])->toDateString();
            }
            $case->save();
            $this->refresh($case->fresh());
        });

        return $case->fresh();
    }

    /** Passage en perte : D 651 / C 411 pour le reste dû (si la comptabilité est active). */
    protected function writeOff(Company $c, User $u, RecoveryCase $case, ?string $note): void
    {
        $this->assert((bool) $note, 'Indiquez la raison du passage en perte (client disparu, liquidation…).');
        $left = $this->balance($case);
        $this->assert($left > 0.004, 'Il ne reste rien à recouvrer.');
        $case->written_off = round($case->written_off + $left, 2);
        $case->status = 'written_off';
        $case->closed_at = now();
        if ($c->hasModule('compta')) {
            $map = \App\Modules\Compta\Chart::mapping($c);
            app(\App\Modules\Compta\Posting::class)->postExternal($c, 'OD', now()->toDateString(), 'Créance irrécouvrable '.$case->number.' — '.(Partner::find($case->partner_id)?->name ?? ''), $case->number,
                [[\App\Modules\Compta\Chart::code($c, '651'), $left, 'Perte sur créance '.$case->number], [$map['customers'], -$left, 'Perte sur créance '.$case->number, $case->partner_id]], 'recovery', (string) $case->id, 'write_off');
        }
        $this->log($case, $u->id, 'write_off', $note, ['amount' => $left]);
        $this->releaseDunning($case);
    }

    public function close(Company $c, User $u, RecoveryCase $case, string $note): RecoveryCase
    {
        $this->assert(in_array($case->status, self::OPEN, true), 'Ce dossier est déjà clos.');
        $this->assert(trim($note) !== '', 'Indiquez pourquoi le dossier est clôturé.');
        $case->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        $this->log($case, $u->id, 'close', trim($note));
        $this->releaseDunning($case);

        return $case->fresh();
    }

    public function assign(Company $c, User $u, RecoveryCase $case, int $userId): RecoveryCase
    {
        $to = User::where('company_id', $c->id)->where('is_active', true)->find($userId);
        $this->assert((bool) $to, 'Utilisateur introuvable.');
        $case->forceFill(['assigned_to' => $to->id])->save();
        $this->log($case, $u->id, 'note', 'Dossier confié à '.$to->name.'.');
        AppNotification::send($c->id, [$to->id], 'relances', 'Dossier de recouvrement confié', $case->number.' — '.(Partner::find($case->partner_id)?->name ?? ''), 'relances', (string) $case->id, $u->id);

        return $case->fresh();
    }

    /** Contrôle quotidien : promesses non tenues, échéances manquées, mise en demeure expirée. */
    public function check(Company $c): array
    {
        $out = ['broken' => 0, 'installments' => 0, 'notices' => 0];
        $today = now()->toDateString();
        foreach (RecoveryCase::whereIn('status', self::OPEN)->get() as $case) {
            $case = $this->refresh($case);
            if (! in_array($case->status, self::OPEN, true)) {
                continue;
            }
            $name = Partner::find($case->partner_id)?->name ?? '';
            foreach (RecoveryAction::where('case_id', $case->id)->where('type', 'promise')->where('promise_status', 'pending')->where('promise_date', '<', $today)->get() as $p) {
                $p->forceFill(['promise_status' => 'broken'])->save();
                $this->log($case, null, 'broken', 'Promesse du '.$p->date->format('d/m/Y').' ('.$this->money($p->amount, $c).' pour le '.$p->promise_date->format('d/m/Y').') non tenue.');
                AppNotification::send($c->id, [$case->assigned_to], 'relances', 'Promesse de paiement non tenue', $case->number.' — '.$name, 'relances', (string) $case->id);
                if ($case->status === 'promise') {
                    $case->forceFill(['status' => 'open', 'next_action_at' => $today])->save();
                }
                $out['broken']++;
            }
            foreach (RecoveryInstallment::where('case_id', $case->id)->where('due_date', '<', $today)->where('alerted', false)->get() as $i) {
                if ($i->paid + 0.005 < $i->amount) {
                    $i->forceFill(['alerted' => true])->save();
                    AppNotification::send($c->id, [$case->assigned_to], 'relances', 'Échéance de recouvrement impayée', $case->number.' — '.$name.' : échéance du '.$i->due_date->format('d/m/Y'), 'relances', (string) $case->id);
                    $out['installments']++;
                }
            }
            if ($case->status === 'notice' && $case->notice_deadline && $case->notice_deadline->toDateString() < $today && ! RecoveryAction::where('case_id', $case->id)->where('type', 'note')->where('body', 'like', 'Délai de la mise en demeure expiré%')->exists()) {
                $this->log($case, null, 'note', 'Délai de la mise en demeure expiré le '.$case->notice_deadline->format('d/m/Y').' : transmettez à l\'huissier ou relancez.');
                AppNotification::send($c->id, [$case->assigned_to], 'relances', 'Mise en demeure restée sans effet', $case->number.' — '.$name, 'relances', (string) $case->id);
                $out['notices']++;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ mise en demeure

    public function noticePdf(Company $c, RecoveryCase $case, ?int $days = null): string
    {
        $days ??= $this->settings($c)['notice_days'];
        $p = Partner::find($case->partner_id);
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $inv = $this->invoices($case)->where('balance', '>', 0);
        $total = round($inv->sum('balance'), 2);
        $text = strtr($this->settings($c)['notice_text'], ['{montant}' => $this->money($total, $c), '{delai}' => (string) $days, '{client}' => $p?->name ?? '']);
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('Yomara ERP');
        $pdf->SetTitle('Mise en demeure '.$case->number);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(20, 18, 20);
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 10);
        $html = '<table><tr><td width="55%"><b style="font-size:13pt">'.$e($c->name).'</b><br><span style="color:#555">'.$e(trim(($c->address ?? '').' '.($c->city ?? ''))).'<br>'.$e($c->phone).' '.$e($c->email).'</span></td>'
            .'<td width="45%"><br><br><b>'.$e($p?->name).'</b><br>'.$e(trim(($p?->address ?? '').' '.($p?->city ?? ''))).'</td></tr></table><br><br>'
            .'<p align="right">'.$e(($c->city ?: 'Abidjan').', le '.now()->locale('fr')->translatedFormat('j F Y')).'</p>'
            .'<p><b>Lettre recommandée avec accusé de réception</b><br>Réf. : '.$e($case->number).'</p>'
            .'<p><b><u>Objet : MISE EN DEMEURE DE PAYER</u></b></p><p>Madame, Monsieur,</p><p>'.nl2br($e($text)).'</p>'
            .'<table cellpadding="5" border="0.3"><tr bgcolor="#eeeeee" style="font-weight:bold"><td width="25%">Facture</td><td width="20%">Date</td><td width="20%">Échéance</td><td width="35%" align="right">Reste dû</td></tr>';
        foreach ($inv as $i) {
            $html .= '<tr><td>'.$e($i['number']).'</td><td>'.$e(Carbon::parse($i['date'])->format('d/m/Y')).'</td><td>'.$e($i['due_date'] ? Carbon::parse($i['due_date'])->format('d/m/Y') : '').'</td><td align="right">'.$e($this->money($i['balance'], $c)).'</td></tr>';
        }
        $html .= '<tr style="font-weight:bold"><td colspan="3" align="right">TOTAL DÛ</td><td align="right">'.$e($this->money($total, $c)).'</td></tr></table>'
            .'<p>Nous restons disponibles pour convenir d\'un règlement amiable avant l\'expiration de ce délai.</p><p>Veuillez agréer, Madame, Monsieur, l\'expression de nos salutations distinguées.</p><br><br>'
            .'<p align="right"><b>'.$e($c->name).'</b><br>La Direction</p>';
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output('', 'S');
    }

    protected function sendNotice(Company $c, RecoveryCase $case, int $days): bool
    {
        $p = Partner::find($case->partner_id);
        if (! $p?->email || ! ($this->messaging->available($c)['email'] ?? false)) {
            return false;
        }
        $msg = new Message($p->email, 'Mise en demeure — '.$c->name, '<p>Madame, Monsieur,</p><p>Veuillez trouver ci-joint notre mise en demeure concernant vos factures impayées ('.htmlspecialchars($this->money($this->balance($case), $c)).').</p><p>'.htmlspecialchars($c->name).'</p>',
            null, [['name' => 'mise-en-demeure-'.Str::slug($case->number).'.pdf', 'data' => $this->noticePdf($c, $case, $days), 'mime' => 'application/pdf']], $p->name);

        return (bool) rescue(fn () => $this->messaging->send($c, 'email', $msg, 'recovery')->ok, false, false);
    }

    public function money(float $v, Company $c): string
    {
        return number_format($v, 0, ',', ' ').' '.(in_array($c->currency, ['XOF', 'XAF', null], true) ? 'F CFA' : $c->currency);
    }

    // ------------------------------------------------------------------ restitution

    public function toApi(RecoveryCase $case, bool $full = false): array
    {
        $p = Partner::withTrashed()->find($case->partner_id);
        $inv = $this->invoices($case);
        $balance = round($inv->sum('balance'), 2);
        $next = RecoveryInstallment::where('case_id', $case->id)->whereColumn('paid', '<', 'amount')->orderBy('due_date')->first();
        $promise = RecoveryAction::where('case_id', $case->id)->where('type', 'promise')->where('promise_status', 'pending')->orderBy('promise_date')->first();
        $out = [
            'id' => $case->id, 'number' => $case->number, 'status' => $case->status, 'status_label' => self::STATUSES[$case->status] ?? $case->status,
            'partner_id' => $case->partner_id, 'partner' => $p?->name, 'phone' => $p?->phone, 'email' => $p?->email, 'aux_code' => $p?->aux_code,
            'assigned_to' => $case->assigned_to, 'assignee' => User::find($case->assigned_to)?->name, 'opened_at' => $case->opened_at?->toDateString(), 'closed_at' => $case->closed_at,
            'next_action_at' => $case->next_action_at?->toDateString(), 'amount_initial' => $case->amount_initial, 'balance' => $balance, 'recovered' => $case->recovered,
            'fees' => $case->fees, 'written_off' => $case->written_off, 'bailiff' => $case->bailiff, 'notice_deadline' => $case->notice_deadline?->toDateString(), 'note' => $case->note,
            'invoices_count' => $inv->count(), 'oldest_days' => $inv->where('balance', '>', 0)->max('days') ?? 0,
            'rate' => $case->amount_initial > 0 ? round($case->recovered * 100 / $case->amount_initial) : 0,
            'next_installment' => $next ? ['due_date' => $next->due_date->toDateString(), 'amount' => $next->amount - $next->paid] : null,
            'promise' => $promise ? ['date' => $promise->promise_date->toDateString(), 'amount' => $promise->amount] : null,
            'late_action' => $case->next_action_at && $case->next_action_at->lt(now()->startOfDay()) && in_array($case->status, self::OPEN, true),
        ];
        if ($full) {
            $out['invoices'] = $inv->values();
            $acts = RecoveryAction::where('case_id', $case->id)->orderByDesc('date')->orderByDesc('id')->get();
            $names = User::whereIn('id', $acts->pluck('user_id')->filter())->pluck('name', 'id');
            $out['actions'] = $acts->map(fn ($a) => ['id' => $a->id, 'type' => $a->type, 'label' => self::ACTIONS[$a->type] ?? $a->type, 'date' => $a->date->toDateString(), 'body' => $a->body,
                'amount' => $a->amount, 'promise_date' => $a->promise_date?->toDateString(), 'promise_status' => $a->promise_status, 'data' => $a->data, 'user' => $names[$a->user_id] ?? null])->values();
            $out['installments'] = RecoveryInstallment::where('case_id', $case->id)->orderBy('due_date')->get()->map(fn ($i) => ['id' => $i->id, 'due_date' => $i->due_date->toDateString(),
                'amount' => $i->amount, 'paid' => $i->paid, 'late' => $i->paid + 0.005 < $i->amount && $i->due_date->lt(now()->startOfDay())])->values();
        }

        return $out;
    }

    public function dashboard(Company $c, string $from, string $to): array
    {
        $cases = RecoveryCase::all();
        foreach ($cases->whereIn('status', self::OPEN) as $case) {
            $this->refresh($case);
        }
        $cases = RecoveryCase::all();
        $open = $cases->whereIn('status', self::OPEN);
        $openApi = $open->map(fn ($x) => $this->toApi($x));
        $payments = RecoveryAction::where('type', 'payment')->whereBetween('date', [$from, $to])->get();
        $start = Carbon::parse($to)->startOfMonth()->subMonths(11);
        $byMonth = RecoveryAction::where('type', 'payment')->where('date', '>=', $start->toDateString())->get()->groupBy(fn ($a) => $a->date->format('Y-m'));
        $months = [];
        for ($m = $start->copy(); $m->lte(Carbon::parse($to)); $m->addMonth()) {
            $months[] = ['month' => $m->format('Y-m'), 'recovered' => round($byMonth->get($m->format('Y-m'), collect())->sum('amount'), 2)];
        }
        $promises = RecoveryAction::where('type', 'promise')->whereBetween('date', [$from, $to])->get();
        $aging = ['0-90' => 0.0, '91-180' => 0.0, '181-365' => 0.0, '365+' => 0.0];
        foreach ($openApi as $o) {
            $k = match (true) { $o['oldest_days'] <= 90 => '0-90', $o['oldest_days'] <= 180 => '91-180', $o['oldest_days'] <= 365 => '181-365', default => '365+' };
            $aging[$k] += $o['balance'];
        }
        $cand = $this->candidates($c);
        $closedPeriod = $cases->filter(fn ($x) => $x->closed_at && $x->closed_at->between($from, Carbon::parse($to)->endOfDay()));

        return [
            'from' => $from, 'to' => $to,
            'kpis' => [
                'open' => $open->count(), 'open_amount' => round($openApi->sum('balance'), 2), 'recovered' => round($payments->sum('amount'), 2),
                'rate' => $cases->sum('amount_initial') > 0 ? round($cases->sum('recovered') * 100 / $cases->sum('amount_initial')) : null,
                'written_off' => round($closedPeriod->sum('written_off'), 2), 'fees' => round($open->sum('fees'), 2),
                'promises' => $promises->count(), 'promises_kept' => $promises->where('promise_status', 'kept')->count(), 'promises_broken' => $promises->where('promise_status', 'broken')->count(),
                'due_week' => RecoveryAction::where('type', 'promise')->where('promise_status', 'pending')->whereBetween('promise_date', [now()->toDateString(), now()->addDays(7)->toDateString()])->count()
                    + RecoveryInstallment::whereIn('case_id', $open->pluck('id'))->whereColumn('paid', '<', 'amount')->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])->count(),
                'late_actions' => $openApi->where('late_action', true)->count(), 'candidates' => count($cand), 'candidates_amount' => round(array_sum(array_column($cand, 'amount')), 2),
            ],
            'by_status' => collect(self::STATUSES)->map(fn ($l, $k) => ['status' => $k, 'label' => $l, 'count' => $cases->where('status', $k)->count(),
                'amount' => round($k === 'recovered' ? $cases->where('status', $k)->sum('recovered') : $openApi->where('status', $k)->sum('balance'), 2)])->filter(fn ($x) => $x['count'] > 0)->values(),
            'by_assignee' => $openApi->groupBy('assignee')->map(fn ($g, $k) => ['assignee' => $k ?: '—', 'count' => $g->count(), 'amount' => round($g->sum('balance'), 2)])->values(),
            'aging' => collect($aging)->map(fn ($v, $k) => ['bucket' => $k, 'amount' => round($v, 2)])->values(),
            'months' => $months,
            'top' => $openApi->sortByDesc('balance')->take(6)->values(),
            'candidates' => array_slice($cand, 0, 6),
        ];
    }
}
