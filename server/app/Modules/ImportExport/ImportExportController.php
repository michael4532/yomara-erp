<?php

namespace App\Modules\ImportExport;

use App\Http\Controllers\Controller;
use App\Modules\ImportExport\Models\ImportJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportExportController extends Controller
{
    public function __construct(protected ImportService $imports, protected ExportService $exports) {}

    /** Droits métier nécessaires pour importer chaque type (en plus de import_export.import) : l'import ne doit pas contourner les droits du module. */
    public const IMPORT_PERMISSIONS = [
        'partners' => ['core.contacts.manage'],
        'products' => ['core.products.manage'],
        'stock' => ['stock.manage'],
        'invoices' => ['ventes.manage', 'ventes.validate'],
        'opening_balance' => ['compta.manage'],
        'entries' => ['compta.manage'],
    ];

    public static function canImport($user, string $entity): bool
    {
        foreach (self::IMPORT_PERMISSIONS[$entity] ?? [] as $p) {
            if (! $user->hasPermission($p)) {
                return false;
            }
        }

        return true;
    }

    protected function importDef(Request $request, string $entity, int $unknown = 404): array
    {
        $def = $this->imports->definitions(app('tenant'))[$entity] ?? abort($unknown, 'Type d\'import inconnu.');
        abort_unless(static::canImport($request->user(), $entity), 403, 'Vous n\'avez pas le droit de modifier ces données.');

        return $def;
    }

    /** Types importables et exportables pour cette société et cet utilisateur. */
    public function entities(Request $request)
    {
        $u = $request->user();
        $defs = collect($this->imports->definitions(app('tenant')))->filter(fn ($d, $k) => static::canImport($u, $k))->map(fn ($d, $k) => [
            'key' => $k, 'label' => $d['label'], 'keys' => $d['keys'],
            'fields' => collect($d['fields'])->map(fn ($f, $name) => ['name' => $name, 'label' => $f[0], 'required' => (bool) ($f[2] ?? false)])->values(),
        ])->values();
        $exports = collect($this->exports->available(app('tenant')))->filter(fn ($e) => $u->hasPermission($e[2]))
            ->map(fn ($e, $k) => ['key' => $k, 'label' => $e[0], 'period' => $e[1]])->values();

        return response()->json(['data' => ['imports' => $u->hasPermission('import_export.import') ? $defs : [], 'exports' => $u->hasPermission('import_export.export') ? $exports : []]]);
    }

    /** Modèle Excel à remplir (en-têtes reconnus + une ligne d'exemple). */
    public function template(Request $request, string $entity)
    {
        $def = $this->importDef($request, $entity);
        $examples = [
            'partners' => ['Maquis Chez Tantie', 'client', 'C001', '07 07 07 07 07', 'contact@tantie.ci', 'Rue des Jardins', 'Cocody', '1234567A', 500000, ''],
            'products' => ['Coca-Cola 33cl', 'COCA33', '5449000000996', 'Boissons', 'produit', 'unité', '', 500, 350, 18, 24, 120, '', 'oui'],
            'stock' => ['COCA33', 118, 'Boutique', 350],
            'invoices' => ['Maquis Chez Tantie', 'FA-2025-0412', '15/09/2026', '15/10/2026', 125000, 25000],
            'opening_balance' => ['521100', 'Banque SGBCI', 2500000, '', ''],
            'entries' => ['VT', '15/09/2026', 'FA-0412', '411000', 'Facture Maquis Chez Tantie', 125000, '', '', '', ''],
        ][$entity] ?? [];
        $headers = collect($def['fields'])->map(fn ($f) => $f[0])->values()->all();
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        Sheet::write($path, $headers, [array_slice(array_pad($examples, count($headers), ''), 0, count($headers))]);

        return response()->download($path, 'modele_import_'.$entity.'.xlsx')->deleteFileAfterSend();
    }

    /** Dépôt du fichier : en-têtes, aperçu, correspondance proposée. */
    public function analyze(Request $request)
    {
        $v = $request->validate(['entity' => 'required|string', 'file' => 'required|file|max:20480']);
        $def = $this->importDef($request, $v['entity'], 422);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext === 'xlsx' && ! class_exists(\ZipArchive::class)) {
            return response()->json(['message' => 'L\'extension PHP « zip » manque sur ce serveur : enregistrez le fichier en CSV depuis Excel, ou demandez à votre hébergeur d\'activer zip.'], 422);
        }
        if (! in_array($ext, ['xlsx', 'csv', 'txt'], true)) {
            return response()->json(['message' => 'Format non pris en charge : enregistrez le fichier en .xlsx (Excel) ou .csv. Les anciens fichiers .xls doivent être réenregistrés depuis Excel.'], 422);
        }
        $rel = 'imports/'.app('tenant')->id.'/'.Str::uuid().'.'.$ext;
        Storage::disk('local')->putFileAs(dirname($rel), $file, basename($rel));
        try {
            [$headers, $rows] = Sheet::read(Storage::disk('local')->path($rel));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Fichier illisible : '.$e->getMessage()], 422);
        }
        if (! $headers || ! $rows) {
            return response()->json(['message' => 'Le fichier est vide : la première ligne doit contenir les titres des colonnes, les suivantes les données.'], 422);
        }
        $job = ImportJob::create(['entity' => $v['entity'], 'filename' => $file->getClientOriginalName(), 'path' => $rel, 'rows' => count($rows), 'headers' => $headers,
            'user_id' => $request->user()->id, 'user_name' => $request->user()->name]);

        return response()->json(['data' => [
            'job_id' => $job->id, 'headers' => $headers, 'rows' => count($rows),
            'sample' => array_slice($rows, 0, 8), 'mapping' => (object) $this->imports->suggest($def, $headers),
        ]]);
    }

    public function run(Request $request, int $id)
    {
        $job = ImportJob::findOrFail($id);
        $v = $request->validate([
            'mapping' => 'required|array', 'mapping.*' => 'nullable|integer|min:0',
            'mode' => 'required|in:create,update,upsert', 'key' => 'required|string', 'dry_run' => 'boolean', 'atomic' => 'boolean',
        ]);
        $def = $this->importDef($request, $job->entity, 422);
        if ($job->entity === 'products' && isset($v['mapping']['stock']) && ! $request->user()->hasPermission('stock.manage')) {
            return response()->json(['message' => 'La colonne « Stock actuel » demande le droit de gérer le stock : retirez-la de la correspondance.'], 403);
        }
        if (! array_key_exists($v['key'], $def['keys'])) {
            return response()->json(['message' => 'Clé de rapprochement inconnue.'], 422);
        }
        try {
            $report = $this->imports->run($job, array_filter($v['mapping'], fn ($x) => $x !== null), $v['mode'], $v['key'], $v['dry_run'] ?? false, $v['atomic'] ?? false);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $report]);
    }

    public function jobs()
    {
        return response()->json(['data' => ImportJob::latest('id')->take(50)->get()->map(fn ($j) => $j->only(['id', 'entity', 'filename', 'status', 'rows', 'created', 'updated', 'skipped', 'failed', 'user_name', 'created_at']))]);
    }

    /** Lignes refusées, avec le motif, à corriger puis réimporter. */
    public function errors(int $id)
    {
        $job = ImportJob::findOrFail($id);
        [$headers, $rows] = Sheet::read(Storage::disk('local')->path($job->path));
        $bad = collect($job->errors ?? [])->keyBy('row');
        $out = [];
        foreach ($rows as $i => $r) {
            if ($e = $bad->get($i + 2)) {
                $out[] = array_merge([$i + 2, $e['message']], array_pad($r, count($headers), null));
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'err').'.xlsx';
        Sheet::write($path, array_merge(['Ligne', 'Erreur'], $headers), $out);

        return response()->download($path, 'erreurs_import_'.$job->id.'.xlsx')->deleteFileAfterSend();
    }

    public function export(Request $request, string $entity)
    {
        $v = $request->validate(['format' => 'nullable|in:xlsx,csv', 'from' => 'nullable|date', 'to' => 'nullable|date']);
        $def = $this->exports->available(app('tenant'))[$entity] ?? abort(404);
        abort_unless($request->user()->hasPermission($def[2]), 403);
        [$title, $headers, $rows] = $this->exports->build($entity, $v['from'] ?? null, $v['to'] ?? null);
        $ext = $v['format'] ?? 'xlsx';
        $path = tempnam(sys_get_temp_dir(), 'exp').'.'.$ext;
        Sheet::write($path, $headers, $rows);

        return response()->download($path, Str::slug($title, '_').'_'.now()->format('Y-m-d').'.'.$ext)->deleteFileAfterSend();
    }
}
