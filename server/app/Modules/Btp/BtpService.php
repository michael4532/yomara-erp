<?php

namespace App\Modules\Btp;

use App\Models\Company;
use App\Modules\Btp\Models\Item;
use App\Modules\Btp\Models\Log;
use App\Modules\Btp\Models\Site;
use App\Modules\Btp\Models\Situation;
use App\Modules\Core\Models\Partner;
use App\Modules\Projets\Models\Project;
use App\Modules\Ventes\Models\SalesDocument;
use App\Services\EntityWriter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * BTP : chantier (adossé à un projet pour les coûts), devis quantitatif par lots et ouvrages, marché, avance de démarrage,
 * situations de travaux à l'avancement (retenue de garantie, remboursement de l'avance), libération de la retenue, journal.
 */
class BtpService
{
    public const STATUSES = ['study' => 'À l\'étude', 'won' => 'Marché signé', 'in_progress' => 'En cours', 'received' => 'Réceptionné', 'closed' => 'Clôturé', 'lost' => 'Perdu'];

    public const UNITS = ['u' => 'unité', 'ens' => 'ensemble', 'ff' => 'forfait', 'm' => 'm', 'm2' => 'm²', 'm3' => 'm³', 'ml' => 'ml', 'kg' => 'kg', 't' => 't', 'h' => 'heure', 'j' => 'jour', 'pt' => 'point'];

    public const WEATHER = ['soleil' => 'Ensoleillé', 'nuageux' => 'Nuageux', 'pluie' => 'Pluie', 'orage' => 'Orage', 'harmattan' => 'Harmattan'];

    public function __construct(protected EntityWriter $writer) {}

    public static function money(float $v): string
    {
        return number_format($v, 0, ',', ' ').' F';
    }

    protected function invoice(string $type, Site $s, array $lines, string $notes, ?string $date = null): SalesDocument
    {
        $id = (string) Str::uuid();
        SalesDocument::$systemWrite = true;
        try {
            $res = $this->writer->upsert(SalesDocument::class, $id, [
                'type' => $type, 'partner_id' => $s->client_id, 'date' => $date ?? now()->toDateString(), 'status' => 'validated', 'reference' => $s->reference ?: $s->name,
                'project_id' => $s->project_id, 'notes' => $notes, 'lines' => array_map(fn ($l) => $l + ['id' => (string) Str::uuid(), 'discount_percent' => 0], $lines),
            ]);
        } finally {
            SalesDocument::$systemWrite = false;
        }
        if ($res['status'] !== EntityWriter::APPLIED) {
            throw new \DomainException('Document refusé : '.($res['message'] ?? 'erreur inconnue'));
        }

        return SalesDocument::find($id);
    }

    // ------------------------------------------------------------------ chantier

    public function saveSite(?Site $s, array $d): Site
    {
        $client = $d['client_id'] ?? null;
        if (! $client && filled($d['client_name'] ?? null)) {
            $client = Partner::create(['name' => Str::limit(trim($d['client_name']), 160, ''), 'type' => 'customer', 'phone' => $d['client_phone'] ?? null, 'is_company' => true])->id;
        }
        if ($client) {
            Partner::findOrFail($client);
        }

        return DB::transaction(function () use ($s, $d, $client) {
            $creating = ! $s;
            $s ??= new Site;
            $s->fill(array_intersect_key($d, array_flip(['name', 'reference', 'address', 'city', 'tax_rate', 'retention_rate', 'advance_rate', 'start_date', 'end_date', 'notes'])));
            if ($client) {
                $s->client_id = $client;
            }
            $pid = $s->project_id ?: (string) Str::uuid();
            $res = $this->writer->upsert(Project::class, $pid, array_filter([
                'name' => 'Chantier — '.$s->name, 'partner_id' => $s->client_id, 'status' => $creating ? 'planned' : null, 'billing_mode' => $creating ? 'fixed' : null,
                'start_date' => $s->start_date?->toDateString(), 'end_date' => $s->end_date?->toDateString(), 'description' => trim(($s->address ?? '').' '.($s->city ?? '')) ?: null,
            ], fn ($v) => $v !== null));
            if ($res['status'] !== EntityWriter::APPLIED) {
                throw new \DomainException('Projet du chantier refusé : '.($res['message'] ?? ''));
            }
            $s->project_id = $pid;
            $s->save();

            return $s;
        });
    }

    /** Devis quantitatif (bordereau des prix) : remplace toutes les lignes. */
    public function saveItems(Site $s, array $items): Site
    {
        if (in_array($s->status, ['in_progress', 'received', 'closed'], true) && Situation::where('site_id', $s->id)->exists()) {
            throw new \DomainException('Des situations ont été établies : le bordereau du marché ne peut plus être modifié (passez un avenant par de nouvelles lignes au chiffrage).');
        }
        DB::transaction(function () use ($s, $items) {
            $keep = [];
            foreach (array_values($items) as $i => $it) {
                $row = ['site_id' => $s->id, 'position' => $i, 'lot' => Str::limit(trim((string) ($it['lot'] ?? 'Travaux')), 120, '') ?: 'Travaux', 'code' => $it['code'] ?? null,
                    'designation' => Str::limit(trim((string) $it['designation']), 255, ''), 'unit' => $it['unit'] ?? 'u', 'quantity' => (float) ($it['quantity'] ?? 0),
                    'unit_cost' => (float) ($it['unit_cost'] ?? 0), 'unit_price' => (float) ($it['unit_price'] ?? 0)];
                $item = ! empty($it['id']) ? Item::where('site_id', $s->id)->find($it['id']) : null;
                $item ? $item->fill($row)->save() : $item = Item::create($row);
                $keep[] = $item->id;
            }
            Item::where('site_id', $s->id)->whereNotIn('id', $keep ?: [0])->delete();
            $this->refreshBudget($s);
        });

        return $s->fresh();
    }

    protected function refreshBudget(Site $s): void
    {
        $t = $this->totals($s);
        if (in_array($s->status, ['won', 'in_progress'], true)) {
            $s->forceFill(['contract_amount' => $t['price']])->save();
        }
        Project::whereKey($s->project_id)->update(['budget_amount' => $t['cost']]);
    }

    public function totals(Site $s): array
    {
        $items = Item::where('site_id', $s->id)->get();
        $price = round($items->sum(fn ($i) => round($i->quantity * $i->unit_price, 2)), 2);
        $cost = round($items->sum(fn ($i) => round($i->quantity * $i->unit_cost, 2)), 2);

        return ['price' => $price, 'cost' => $cost, 'margin' => $price - $cost, 'margin_rate' => $price > 0 ? round(($price - $cost) * 100 / $price, 1) : null,
            'coefficient' => $cost > 0 ? round($price / $cost, 3) : null, 'tax' => round($price * $s->tax_rate / 100, 2), 'ttc' => round($price * (1 + $s->tax_rate / 100), 2)];
    }

    /** Devis client établi depuis le bordereau. */
    public function quote(Site $s): SalesDocument
    {
        $items = Item::where('site_id', $s->id)->orderBy('position')->get();
        if ($items->isEmpty() || ! $s->client_id) {
            throw new \DomainException('Renseignez le client et chiffrez au moins un ouvrage avant d\'établir le devis.');
        }
        $q = $this->invoice('quote', $s, $items->map(fn ($i) => ['description' => Str::limit($i->lot.' — '.($i->code ? $i->code.' ' : '').$i->designation, 255, ''),
            'quantity' => $i->quantity, 'unit' => self::UNITS[$i->unit] ?? $i->unit, 'unit_price' => $i->unit_price, 'tax_rate' => $s->tax_rate])->all(),
            'Devis — chantier '.$s->name.($s->address ? ' · '.$s->address : ''));
        $s->forceFill(['quote_id' => $q->id])->save();

        return $q;
    }

    /** Marché signé : le montant du marché est figé sur le bordereau, le chantier démarre. */
    public function accept(Site $s, array $d = []): Site
    {
        if (! in_array($s->status, ['study', 'lost'], true)) {
            throw new \DomainException('Ce marché est déjà signé.');
        }
        $t = $this->totals($s);
        if ($t['price'] <= 0) {
            throw new \DomainException('Le bordereau est vide.');
        }
        $s->forceFill(['status' => 'in_progress', 'contract_amount' => $t['price'], 'reference' => $d['reference'] ?? $s->reference, 'start_date' => $d['start_date'] ?? $s->start_date ?? now()->toDateString()])->save();
        Project::whereKey($s->project_id)->update(['status' => 'active', 'budget_amount' => $t['cost'], 'start_date' => $s->start_date->toDateString()]);

        return $s;
    }

    public function lost(Site $s): Site
    {
        if ($s->status !== 'study') {
            throw new \DomainException('Seul un chantier à l\'étude peut être marqué perdu.');
        }
        $s->forceFill(['status' => 'lost'])->save();
        Project::whereKey($s->project_id)->update(['status' => 'cancelled']);

        return $s;
    }

    public function advance(Site $s): SalesDocument
    {
        if (! in_array($s->status, ['in_progress', 'won'], true)) {
            throw new \DomainException('Signez d\'abord le marché.');
        }
        if ($s->advance_invoice_id) {
            throw new \DomainException('L\'avance de démarrage est déjà facturée.');
        }
        if ($s->advance_rate <= 0) {
            throw new \DomainException('Aucune avance de démarrage prévue au marché.');
        }
        $amount = round($s->contract_amount * $s->advance_rate / 100);
        $inv = $this->invoice('invoice', $s, [['description' => 'Avance de démarrage ('.rtrim(rtrim(number_format($s->advance_rate, 2, ',', ''), '0'), ',').' % du marché '.self::money($s->contract_amount).' HT)',
            'quantity' => 1, 'unit_price' => $amount, 'tax_rate' => $s->tax_rate]], 'Avance de démarrage — chantier '.$s->name);
        $s->forceFill(['advance_invoice_id' => $inv->id])->save();

        return $inv;
    }

    public function advanceAmount(Site $s): float
    {
        return $s->advance_invoice_id ? round($s->contract_amount * $s->advance_rate / 100) : 0.0;
    }

    // ------------------------------------------------------------------ situations de travaux

    /**
     * Calcul d'une situation : quantités cumulées exécutées par ouvrage → montant cumulé, montant de la période,
     * remboursement de l'avance au prorata, TVA, retenue de garantie sur le TTC, net à payer.
     */
    public function computeSituation(Site $s, array $progress): array
    {
        $items = Item::where('site_id', $s->id)->orderBy('position')->get();
        $prev = Situation::where('site_id', $s->id)->orderByDesc('number')->first();
        $prevProgress = $prev?->progress ?? [];
        $rows = [];
        $cumul = 0.0;
        foreach ($items as $i) {
            $q = array_key_exists($i->id, $progress) ? (float) $progress[$i->id] : (float) ($prevProgress[$i->id] ?? 0);
            $q = max(0, min($i->quantity, round($q, 3)));
            $amount = round($q * $i->unit_price, 2);
            $cumul += $amount;
            $before = (float) ($prevProgress[$i->id] ?? 0);
            $rows[] = ['item_id' => $i->id, 'lot' => $i->lot, 'code' => $i->code, 'designation' => $i->designation, 'unit' => $i->unit, 'quantity' => $i->quantity, 'unit_price' => $i->unit_price,
                'previous' => $before, 'cumul' => $q, 'pct' => $i->quantity > 0 ? round($q * 100 / $i->quantity, 1) : 0, 'amount' => $amount, 'period' => round(($q - $before) * $i->unit_price, 2)];
        }
        $cumul = round($cumul, 2);
        $previous = (float) ($prev?->works_cumul ?? 0);
        $works = round($cumul - $previous, 2);
        $advance = $this->advanceAmount($s);
        $repaidBefore = (float) Situation::where('site_id', $s->id)->sum('advance_repaid');
        $repaid = $advance > 0 && $s->contract_amount > 0 ? min(max(0, $advance - $repaidBefore), round($advance * $works / $s->contract_amount)) : 0.0;
        $base = round($works - $repaid, 2);
        $tax = round($base * $s->tax_rate / 100, 2);
        $retention = round(($base + $tax) * $s->retention_rate / 100);

        return ['rows' => $rows, 'number' => ($prev?->number ?? 0) + 1, 'works_cumul' => $cumul, 'works_previous' => $previous, 'works_amount' => $works, 'advance_repaid' => $repaid,
            'base' => $base, 'tax' => $tax, 'ttc' => $base + $tax, 'retention' => $retention, 'net' => round($base + $tax - $retention, 2),
            'progress_pct' => $s->contract_amount > 0 ? round($cumul * 100 / $s->contract_amount, 1) : 0, 'progress' => collect($rows)->mapWithKeys(fn ($r) => [$r['item_id'] => $r['cumul']])->all()];
    }

    public function createSituation(Site $s, array $progress, ?string $date = null, ?string $notes = null): Situation
    {
        if (! in_array($s->status, ['in_progress', 'won'], true)) {
            throw new \DomainException('Les situations se font sur un chantier en cours.');
        }
        $c = $this->computeSituation($s, $progress);
        if ($c['works_amount'] <= 0) {
            throw new \DomainException('Aucun avancement depuis la situation précédente.');
        }
        $date ??= now()->toDateString();

        return DB::transaction(function () use ($s, $c, $date, $notes) {
            $lines = [['description' => 'Situation n° '.$c['number'].' — travaux exécutés au '.Carbon::parse($date)->format('d/m/Y').' (cumul '.self::money($c['works_cumul']).', '.number_format($c['progress_pct'], 1, ',', '').' % du marché ; déjà facturé '.self::money($c['works_previous']).')',
                'quantity' => 1, 'unit_price' => $c['works_amount'], 'tax_rate' => $s->tax_rate]];
            if ($c['advance_repaid'] > 0) {
                $lines[] = ['description' => 'Remboursement de l\'avance de démarrage', 'quantity' => -1, 'unit_price' => $c['advance_repaid'], 'tax_rate' => $s->tax_rate];
            }
            if ($c['retention'] > 0) {
                $lines[] = ['description' => 'Retenue de garantie ('.rtrim(rtrim(number_format($s->retention_rate, 2, ',', ''), '0'), ',').' % du TTC)', 'quantity' => -1, 'unit_price' => $c['retention'], 'tax_rate' => 0];
            }
            $inv = $this->invoice('invoice', $s, $lines, 'Situation de travaux n° '.$c['number'].' — chantier '.$s->name, $date);

            return Situation::create(['site_id' => $s->id, 'number' => $c['number'], 'date' => $date, 'progress' => $c['progress'], 'works_cumul' => $c['works_cumul'],
                'works_previous' => $c['works_previous'], 'works_amount' => $c['works_amount'], 'advance_repaid' => $c['advance_repaid'], 'retention' => $c['retention'],
                'tax' => $c['tax'], 'net' => (float) $inv->total, 'invoice_id' => $inv->id, 'notes' => $notes]);
        });
    }

    /** Réception des travaux (le délai de garantie commence). */
    public function receive(Site $s, ?string $date = null): Site
    {
        if (! in_array($s->status, ['in_progress', 'won'], true)) {
            throw new \DomainException('Ce chantier n\'est pas en cours.');
        }
        $s->forceFill(['status' => 'received', 'reception_date' => $date ?? now()->toDateString()])->save();
        Project::whereKey($s->project_id)->update(['status' => 'done', 'end_date' => $s->reception_date->toDateString()]);

        return $s;
    }

    /** Fin du délai de garantie : la retenue est facturée au client pour être libérée. */
    public function releaseRetention(Site $s): SalesDocument
    {
        if ($s->status !== 'received') {
            throw new \DomainException('La retenue se libère après la réception des travaux.');
        }
        if ($s->release_invoice_id) {
            throw new \DomainException('La retenue de garantie est déjà libérée.');
        }
        $held = (float) Situation::where('site_id', $s->id)->sum('retention');
        if ($held <= 0) {
            throw new \DomainException('Aucune retenue de garantie sur ce chantier.');
        }
        $inv = $this->invoice('invoice', $s, [['description' => 'Libération de la retenue de garantie (réception du '.$s->reception_date->format('d/m/Y').')', 'quantity' => 1, 'unit_price' => $held, 'tax_rate' => 0]],
            'Libération de la retenue de garantie — chantier '.$s->name);
        $s->forceFill(['release_invoice_id' => $inv->id, 'status' => 'closed'])->save();

        return $inv;
    }

    // ------------------------------------------------------------------ synthèse

    public function summary(Site $s, bool $detail = false): array
    {
        $t = $this->totals($s);
        $sits = Situation::where('site_id', $s->id)->orderBy('number')->get();
        $last = $sits->last();
        $invoiceIds = array_values(array_filter(array_merge([$s->advance_invoice_id, $s->release_invoice_id], $sits->pluck('invoice_id')->all())));
        $docs = $invoiceIds ? DB::table('trade_documents')->whereIn('id', $invoiceIds)->get(['id', 'number', 'total', 'amount_paid', 'status'])->keyBy('id') : collect();
        $invoiced = (float) $docs->where('status', '!=', 'cancelled')->sum('total');
        $paid = (float) $docs->sum('amount_paid');
        $project = Project::find($s->project_id);
        $costs = $project && app('tenant')->hasModule('projets') ? app(\App\Modules\Projets\ProjectCostService::class)->compute($project) : null;
        $actual = (float) ($costs['actual_cost'] ?? 0);
        $works = (float) ($last?->works_cumul ?? 0);
        $progress = $s->contract_amount > 0 ? round($works * 100 / $s->contract_amount, 1) : 0;
        $consumed = $t['cost'] > 0 ? round($actual * 100 / $t['cost'], 1) : null;
        $client = $s->client_id ? DB::table('partners')->where('id', $s->client_id)->first(['id', 'name', 'phone']) : null;
        $held = (float) $sits->sum('retention');
        $out = $s->toArray() + [
            'status_label' => self::STATUSES[$s->status] ?? $s->status, 'client' => $client ? (array) $client : null,
            'estimate' => $t, 'situations_count' => $sits->count(), 'works_cumul' => $works, 'progress' => $progress, 'remaining' => max(0, $s->contract_amount - $works),
            'invoiced' => $invoiced, 'paid' => $paid, 'receivable' => round($invoiced - $paid, 2),
            'advance_amount' => $this->advanceAmount($s), 'advance_repaid' => (float) $sits->sum('advance_repaid'),
            'retention_held' => $s->release_invoice_id ? 0.0 : $held, 'retention_total' => $held,
            'actual_cost' => $actual, 'committed_cost' => (float) ($costs['committed_cost'] ?? 0), 'cost_consumed' => $consumed,
            // Alerte : les coûts consomment le budget plus vite que les travaux n'avancent
            'drift' => $consumed !== null && $progress > 0 ? round($consumed - $progress, 1) : null,
            'margin_to_date' => round($works - $actual, 2), 'margin_forecast' => round(($s->contract_amount ?: $t['price']) - max($t['cost'], $progress > 0 ? $actual * 100 / $progress : 0), 2),
            'last_log' => Log::where('site_id', $s->id)->max('date'),
        ];
        if ($detail) {
            $out['items'] = Item::where('site_id', $s->id)->orderBy('position')->get();
            $out['situations'] = $sits->map(fn ($x) => $x->toArray() + ['invoice' => $x->invoice_id && isset($docs[$x->invoice_id]) ? (array) $docs[$x->invoice_id] : null])->values();
            $out['next'] = in_array($s->status, ['in_progress', 'won'], true) ? $this->computeSituation($s, []) : null;
            $out['logs'] = Log::where('site_id', $s->id)->orderByDesc('date')->orderByDesc('id')->limit(60)->get()
                ->map(fn ($l) => $l->toArray() + ['user' => $l->user_id ? DB::table('users')->where('id', $l->user_id)->value('name') : null])->values();
            $out['costs'] = $costs;
            $out['documents'] = array_values(array_filter([
                $s->quote_id ? ['label' => 'Devis', 'doc' => DB::table('trade_documents')->where('id', $s->quote_id)->first(['id', 'number', 'total', 'status'])] : null,
                $s->advance_invoice_id ? ['label' => 'Avance de démarrage', 'doc' => $docs[$s->advance_invoice_id] ?? null] : null,
                $s->release_invoice_id ? ['label' => 'Libération de la retenue', 'doc' => $docs[$s->release_invoice_id] ?? null] : null,
            ]));
        }

        return $out;
    }

    public function dashboard(Company $c): array
    {
        $sites = Site::orderByDesc('id')->get()->map(fn ($s) => $this->summary($s));
        $active = $sites->whereIn('status', ['won', 'in_progress']);

        return [
            'sites' => $sites->values()->all(),
            'active' => $active->count(), 'backlog' => (float) $active->sum('remaining'), 'contracts' => (float) $active->sum('contract_amount'),
            'invoiced' => (float) $sites->sum('invoiced'), 'receivable' => (float) $sites->sum('receivable'), 'retention_held' => (float) $sites->sum('retention_held'),
            'pipeline' => (float) $sites->where('status', 'study')->sum(fn ($s) => $s['estimate']['price']), 'studies' => $sites->where('status', 'study')->count(),
            'drifting' => $active->filter(fn ($s) => ($s['drift'] ?? 0) > 10)->count(),
            'won_rate' => ($w = $sites->whereNotIn('status', ['study'])->count()) ? round($sites->whereNotIn('status', ['study', 'lost'])->count() * 100 / $w, 1) : null,
        ];
    }

    // ------------------------------------------------------------------ PDF

    protected function pdf(): \TCPDF
    {
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->AddPage();

        return $pdf;
    }

    protected function head(Company $c, Site $s, string $title, string $sub): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $client = $s->client_id ? Partner::find($s->client_id) : null;

        return '<table cellpadding="3"><tr><td width="55%"><b style="font-size:12pt">'.$e($c->name).'</b><br>'.$e($c->address).($c->phone ? '<br>Tél. '.$e($c->phone) : '').'</td>'
            .'<td width="45%" align="right"><b style="font-size:12pt;color:#1f6feb">'.$e($title).'</b><br>'.$sub.'</td></tr></table><br>'
            .'<table cellpadding="4" border="0.3"><tr><td width="50%" bgcolor="#f3f6fb"><b>Maître d\'ouvrage</b><br>'.$e($client?->name).'</td>'
            .'<td width="50%" bgcolor="#f3f6fb"><b>Chantier</b><br>'.$e($s->name).($s->address ? '<br>'.$e($s->address.' '.$s->city) : '').($s->reference ? '<br>Marché n° '.$e($s->reference) : '').'</td></tr></table><br>';
    }

    public function situationPdf(Company $c, Site $s, Situation $x): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $m = fn ($v) => number_format((float) $v, 0, ',', ' ');
        $q = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', ' '), '0'), ',');
        $prev = Situation::where('site_id', $s->id)->where('number', $x->number - 1)->first();
        $items = Item::where('site_id', $s->id)->orderBy('position')->get();
        $pdf = $this->pdf();
        $inv = DB::table('trade_documents')->where('id', $x->invoice_id)->value('number');
        $html = $this->head($c, $s, 'SITUATION DE TRAVAUX N° '.$x->number, 'Arrêtée au '.$x->date->format('d/m/Y').($inv ? '<br>Facture '.$e($inv) : ''));
        $html .= '<table cellpadding="2.5" border="0.3"><tr bgcolor="#1f2a44" color="#ffffff" style="font-weight:bold"><td width="34%">Désignation</td><td width="5%">U</td><td width="9%" align="right">Qté marché</td>'
            .'<td width="10%" align="right">P.U. HT</td><td width="9%" align="right">Qté préc.</td><td width="9%" align="right">Qté cumul</td><td width="7%" align="right">%</td><td width="17%" align="right">Montant cumulé HT</td></tr>';
        foreach ($items->groupBy('lot') as $lot => $rows) {
            $html .= '<tr bgcolor="#eef2f8"><td colspan="8"><b>'.$e($lot).'</b></td></tr>';
            $lotTotal = 0;
            foreach ($rows as $i) {
                $cum = (float) ($x->progress[$i->id] ?? 0);
                $pre = (float) ($prev?->progress[$i->id] ?? 0);
                $amt = round($cum * $i->unit_price, 2);
                $lotTotal += $amt;
                $html .= '<tr><td>'.$e(($i->code ? $i->code.' ' : '').$i->designation).'</td><td>'.$e(self::UNITS[$i->unit] ?? $i->unit).'</td><td align="right">'.$q($i->quantity).'</td><td align="right">'.$m($i->unit_price).'</td>'
                    .'<td align="right">'.$q($pre).'</td><td align="right">'.$q($cum).'</td><td align="right">'.($i->quantity > 0 ? number_format($cum * 100 / $i->quantity, 0, ',', '') : '0').'</td><td align="right">'.$m($amt).'</td></tr>';
            }
            $html .= '<tr><td colspan="7" align="right"><i>Sous-total '.$e($lot).'</i></td><td align="right"><i>'.$m($lotTotal).'</i></td></tr>';
        }
        $html .= '</table><br><table cellpadding="3" border="0.3" width="60%" align="right">'
            .'<tr><td width="65%">Montant du marché HT</td><td width="35%" align="right">'.$m($s->contract_amount).'</td></tr>'
            .'<tr><td>Travaux exécutés cumulés HT ('.number_format($s->contract_amount > 0 ? $x->works_cumul * 100 / $s->contract_amount : 0, 1, ',', '').' %)</td><td align="right">'.$m($x->works_cumul).'</td></tr>'
            .'<tr><td>À déduire : situations précédentes</td><td align="right">− '.$m($x->works_previous).'</td></tr>'
            .'<tr style="font-weight:bold"><td>Travaux de la période HT</td><td align="right">'.$m($x->works_amount).'</td></tr>'
            .($x->advance_repaid > 0 ? '<tr><td>Remboursement de l\'avance de démarrage</td><td align="right">− '.$m($x->advance_repaid).'</td></tr>' : '')
            .'<tr><td>TVA '.$e(rtrim(rtrim(number_format($s->tax_rate, 2, ',', ''), '0'), ',')).' %</td><td align="right">'.$m($x->tax).'</td></tr>'
            .($x->retention > 0 ? '<tr><td>Retenue de garantie '.$e(rtrim(rtrim(number_format($s->retention_rate, 2, ',', ''), '0'), ',')).' %</td><td align="right">− '.$m($x->retention).'</td></tr>' : '')
            .'<tr bgcolor="#eef2f8" style="font-weight:bold"><td>NET À PAYER TTC</td><td align="right">'.$m($x->net).' F</td></tr></table>'
            .'<br><br><table cellpadding="4"><tr><td width="50%">L\'entreprise<br><br><br></td><td width="50%">Le maître d\'ouvrage (bon pour accord)<br><br><br></td></tr></table>';
        $pdf->writeHTML($html);

        return $pdf->Output('', 'S');
    }

    public function estimatePdf(Company $c, Site $s, bool $internal = false): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $m = fn ($v) => number_format((float) $v, 0, ',', ' ');
        $q = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', ' '), '0'), ',');
        $t = $this->totals($s);
        $items = Item::where('site_id', $s->id)->orderBy('position')->get();
        $pdf = $this->pdf();
        $html = $this->head($c, $s, $internal ? 'ÉTUDE DE PRIX (INTERNE)' : 'DEVIS QUANTITATIF ET ESTIMATIF', 'Le '.now()->format('d/m/Y'));
        $cols = $internal
            ? [['Désignation', '36%'], ['U', '5%'], ['Qté', '8%', 1], ['Déboursé U.', '11%', 1], ['Déboursé', '13%', 1], ['P.U. HT', '11%', 1], ['Montant HT', '16%', 1]]
            : [['Désignation', '50%'], ['U', '6%'], ['Qté', '10%', 1], ['P.U. HT', '14%', 1], ['Montant HT', '20%', 1]];
        $html .= '<table cellpadding="2.5" border="0.3"><tr bgcolor="#1f2a44" color="#ffffff" style="font-weight:bold">'.implode('', array_map(fn ($c) => '<td width="'.$c[1].'"'.(! empty($c[2]) ? ' align="right"' : '').'>'.$c[0].'</td>', $cols)).'</tr>';
        foreach ($items->groupBy('lot') as $lot => $rows) {
            $html .= '<tr bgcolor="#eef2f8"><td colspan="'.count($cols).'"><b>'.$e($lot).'</b></td></tr>';
            foreach ($rows as $i) {
                $cells = $internal
                    ? [$e(($i->code ? $i->code.' ' : '').$i->designation), $e(self::UNITS[$i->unit] ?? $i->unit), $q($i->quantity), $m($i->unit_cost), $m($i->quantity * $i->unit_cost), $m($i->unit_price), $m($i->quantity * $i->unit_price)]
                    : [$e(($i->code ? $i->code.' ' : '').$i->designation), $e(self::UNITS[$i->unit] ?? $i->unit), $q($i->quantity), $m($i->unit_price), $m($i->quantity * $i->unit_price)];
                $html .= '<tr>'.implode('', array_map(fn ($v, $c) => '<td width="'.$c[1].'"'.(! empty($c[2]) ? ' align="right"' : '').'>'.$v.'</td>', $cells, $cols)).'</tr>';
            }
            $sub = $rows->sum(fn ($i) => $i->quantity * $i->unit_price);
            $html .= '<tr><td colspan="'.(count($cols) - 1).'" align="right"><i>Sous-total '.$e($lot).'</i></td><td align="right"><i>'.$m($sub).'</i></td></tr>';
        }
        $html .= '</table><br><table cellpadding="3" border="0.3" width="55%" align="right"><tr><td width="60%">Total HT</td><td width="40%" align="right">'.$m($t['price']).'</td></tr>'
            .'<tr><td>TVA '.$e(rtrim(rtrim(number_format($s->tax_rate, 2, ',', ''), '0'), ',')).' %</td><td align="right">'.$m($t['tax']).'</td></tr>'
            .'<tr bgcolor="#eef2f8" style="font-weight:bold"><td>Total TTC</td><td align="right">'.$m($t['ttc']).' F</td></tr>'
            .($internal ? '<tr><td>Déboursé sec</td><td align="right">'.$m($t['cost']).'</td></tr><tr><td>Marge brute ('.$e($t['margin_rate']).' %) · coefficient '.$e($t['coefficient']).'</td><td align="right">'.$m($t['margin']).'</td></tr>' : '')
            .'</table>'.(! $internal && ($s->advance_rate > 0 || $s->retention_rate > 0) ? '<p>Conditions : '.($s->advance_rate > 0 ? 'avance de démarrage de '.$e($s->advance_rate).' % ; ' : '').'paiement sur situations mensuelles de travaux'.($s->retention_rate > 0 ? ' ; retenue de garantie de '.$e($s->retention_rate).' %, libérée à la fin du délai de garantie' : '').'.</p>' : '');
        $pdf->writeHTML($html);

        return $pdf->Output('', 'S');
    }
}
