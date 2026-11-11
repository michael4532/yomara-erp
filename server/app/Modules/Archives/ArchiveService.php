<?php

namespace App\Modules\Archives;

use App\Models\Company;
use App\Models\Sequence;
use App\Modules\Core\Models\TradeDocument;
use App\Storage\StoragePool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Archivage : versement, cotation, scellement (empreinte SHA-256), conservation, prêts et éliminations.
 * Une archive ne se modifie pas : seules ses métadonnées de gestion (emplacement, mots-clés…) évoluent,
 * et tout est tracé dans le journal.
 */
class ArchiveService
{
    public const FINAL_ACTIONS = ['destroy' => 'Élimination', 'keep' => 'Conservation définitive', 'review' => 'Tri (à examiner)'];

    public const LEVELS = ['public' => 'Public', 'internal' => 'Interne', 'confidential' => 'Confidentiel', 'secret' => 'Secret'];

    /** Types de documents de l'ERP archivables automatiquement. */
    public const SOURCES = [
        'sale.invoice' => 'Factures clients',
        'sale.deposit' => 'Factures d\'acompte',
        'sale.credit_note' => 'Avoirs clients',
        'sale.receipt' => 'Tickets de caisse',
        'sale.order' => 'Commandes clients',
        'sale.quote' => 'Devis',
        'purchase.supplier_invoice' => 'Factures fournisseurs',
        'purchase.purchase_order' => 'Bons de commande fournisseurs',
    ];

    /**
     * Plan de classement de départ (Acte uniforme OHADA : pièces comptables 10 ans). À adapter avec votre conseil.
     * [code, nom, années (0 = définitif), sort final, confidentialité, sources, enfants]
     */
    protected const DEFAULT_PLAN = [
        ['1', 'Comptabilité et finances', 10, 'destroy', 'internal', [], [
            ['1.1', 'Factures clients et acomptes', 10, 'destroy', 'internal', ['sale.invoice', 'sale.deposit']],
            ['1.2', 'Avoirs clients', 10, 'destroy', 'internal', ['sale.credit_note']],
            ['1.3', 'Factures fournisseurs', 10, 'destroy', 'internal', ['purchase.supplier_invoice']],
            ['1.4', 'Relevés et pièces bancaires', 10, 'destroy', 'confidential', []],
            ['1.5', 'États financiers, grand livre, déclarations fiscales', 0, 'keep', 'confidential', []],
        ]],
        ['2', 'Ressources humaines', 5, 'review', 'confidential', [], [
            ['2.1', 'Dossiers du personnel', 5, 'review', 'confidential', []],
            ['2.2', 'Bulletins de paie et déclarations sociales', 10, 'destroy', 'confidential', []],
            ['2.3', 'Contrats de travail', 5, 'review', 'confidential', []],
        ]],
        ['3', 'Juridique', 0, 'keep', 'confidential', [], [
            ['3.1', 'Statuts, procès-verbaux, registres', 0, 'keep', 'confidential', []],
            ['3.2', 'Contrats et conventions', 5, 'review', 'internal', []],
            ['3.3', 'Contentieux', 10, 'review', 'secret', []],
        ]],
        ['4', 'Commercial', 3, 'destroy', 'internal', [], [
            ['4.1', 'Devis, offres et commandes', 3, 'destroy', 'internal', []],
            ['4.2', 'Marchés publics et appels d\'offres', 10, 'review', 'internal', []],
        ]],
        ['5', 'Administration générale et courrier', 3, 'review', 'internal', [], []],
    ];

    public function __construct(protected StoragePool $pool) {}

    // ---------- Plan de classement ----------

    public function ensurePlan(Company $c): void
    {
        if (DB::table('ar_series')->where('company_id', $c->id)->exists()) {
            return;
        }
        $sort = 0;
        $add = function (array $s, ?int $parent) use ($c, &$sort, &$add) {
            $id = DB::table('ar_series')->insertGetId([
                'company_id' => $c->id, 'parent_id' => $parent, 'code' => $s[0], 'name' => $s[1], 'retention_years' => $s[2], 'final_action' => $s[3],
                'confidentiality' => $s[4], 'sources' => json_encode($s[5]), 'sort' => $sort++, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($s[6] ?? [] as $child) {
                $add($child, $id);
            }
        };
        foreach (self::DEFAULT_PLAN as $s) {
            $add($s, null);
        }
    }

    public function series(Company $c): array
    {
        $this->ensurePlan($c);
        $counts = DB::table('ar_records')->where('company_id', $c->id)->where('status', '!=', 'disposed')->groupBy('series_id')->selectRaw('series_id, COUNT(*) n, SUM(size) bytes')->get()->keyBy('series_id');

        return DB::table('ar_series')->where('company_id', $c->id)->orderBy('sort')->orderBy('code')->get()->map(fn ($s) => [
            'id' => $s->id, 'parent_id' => $s->parent_id, 'code' => $s->code, 'name' => $s->name, 'description' => $s->description,
            'retention_years' => (int) $s->retention_years, 'final_action' => $s->final_action, 'confidentiality' => $s->confidentiality,
            'sources' => json_decode((string) $s->sources, true) ?: [], 'count' => (int) ($counts[$s->id]->n ?? 0), 'bytes' => (int) ($counts[$s->id]->bytes ?? 0),
        ])->all();
    }

    // ---------- Versement ----------

    /** Date de fin de conservation : date du document + durée ; null = conservation définitive. */
    public function retentionUntil(?string $date, int $years): ?string
    {
        return $years > 0 ? Carbon::parse($date ?: now())->addYears($years)->toDateString() : null;
    }

    public function cote(Company $c, ?object $series, ?string $date): string
    {
        $year = (int) Carbon::parse($date ?: now())->format('Y');
        $prefix = $series ? 'AR'.str_replace('.', '', $series->code) : 'AR';

        return Sequence::next($c->id, 'archives.'.($series->id ?? 0), $prefix, $year);
    }

    /**
     * Crée une archive (fichier versé ou instantané de document) et la scelle.
     *
     * @param  array{title: string, series_id?: ?int, description?: ?string, doc_date?: ?string, location?: ?string, keywords?: ?string,
     *         confidentiality?: ?string, department?: ?string, transfer_ref?: ?string, source_type?: ?string, source_id?: ?string}  $meta
     */
    public function create(Company $c, array $meta, ?string $bytes = null, ?string $fileName = null, ?string $mime = null, ?string $content = null, ?int $userId = null): string
    {
        $series = isset($meta['series_id']) ? DB::table('ar_series')->where('company_id', $c->id)->where('id', $meta['series_id'])->first() : null;
        $id = (string) Str::uuid();
        $path = $backend = null;
        if ($bytes !== null) {
            $ext = strtolower(pathinfo((string) $fileName, PATHINFO_EXTENSION)) ?: 'bin';
            $path = 'archives/'.$c->id.'/'.$id.'.'.preg_replace('/[^a-z0-9]/', '', $ext);
            $backend = $this->pool->put($path, $bytes, $mime ?: 'application/octet-stream');
        }
        $hashed = $bytes ?? $content;
        DB::table('ar_records')->insert([
            'id' => $id, 'company_id' => $c->id, 'series_id' => $series?->id,
            'cote' => $this->cote($c, $series, $meta['doc_date'] ?? null),
            'title' => Str::limit($meta['title'], 250, ''), 'description' => $meta['description'] ?? null, 'doc_date' => $meta['doc_date'] ?? now()->toDateString(),
            'source_type' => $meta['source_type'] ?? null, 'source_id' => $meta['source_id'] ?? null, 'content' => $content,
            'file_name' => $fileName ? Str::limit($fileName, 250, '') : null, 'file_path' => $path, 'backend_id' => $backend, 'mime' => $mime,
            'size' => strlen((string) $hashed), 'sha256' => $hashed !== null ? hash('sha256', $hashed) : null, 'sealed_at' => $hashed !== null ? now() : null,
            'retention_until' => $this->retentionUntil($meta['doc_date'] ?? null, (int) ($series->retention_years ?? 10)),
            'final_action' => $series->final_action ?? 'review',
            'confidentiality' => $meta['confidentiality'] ?? ($series->confidentiality ?? 'internal'),
            'location' => $meta['location'] ?? null, 'keywords' => $meta['keywords'] ?? null, 'department' => $meta['department'] ?? null,
            'transfer_ref' => $meta['transfer_ref'] ?? null, 'status' => 'active', 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->log($c, $id, 'archived', $series ? 'Série '.$series->code.' '.$series->name : null, $userId);

        return $id;
    }

    /** Facture (ou autre document) validée : instantané JSON scellé dans la série qui l'archive automatiquement. */
    public function onValidated(TradeDocument $doc): ?string
    {
        $c = Company::find($doc->company_id);
        if (! $c || ! $c->hasModule('archives')) {
            return null;
        }
        $this->ensurePlan($c);
        $key = $doc->direction.'.'.$doc->type;
        $series = DB::table('ar_series')->where('company_id', $c->id)->get()->first(fn ($s) => in_array($key, json_decode((string) $s->sources, true) ?: [], true));
        if (! $series || DB::table('ar_records')->where('company_id', $c->id)->where('source_id', $doc->id)->exists()) {
            return null;
        }
        $doc->loadMissing('lines');
        $snapshot = json_encode([
            'type' => $doc->type, 'direction' => $doc->direction, 'number' => $doc->number, 'date' => $doc->date?->toDateString(), 'due_date' => $doc->due_date?->toDateString(),
            'partner_id' => $doc->partner_id, 'partner_name' => $doc->partner_name, 'currency' => $doc->currency, 'exchange_rate' => $doc->exchange_rate,
            'subtotal' => $doc->subtotal, 'tax_total' => $doc->tax_total, 'total' => $doc->total, 'reference' => $doc->reference, 'notes' => $doc->notes,
            'lines' => $doc->lines->map(fn ($l) => ['description' => $l->description, 'quantity' => $l->quantity, 'unit' => $l->unit, 'unit_price' => $l->unit_price,
                'discount_percent' => $l->discount_percent, 'tax_rate' => $l->tax_rate, 'subtotal' => $l->subtotal, 'tax_amount' => $l->tax_amount, 'total' => $l->total])->all(),
            'validated_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->create($c, [
            'series_id' => $series->id,
            'title' => (self::SOURCES[$key] ?? 'Document').' '.$doc->number.($doc->partner_name ? ' — '.$doc->partner_name : ''),
            'doc_date' => $doc->date?->toDateString(), 'source_type' => $key, 'source_id' => $doc->id,
            'keywords' => trim($doc->number.' '.$doc->partner_name.' '.$doc->reference),
        ], null, null, 'application/json', $snapshot, auth()->id());
    }

    // ---------- Accès ----------

    /** Contenu archivé (fichier ou instantané). */
    public function bytes(object $r): ?string
    {
        if ($r->file_path) {
            return $this->pool->get($r->backend_id, $r->file_path);
        }

        return $r->content;
    }

    /** Contrôle d'intégrité : l'empreinte recalculée doit être celle du scellement. */
    public function verify(Company $c, object $r, ?int $userId = null): array
    {
        try {
            $data = $this->bytes($r);
            $ok = $data !== null && $r->sha256 !== null && hash_equals($r->sha256, hash('sha256', $data));
            $msg = $ok ? 'Intègre : empreinte conforme au scellement.' : ($data === null ? 'Aucun contenu archivé.' : 'ALTÉRÉ : l\'empreinte ne correspond plus au scellement.');
        } catch (\Throwable $e) {
            $ok = false;
            $msg = 'Contenu illisible sur le stockage : '.$e->getMessage();
        }
        $this->log($c, $r->id, 'verified', $msg, $userId);

        return ['ok' => $ok, 'message' => $msg, 'sha256' => $r->sha256];
    }

    public function log(Company $c, ?string $recordId, string $action, ?string $details = null, ?int $userId = null): void
    {
        DB::table('ar_events')->insert([
            'company_id' => $c->id, 'record_id' => $recordId, 'user_id' => $userId ?? auth()->id(), 'action' => $action,
            'details' => $details ? Str::limit($details, 490, '') : null, 'ip' => request()?->ip(), 'created_at' => now(),
        ]);
    }

    // ---------- Éliminations ----------

    /** Archives arrivées au terme de leur conservation, à éliminer (hors gel juridique et prêts en cours). */
    public function eligibleForDisposal(Company $c)
    {
        return DB::table('ar_records')->where('company_id', $c->id)->where('status', 'active')->where('final_action', 'destroy')
            ->where('legal_hold', false)->whereNotNull('retention_until')->where('retention_until', '<', now()->toDateString())
            ->orderBy('retention_until')->get(['id', 'cote', 'title', 'doc_date', 'size', 'retention_until']);
    }

    public function propose(Company $c, array $ids, ?string $notes, int $userId): int
    {
        $eligible = $this->eligibleForDisposal($c)->keyBy('id');
        $rows = collect($ids)->map(fn ($id) => $eligible->get($id))->filter()->values();
        abort_if($rows->isEmpty(), 422, 'Aucune archive éligible à l\'élimination dans la sélection.');

        return DB::transaction(function () use ($c, $rows, $notes, $userId) {
            $id = DB::table('ar_disposals')->insertGetId([
                'company_id' => $c->id, 'number' => Sequence::next($c->id, 'archives.disposal', 'ELIM'), 'status' => 'proposed',
                'records' => json_encode($rows->map(fn ($r) => (array) $r)->all(), JSON_UNESCAPED_UNICODE), 'notes' => $notes, 'proposed_by' => $userId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('ar_records')->where('company_id', $c->id)->whereIn('id', $rows->pluck('id'))->update(['status' => 'pending_disposal', 'updated_at' => now()]);
            foreach ($rows as $r) {
                $this->log($c, $r->id, 'disposal_proposed', 'Bordereau d\'élimination n° '.$id, $userId);
            }

            return $id;
        });
    }

    /** Exécution : contenu détruit sur le stockage, notice conservée (preuve de l'élimination). */
    public function execute(Company $c, object $d, int $userId): void
    {
        $ids = collect(json_decode($d->records, true))->pluck('id');
        foreach (DB::table('ar_records')->where('company_id', $c->id)->whereIn('id', $ids)->where('status', 'pending_disposal')->get() as $r) {
            if ($r->legal_hold) {
                continue;
            }
            if ($r->file_path) {
                try {
                    $this->pool->delete($r->backend_id, $r->file_path, (int) $r->size);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
            DB::table('ar_records')->where('id', $r->id)->update(['status' => 'disposed', 'content' => null, 'file_path' => null, 'updated_at' => now()]);
            $this->log($c, $r->id, 'disposed', 'Éliminée — PV '.$d->number, $userId);
        }
        DB::table('ar_disposals')->where('id', $d->id)->update(['status' => 'executed', 'executed_by' => $userId, 'executed_at' => now(), 'updated_at' => now()]);
    }
}
