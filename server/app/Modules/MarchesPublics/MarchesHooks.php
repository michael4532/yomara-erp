<?php

namespace App\Modules\MarchesPublics;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Ventes\Models\SalesDocument;
use App\Services\EntityWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Appels d'offres et marchés : compte à rebours, dossier de soumission, cautions, décomptes et alertes. */
class MarchesHooks
{
    public const TENDER_STATUSES = ['watch' => ['Veille', '#64748B'], 'go' => ['On soumissionne', '#1F6FEB'], 'preparing' => ['Dossier en préparation', '#D97706'],
        'submitted' => ['Offre déposée', '#9333EA'], 'won' => ['Attribué', '#16A34A'], 'lost' => ['Non retenu', '#DC2626'], 'no_go' => ['Pas de soumission', '#94A3B8'],
        'cancelled' => ['Infructueux / annulé', '#94A3B8']];

    public const OPEN = ['watch', 'go', 'preparing'];

    public const PROCEDURES = ['open' => 'Appel d\'offres ouvert', 'restricted' => 'Appel d\'offres restreint', 'quotation' => 'Demande de cotation / PSL',
        'ami' => 'Manifestation d\'intérêt (AMI)', 'direct' => 'Gré à gré / entente directe', 'private' => 'Consultation privée', 'international' => 'Appel d\'offres international'];

    public const SECTORS = ['works' => 'Travaux', 'supplies' => 'Fournitures', 'services' => 'Services', 'intellectual' => 'Prestations intellectuelles'];

    public const CHANCES = ['low' => ['Faibles', '#DC2626'], 'medium' => ['Moyennes', '#D97706'], 'high' => ['Bonnes', '#16A34A']];

    public const PIECE_CATEGORIES = ['admin' => 'Administrative', 'technical' => 'Technique', 'financial' => 'Financière'];

    public const PIECE_STATUSES = ['todo' => ['À faire', '#64748B'], 'in_progress' => ['En cours', '#D97706'], 'ready' => ['Prête', '#16A34A'], 'na' => ['Sans objet', '#94A3B8']];

    public const DOC_KINDS = ['tax' => 'Attestation de régularité fiscale', 'cnps' => 'Attestation CNPS', 'rccm' => 'RCCM', 'dfe' => 'Déclaration fiscale d\'existence (DFE)',
        'bankruptcy' => 'Attestation de non-faillite', 'bank' => 'Attestation bancaire / capacité financière', 'statutes' => 'Statuts', 'insurance' => 'Assurance',
        'license' => 'Agrément / licence', 'financials' => 'États financiers', 'reference' => 'Attestation de bonne exécution', 'other' => 'Autre'];

    public const CONTRACT_STATUSES = ['signed' => ['Signé', '#1F6FEB'], 'in_progress' => ['En exécution', '#D97706'], 'suspended' => ['Suspendu', '#DC2626'],
        'provisional' => ['Réception provisoire', '#9333EA'], 'final' => ['Réception définitive', '#16A34A'], 'closed' => ['Soldé', '#94A3B8'], 'terminated' => ['Résilié', '#DC2626']];

    public const STATEMENT_STATUSES = ['draft' => ['Brouillon', '#64748B'], 'submitted' => ['Déposé', '#D97706'], 'approved' => ['Approuvé', '#1F6FEB'], 'paid' => ['Payé', '#16A34A'], 'rejected' => ['Rejeté', '#DC2626']];

    public const BOND_KINDS = ['bid' => 'Caution de soumission', 'advance' => 'Caution d\'avance de démarrage', 'performance' => 'Caution de bonne exécution', 'retention' => 'Caution de retenue de garantie'];

    public const BOND_STATUSES = ['requested' => ['Demandée', '#64748B'], 'issued' => ['En cours', '#1F6FEB'], 'released' => ['Mainlevée obtenue', '#16A34A'], 'called' => ['Appelée', '#DC2626']];

    /** Pièces habituelles d'un dossier de soumission (Côte d'Ivoire / UEMOA), ajustables dossier par dossier. */
    public const STANDARD_PIECES = [
        ['admin', 'Achat du dossier d\'appel d\'offres (reçu)', null],
        ['admin', 'Lettre de soumission signée', null],
        ['admin', 'Attestation de régularité fiscale', 'tax'],
        ['admin', 'Attestation CNPS', 'cnps'],
        ['admin', 'Registre du commerce (RCCM)', 'rccm'],
        ['admin', 'Déclaration fiscale d\'existence (DFE)', 'dfe'],
        ['admin', 'Attestation de non-faillite', 'bankruptcy'],
        ['admin', 'Pouvoir du signataire', null],
        ['admin', 'Caution de soumission', null],
        ['technical', 'Références de marchés similaires et attestations de bonne exécution', 'reference'],
        ['technical', 'CV et diplômes du personnel clé', null],
        ['technical', 'Liste du matériel et justificatifs', null],
        ['technical', 'Méthodologie et organisation', null],
        ['technical', 'Planning d\'exécution', null],
        ['financial', 'Bordereau des prix unitaires', null],
        ['financial', 'Devis quantitatif et estimatif', null],
        ['financial', 'Attestation de capacité financière / chiffre d\'affaires', 'bank'],
    ];

    protected static function days(?string $date): ?int
    {
        return $date ? (int) now()->startOfDay()->diffInDays(Carbon::parse($date)->startOfDay(), false) : null;
    }

    public function present(string $res, array $row): array
    {
        switch ($res) {
            case 'tenders':
                $row['countdown'] = null;
                if ($row['submission_at'] && in_array($row['status'], self::OPEN, true)) {
                    $at = Carbon::parse($row['submission_at']);
                    $d = self::days($row['submission_at']);
                    $row['days_left'] = $d;
                    $row['countdown'] = match (true) {
                        $at->isPast() => 'Date de dépôt dépassée',
                        $d === 0 => 'Dépôt aujourd\'hui à '.$at->format('H:i'),
                        $d === 1 => 'Dépôt demain à '.$at->format('H:i'),
                        default => 'Dépôt dans '.$d.' j',
                    };
                }
                $p = DB::table('mp_pieces')->where('company_id', $row['company_id'])->where('tender_id', $row['id'])->where('status', '!=', 'na')
                    ->selectRaw('count(*) as n, sum(case when status = \'ready\' then 1 else 0 end) as ok')->first();
                $row['pieces_total'] = (int) ($p->n ?? 0);
                $row['pieces_ready'] = (int) ($p->ok ?? 0);
                $row['pieces_progress'] = $row['pieces_total'] ? $row['pieces_ready'].'/'.$row['pieces_total'].' prêtes' : null;
                break;
            case 'pieces':
                $row['alert'] = null;
                if ($row['status'] !== 'ready' && $row['status'] !== 'na' && $row['due_date'] && ($d = self::days($row['due_date'])) !== null && $d <= 3) {
                    $row['alert'] = $d < 0 ? 'En retard de '.abs($d).' j' : ($d === 0 ? 'À fournir aujourd\'hui' : 'À fournir dans '.$d.' j');
                }
                if ($row['document_id']) {
                    $doc = DB::table('mp_documents')->where('id', $row['document_id'])->first();
                    $t = DB::table('mp_tenders')->where('id', $row['tender_id'])->value('submission_at');
                    if ($doc && $doc->expires_on && $t && Carbon::parse($doc->expires_on)->lt(Carbon::parse($t)->startOfDay())) {
                        $row['alert'] = 'Pièce expirée à la date de dépôt ('.date('d/m/Y', strtotime($doc->expires_on)).')';
                    }
                }
                break;
            case 'documents':
                $d = self::days($row['expires_on']);
                $row['days_left'] = $d;
                $row['alert'] = $d === null ? null : ($d < 0 ? 'Expirée depuis '.abs($d).' j' : ($d <= 30 ? 'Expire dans '.$d.' j' : 'Valide'));
                break;
            case 'contracts':
                $st = DB::table('mp_statements')->where('company_id', $row['company_id'])->where('contract_id', $row['id'])->where('status', '!=', 'rejected');
                $row['executed'] = (float) (clone $st)->sum('amount');
                $row['retention_held'] = (float) (clone $st)->sum('retention');
                $row['paid'] = (float) (clone $st)->where('status', 'paid')->sum('net');
                $row['remaining'] = max(0, (float) $row['amount'] - $row['executed']);
                $pct = (float) $row['amount'] > 0 ? min(100, round($row['executed'] / (float) $row['amount'] * 100)) : 0;
                // avancement déclaré sur le dernier décompte, sinon montant exécuté / montant du marché
                $last = (clone $st)->orderByDesc('id')->value('progress_percent');
                $row['progress_pct'] = $last !== null ? (float) $last : $pct;
                $row['progress'] = rtrim(rtrim(number_format($row['progress_pct'], 1, ',', ''), '0'), ',').' % exécuté';
                $row['alert'] = self::contractAlert($row);
                break;
            case 'statements':
                $row['invoice'] = null;
                if ($row['invoice_id'] && class_exists(SalesDocument::class)) {
                    $doc = DB::table('trade_documents')->where('id', $row['invoice_id'])->first(['number', 'status']);
                    $row['invoice'] = $doc ? ($doc->number ?: 'Brouillon') : null;
                }
                break;
            case 'bonds':
                $row['alert'] = null;
                if (in_array($row['status'], ['issued', 'requested'], true) && ($d = self::days($row['expires_on'])) !== null) {
                    $row['alert'] = $d < 0 ? 'Échue depuis '.abs($d).' j : demander la mainlevée ou la prorogation' : ($d <= 30 ? 'Expire dans '.$d.' j' : null);
                }
                if ($row['status'] === 'issued' && ! $row['alert']) {
                    $row['alert'] = $this->releaseHint($row);
                }
                break;
        }

        return $row;
    }

    protected static function contractAlert(array $row): ?string
    {
        if (in_array($row['status'], ['signed', 'in_progress'], true) && $row['planned_end']) {
            $d = self::days($row['planned_end']);
            if ($d < 0) {
                $pen = $row['penalty_per_day'] ? ' — pénalités possibles : '.number_format((float) $row['amount'] * (float) $row['penalty_per_day'] / 1000 * abs($d), 0, ',', ' ') : '';

                return 'En retard de '.abs($d).' j'.$pen;
            }
            if ($d <= 15) {
                return 'Fin prévue dans '.$d.' j';
            }
        }
        if ($row['status'] === 'provisional' && $row['provisional_acceptance_on']) {
            $due = Carbon::parse($row['provisional_acceptance_on'])->addMonths((int) $row['warranty_months']);
            if ($due->lte(now()->addDays(30))) {
                return 'Réception définitive à demander ('.$due->format('d/m/Y').')';
            }
        }
        if ($row['status'] === 'final' && ($row['retention_held'] ?? 0) > 0) {
            return 'Retenue de garantie à récupérer';
        }

        return null;
    }

    /** Mainlevée : soumission après attribution, avance remboursée, bonne exécution après réception. */
    protected function releaseHint(array $b): ?string
    {
        if ($b['kind'] === 'bid' && $b['tender_id']) {
            $s = DB::table('mp_tenders')->where('id', $b['tender_id'])->value('status');

            return in_array($s, ['won', 'lost', 'cancelled'], true) ? 'Résultat connu : demander la mainlevée' : null;
        }
        if ($b['contract_id'] && ($k = DB::table('mp_contracts')->where('id', $b['contract_id'])->first())) {
            if ($b['kind'] === 'performance' && in_array($k->status, ['provisional', 'final', 'closed'], true)) {
                return 'Réception prononcée : demander la mainlevée';
            }
            if ($b['kind'] === 'retention' && in_array($k->status, ['final', 'closed'], true)) {
                return 'Réception définitive : demander la mainlevée';
            }
            if ($b['kind'] === 'advance' && (float) $k->advance_percent > 0) {
                $repaid = (float) DB::table('mp_statements')->where('contract_id', $k->id)->where('status', '!=', 'rejected')->sum('advance_repayment');
                if ($repaid >= round((float) $k->amount * (float) $k->advance_percent / 100, 2) - 1) {
                    return 'Avance remboursée : demander la mainlevée';
                }
            }
        }

        return null;
    }

    public function saving(string $res, array $data, ?array $old, User $u): array
    {
        $c = app('tenant');
        if ($res === 'contracts') {
            $start = $data['start_order_on'] ?? $old['start_order_on'] ?? null;
            $dur = $data['duration_days'] ?? $old['duration_days'] ?? null;
            if (empty($data['planned_end'] ?? $old['planned_end'] ?? null) && $start && $dur) {
                $data['planned_end'] = Carbon::parse($start)->addDays((int) $dur)->toDateString();
            }
            if (($data['provisional_acceptance_on'] ?? null) && ! ($old['provisional_acceptance_on'] ?? null) && in_array($data['status'] ?? $old['status'] ?? '', ['signed', 'in_progress'], true)) {
                $data['status'] = 'provisional';
            }
            if (($data['final_acceptance_on'] ?? null) && ! ($old['final_acceptance_on'] ?? null) && in_array($data['status'] ?? $old['status'] ?? '', ['signed', 'in_progress', 'provisional'], true)) {
                $data['status'] = 'final';
            }
        } elseif ($res === 'statements') {
            $cid = $data['contract_id'] ?? $old['contract_id'];
            $k = DB::table('mp_contracts')->where('company_id', $c->id)->where('id', $cid)->first() ?: throw new \DomainException('Marché introuvable.');
            if ($old && $old['invoice_id'] && isset($data['amount']) && (float) $data['amount'] !== (float) $old['amount']) {
                throw new \DomainException('Ce décompte est déjà facturé : établissez un nouveau décompte (ou un avoir) plutôt que de modifier le montant.');
            }
            $amount = (float) ($data['amount'] ?? $old['amount']);
            $dec = ($c->currency ?? 'XOF') === 'XOF' ? 0 : 2;
            $data['retention'] = round($amount * (float) $k->retention_percent / 100, $dec);
            // avance remboursée au prorata des décomptes, sans dépasser l'avance versée
            $advance = round((float) $k->amount * (float) $k->advance_percent / 100, $dec);
            $already = (float) DB::table('mp_statements')->where('contract_id', $k->id)->where('status', '!=', 'rejected')->when($old, fn ($q) => $q->where('id', '!=', $old['id']))->sum('advance_repayment');
            $data['advance_repayment'] = max(0, min($advance - $already, round($amount * (float) $k->advance_percent / 100, $dec)));
            $data['net'] = $amount - $data['retention'] - $data['advance_repayment'];
            if (! $old && empty($data['number'])) {
                $data['number'] = 'Décompte n° '.(DB::table('mp_statements')->where('contract_id', $k->id)->count() + 1);
            }
            if (($data['status'] ?? null) === 'paid' && empty($data['paid_on']) && empty($old['paid_on'])) {
                $data['paid_on'] = now()->toDateString();
            }
            if (($data['status'] ?? null) === 'submitted' && empty($data['submitted_on']) && empty($old['submitted_on'])) {
                $data['submitted_on'] = now()->toDateString();
            }
        } elseif ($res === 'bonds') {
            if (($data['status'] ?? null) === 'released' && empty($data['released_on']) && empty($old['released_on'])) {
                $data['released_on'] = now()->toDateString();
            }
            if (isset($data['expires_on']) && $data['expires_on'] !== ($old['expires_on'] ?? null)) {
                $data['alerted_on'] = null;
            }
        } elseif ($res === 'documents' && isset($data['expires_on']) && $data['expires_on'] !== ($old['expires_on'] ?? null)) {
            $data['alerted_on'] = null;
        } elseif ($res === 'tenders' && isset($data['submission_at']) && $data['submission_at'] !== ($old['submission_at'] ?? null)) {
            $data['reminded_at'] = null;
        }

        return $data;
    }

    public function saved(string $res, array $row, ?array $old, User $u): void
    {
        if ($res !== 'tenders') {
            return;
        }
        $c = app('tenant');
        // On se lance : la liste des pièces habituelles est créée une fois
        if (in_array($row['status'], ['go', 'preparing'], true) && ! in_array($old['status'] ?? null, ['go', 'preparing'], true)) {
            $this->createChecklist($c, $row);
        }
        if ($row['status'] === 'won' && ($old['status'] ?? null) !== 'won') {
            $this->award($c, $row['id']);
        }
    }

    public function deleting(string $res, array $row, User $u): void
    {
        if ($res === 'tenders') {
            DB::table('mp_pieces')->where('company_id', $row['company_id'])->where('tender_id', $row['id'])->delete();
        } elseif ($res === 'contracts' && DB::table('mp_statements')->where('contract_id', $row['id'])->exists()) {
            throw new \DomainException('Ce marché a des décomptes : supprimez-les d\'abord ou passez le marché à « Soldé ».');
        } elseif ($res === 'statements' && $row['invoice_id']) {
            throw new \DomainException('Ce décompte est facturé : il ne peut plus être supprimé.');
        }
    }

    /** Crée la liste des pièces habituelles (si le dossier n'en a pas encore), reliées aux pièces administratives à jour. */
    public function createChecklist(Company $c, array $tender): int
    {
        if (DB::table('mp_pieces')->where('company_id', $c->id)->where('tender_id', $tender['id'])->exists()) {
            return 0;
        }
        $due = $tender['submission_at'] ? Carbon::parse($tender['submission_at'])->subDays(2)->toDateString() : null;
        $n = 0;
        foreach (self::STANDARD_PIECES as $i => [$cat, $title, $kind]) {
            if ($title === 'Caution de soumission' && ! (float) ($tender['bid_bond_amount'] ?? 0)) {
                continue;
            }
            $doc = $kind ? DB::table('mp_documents')->where('company_id', $c->id)->where('kind', $kind)->orderByDesc('expires_on')->first() : null;
            $valid = $doc && (! $doc->expires_on || ! $tender['submission_at'] || Carbon::parse($doc->expires_on)->gte(Carbon::parse($tender['submission_at'])->startOfDay()));
            DB::table('mp_pieces')->insert(['company_id' => $c->id, 'tender_id' => $tender['id'], 'title' => $title, 'category' => $cat, 'status' => $valid ? 'ready' : 'todo',
                'document_id' => $doc->id ?? null, 'file_url' => $valid ? $doc->file_url : null, 'owner_id' => $tender['owner_id'], 'due_date' => $due, 'position' => $i + 1,
                'created_at' => now(), 'updated_at' => now()]);
            $n++;
        }

        return $n;
    }

    /** Attribué : le marché est créé à partir de l'offre (une seule fois). */
    public function award(Company $c, int $tenderId): array
    {
        $t = DB::table('mp_tenders')->where('company_id', $c->id)->where('id', $tenderId)->first() ?: throw new \DomainException('Appel d\'offres introuvable.');
        if ($k = DB::table('mp_contracts')->where('company_id', $c->id)->where('tender_id', $t->id)->first()) {
            return (array) $k;
        }
        if ($t->status !== 'won') {
            DB::table('mp_tenders')->where('id', $t->id)->update(['status' => 'won', 'updated_at' => now()]);
        }
        $id = DB::table('mp_contracts')->insertGetId(['company_id' => $c->id, 'created_by' => auth()->id(), 'owner_id' => $t->owner_id, 'title' => $t->title.($t->lot ? ' — '.$t->lot : ''),
            'tender_id' => $t->id, 'buyer' => $t->buyer, 'partner_id' => $t->partner_id, 'status' => 'signed', 'amount' => (float) ($t->winning_amount ?: $t->our_bid ?: 0),
            'tax_rate' => 18, 'retention_percent' => 5, 'warranty_months' => 12, 'created_at' => now(), 'updated_at' => now()]);

        return (array) DB::table('mp_contracts')->find($id);
    }

    /** Facture du décompte (brouillon, à valider au service ventes) : montant, retenue de garantie, remboursement de l'avance. */
    public function invoice(Company $c, int $statementId): array
    {
        if (! $c->hasModule('ventes')) {
            throw new \DomainException('Le module Ventes & Facturation n\'est pas activé.');
        }
        $s = DB::table('mp_statements')->where('company_id', $c->id)->where('id', $statementId)->lockForUpdate()->first() ?: throw new \DomainException('Décompte introuvable.');
        if ($s->invoice_id) {
            throw new \DomainException('Ce décompte est déjà facturé.');
        }
        $k = DB::table('mp_contracts')->where('id', $s->contract_id)->first();
        if (! $k->partner_id) {
            throw new \DomainException('Indiquez le client à facturer sur le marché.');
        }
        $tax = (float) $k->tax_rate;
        $line = fn ($d, $q, $p) => ['id' => (string) Str::uuid(), 'description' => Str::limit($d, 250, ''), 'quantity' => $q, 'unit_price' => $p, 'tax_rate' => $tax, 'discount_percent' => 0];
        $lines = [$line(($s->number ?: 'Décompte').' — '.$k->title.($s->period_end ? ' (arrêté au '.date('d/m/Y', strtotime($s->period_end)).')' : ''), 1, (float) $s->amount)];
        if ((float) $s->retention > 0) {
            $lines[] = $line('Retenue de garantie '.rtrim(rtrim(number_format((float) $k->retention_percent, 2, ',', ''), '0'), ',').' %', -1, (float) $s->retention);
        }
        if ((float) $s->advance_repayment > 0) {
            $lines[] = $line('Remboursement de l\'avance de démarrage', -1, (float) $s->advance_repayment);
        }
        $id = (string) Str::uuid();
        SalesDocument::$systemWrite = true;
        try {
            $res = app(EntityWriter::class)->upsert(SalesDocument::class, $id, [
                'type' => 'invoice', 'partner_id' => $k->partner_id, 'date' => now()->toDateString(), 'reference' => $k->number ?: null,
                'status' => 'draft', 'lines' => $lines, 'notes' => 'Marché '.($k->number ? 'n° '.$k->number.' ' : '').'— '.$k->title.($k->buyer ? "\n".$k->buyer : ''),
            ]);
        } finally {
            SalesDocument::$systemWrite = false;
        }
        if ($res['status'] !== EntityWriter::APPLIED) {
            throw new \DomainException('Facture refusée : '.($res['message'] ?? ''));
        }
        DB::table('mp_statements')->where('id', $s->id)->update(['invoice_id' => $id, 'status' => $s->status === 'draft' ? 'submitted' : $s->status,
            'submitted_on' => $s->submitted_on ?: now()->toDateString(), 'updated_at' => now()]);

        return (array) DB::table('mp_statements')->find($s->id);
    }

    protected function managers(int $companyId): array
    {
        return User::where('company_id', $companyId)->where('is_active', true)->get()->filter(fn ($x) => $x->hasPermission('marches.manage'))->pluck('id')->all();
    }

    /** Rappels : dates de dépôt (J-7, J-2, veille), pièces administratives et cautions qui expirent, marchés en retard. */
    public function alerts(Company $c): int
    {
        $n = 0;
        $to = fn ($owner) => $owner ? [$owner] : $this->managers($c->id);
        foreach (DB::table('mp_tenders')->where('company_id', $c->id)->whereIn('status', self::OPEN)->whereBetween('submission_at', [now(), now()->addDays(7)->endOfDay()])->get() as $t) {
            $d = self::days($t->submission_at);
            $step = $d <= 1 ? 1 : ($d <= 2 ? 2 : 7);
            $last = $t->reminded_at ? self::days(substr($t->reminded_at, 0, 10)) : null;
            if ($t->reminded_at && Carbon::parse($t->reminded_at)->diffInHours(now()) < 20) {
                continue;
            }
            if ($t->reminded_at && $step === 7 && $last !== null && $last >= -7) {
                continue; // J-7 déjà envoyé, on attend J-2
            }
            $p = $this->present('tenders', (array) $t);
            AppNotification::send($c->id, $to($t->owner_id), 'marches', ($d <= 1 ? 'Dépôt demain' : 'Dépôt dans '.$d.' jours').' : '.Str::limit($t->title, 80),
                'Date limite le '.date('d/m/Y à H:i', strtotime($t->submission_at)).($p['pieces_total'] ? ' — '.$p['pieces_ready'].'/'.$p['pieces_total'].' pièces prêtes.' : '.'), 'marches', (string) $t->id);
            DB::table('mp_tenders')->where('id', $t->id)->update(['reminded_at' => now()]);
            $n++;
        }
        foreach (DB::table('mp_documents')->where('company_id', $c->id)->whereNotNull('expires_on')->where('expires_on', '<=', now()->addDays(30)->toDateString())->get() as $doc) {
            $d = self::days($doc->expires_on);
            $due = $doc->alerted_on === null || ($d <= 7 && self::days($doc->alerted_on) < -15) || ($d < 0 && Carbon::parse($doc->alerted_on)->lt(Carbon::parse($doc->expires_on)));
            if (! $due) {
                continue;
            }
            AppNotification::send($c->id, $to($doc->owner_id), 'marches', $d < 0 ? 'Pièce administrative expirée' : 'Pièce administrative à renouveler',
                '« '.$doc->title.' » '.($d < 0 ? 'a expiré le ' : 'expire le ').date('d/m/Y', strtotime($doc->expires_on)).'.', 'marches', (string) $doc->id);
            DB::table('mp_documents')->where('id', $doc->id)->update(['alerted_on' => now()->toDateString()]);
            $n++;
        }
        foreach (DB::table('mp_bonds')->where('company_id', $c->id)->whereIn('status', ['issued', 'requested'])->whereNotNull('expires_on')->where('expires_on', '<=', now()->addDays(30)->toDateString())->whereNull('alerted_on')->get() as $b) {
            AppNotification::send($c->id, $to($b->owner_id), 'marches', (self::BOND_KINDS[$b->kind] ?? 'Caution').' à suivre',
                number_format((float) $b->amount, 0, ',', ' ').($b->bank ? ' ('.$b->bank.')' : '').' : échéance le '.date('d/m/Y', strtotime($b->expires_on)).' — mainlevée ou prorogation.', 'marches', (string) $b->id);
            DB::table('mp_bonds')->where('id', $b->id)->update(['alerted_on' => now()->toDateString()]);
            $n++;
        }
        foreach (DB::table('mp_contracts')->where('company_id', $c->id)->whereIn('status', ['signed', 'in_progress'])->whereNotNull('planned_end')->where('planned_end', '<=', now()->addDays(15)->toDateString())->get() as $k) {
            if ($k->alerted_on && self::days($k->alerted_on) > -7) {
                continue;
            }
            $d = self::days($k->planned_end);
            AppNotification::send($c->id, $to($k->owner_id), 'marches', $d < 0 ? 'Marché en retard' : 'Fin de marché proche',
                '« '.Str::limit($k->title, 90).' » : fin prévue le '.date('d/m/Y', strtotime($k->planned_end)).($d < 0 ? ' (retard de '.abs($d).' j).' : '.'), 'marches', (string) $k->id);
            DB::table('mp_contracts')->where('id', $k->id)->update(['alerted_on' => now()->toDateString()]);
            $n++;
        }

        return $n;
    }

    public function dashboard(Company $c): array
    {
        $t = DB::table('mp_tenders')->where('company_id', $c->id);
        $year = now()->startOfYear();
        $decided = (clone $t)->whereIn('status', ['won', 'lost'])->where('updated_at', '>=', $year);
        $won = (clone $decided)->where('status', 'won')->count();
        $all = (clone $decided)->count();
        $contracts = collect(DB::table('mp_contracts')->where('company_id', $c->id)->whereNotIn('status', ['closed', 'terminated'])->get())->map(fn ($x) => $this->present('contracts', (array) $x));
        $bonds = DB::table('mp_bonds')->where('company_id', $c->id)->whereIn('status', ['issued', 'requested']);

        return [
            'open' => (clone $t)->whereIn('status', self::OPEN)->count(),
            'submitted' => (clone $t)->where('status', 'submitted')->count(),
            'due_week' => (clone $t)->whereIn('status', self::OPEN)->whereBetween('submission_at', [now(), now()->addDays(7)->endOfDay()])->count(),
            'win_rate' => $all ? round($won / $all * 100) : null,
            'won_year' => $won,
            'won_amount' => (float) DB::table('mp_contracts')->where('company_id', $c->id)->where('signed_on', '>=', $year->toDateString())->sum('amount'),
            'contracts' => $contracts->count(),
            'backlog' => (float) $contracts->sum('remaining'),
            'late' => $contracts->filter(fn ($x) => str_starts_with((string) $x['alert'], 'En retard'))->count(),
            'retention' => (float) $contracts->sum('retention_held'),
            'bonds_amount' => (float) (clone $bonds)->sum('amount'),
            'bonds_count' => (clone $bonds)->count(),
            'docs_expiring' => DB::table('mp_documents')->where('company_id', $c->id)->whereNotNull('expires_on')->where('expires_on', '<=', now()->addDays(30)->toDateString())->count(),
            'unpaid' => (float) DB::table('mp_statements')->where('company_id', $c->id)->whereIn('status', ['submitted', 'approved'])->sum('net'),
            'upcoming' => (clone $t)->whereIn('status', self::OPEN)->whereNotNull('submission_at')->where('submission_at', '>=', now())->orderBy('submission_at')->limit(6)
                ->get(['id', 'title', 'buyer', 'submission_at', 'status'])->map(fn ($x) => (array) $x)->all(),
        ];
    }
}
