<?php

namespace App\Modules\Pdf;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Historique personnel des outils PDF : chaque fichier produit (fusion, découpe, filigrane, modification…)
 * est gardé pour son auteur, qui peut le retélécharger. 30 fichiers au plus par personne, 30 jours au plus.
 */
class PdfHistory
{
    public const KEEP = 30;

    public const DAYS = 30;

    public const LABELS = [
        'merge' => 'Fusion', 'split' => 'Découpe', 'organize' => 'Pages réorganisées', 'rotate' => 'Pages pivotées', 'watermark' => 'Filigrane',
        'edit' => 'Modification', 'annotate' => 'Annotations', 'numbers' => 'Numérotation', 'protect' => 'Protection', 'unlock' => 'Protection retirée', 'images' => 'Images en PDF',
    ];

    protected static function ready(): bool
    {
        return Schema::hasTable('pdf_history');
    }

    public static function record(int $companyId, int $userId, string $op, string $name, string $bytes, string $mime, ?string $source, int $files): void
    {
        if (! self::ready() || $bytes === '') {
            return;
        }
        $path = 'pdf-history/'.$companyId.'/'.$userId.'/'.Str::uuid().'.'.($mime === 'application/zip' ? 'zip' : 'pdf');
        Storage::disk('local')->put($path, $bytes);
        DB::table('pdf_history')->insert([
            'company_id' => $companyId, 'user_id' => $userId, 'op' => $op, 'name' => Str::limit($name, 185, ''), 'source' => $source ? Str::limit($source, 185, '') : null,
            'mime' => $mime, 'size' => strlen($bytes), 'files' => $files, 'path' => $path, 'created_at' => now(),
        ]);
        self::prune($companyId, $userId);
    }

    public static function prune(int $companyId, int $userId): void
    {
        $q = DB::table('pdf_history')->where('company_id', $companyId)->where('user_id', $userId);
        $keep = (clone $q)->orderByDesc('id')->limit(self::KEEP)->pluck('id');
        $old = (clone $q)->where(fn ($w) => $w->whereNotIn('id', $keep)->orWhere('created_at', '<', now()->subDays(self::DAYS)))->get(['id', 'path']);
        foreach ($old as $o) {
            Storage::disk('local')->delete($o->path);
        }
        if ($old->isNotEmpty()) {
            DB::table('pdf_history')->whereIn('id', $old->pluck('id'))->delete();
        }
    }

    public static function list(int $companyId, int $userId): array
    {
        if (! self::ready()) {
            return [];
        }
        self::prune($companyId, $userId);

        return DB::table('pdf_history')->where('company_id', $companyId)->where('user_id', $userId)->orderByDesc('id')->get()
            ->map(fn ($h) => [
                'id' => $h->id, 'op' => $h->op, 'label' => self::LABELS[$h->op] ?? $h->op, 'name' => $h->name, 'source' => $h->source,
                'zip' => $h->mime === 'application/zip', 'size' => (int) $h->size, 'files' => (int) $h->files, 'created_at' => \Illuminate\Support\Carbon::parse($h->created_at)->toIso8601String(),
            ])->all();
    }

    public static function find(int $companyId, int $userId, int $id): object
    {
        $h = self::ready() ? DB::table('pdf_history')->where('company_id', $companyId)->where('user_id', $userId)->where('id', $id)->first() : null;
        abort_unless($h && Storage::disk('local')->exists($h->path), 404, 'Ce fichier n\'est plus dans votre historique.');

        return $h;
    }
}
