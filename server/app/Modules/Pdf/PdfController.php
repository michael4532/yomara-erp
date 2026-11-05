<?php

namespace App\Modules\Pdf;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class PdfController extends Controller
{
    public const OPS = ['info', 'merge', 'split', 'organize', 'rotate', 'watermark', 'edit', 'annotate', 'numbers', 'protect', 'unlock', 'images'];

    protected function pdfFile(UploadedFile $f): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $f->getRealPath());
        if ($mime !== 'application/pdf' && ! str_starts_with((string) file_get_contents($f->getRealPath(), false, null, 0, 5), '%PDF')) {
            throw new \DomainException('« '.$f->getClientOriginalName().' » n\'est pas un PDF.');
        }

        return (string) $f->getRealPath();
    }

    protected function imageFile(?UploadedFile $f): ?string
    {
        if (! $f) {
            return null;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $f->getRealPath());
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg'][$mime] ?? throw new \DomainException('Image JPG ou PNG uniquement.');
        // TCPDF devine le format à l'extension : copie temporaire nommée
        $tmp = tempnam(sys_get_temp_dir(), 'yimg').'.'.$ext;
        copy($f->getRealPath(), $tmp);
        register_shutdown_function(fn () => @unlink($tmp));

        return $tmp;
    }

    protected function name(Request $r, string $suffix): string
    {
        $first = $r->file('files')[0] ?? null;
        $base = $first ? pathinfo($first->getClientOriginalName(), PATHINFO_FILENAME) : 'document';

        return \Illuminate\Support\Str::slug($base, '-').'-'.$suffix;
    }

    public function run(Request $r, PdfService $pdf, string $op)
    {
        abort_unless(in_array($op, self::OPS, true), 404);
        $r->validate([
            'files' => 'nullable|array|max:30', 'files.*' => 'file|max:51200',
            'images' => 'nullable|array|max:60', 'images.*' => 'file|max:15360',
            'image' => 'nullable|file|max:15360', 'options' => 'nullable|string|max:20000',
        ]);
        $o = json_decode((string) $r->input('options', '{}'), true) ?: [];
        @set_time_limit(180);
        @ini_set('memory_limit', '1024M');
        try {
            $files = array_map(fn ($f) => $this->pdfFile($f), $r->file('files') ?? []);
            if ($op !== 'images' && ! $files) {
                throw new \DomainException('Ajoutez un fichier PDF.');
            }
            $out = match ($op) {
                'info' => $pdf->info($files[0]),
                'merge' => $pdf->merge($files, (array) ($o['ranges'] ?? [])),
                'organize' => $pdf->organize($files[0], $o['order'] ?? null, (array) ($o['rotations'] ?? [])),
                'rotate' => $pdf->rotate($files[0], (int) ($o['angle'] ?? 90), $o['pages'] ?? null),
                'split' => $pdf->split($files[0], $o['groups'] ?? null, (int) ($o['every'] ?? 0)),
                'watermark' => $pdf->watermark($files[0], ['image' => $this->imageFile($r->file('image'))] + $o),
                'edit' => $pdf->edit($files[0], $this->items($r, (array) ($o['items'] ?? []), 800), (array) ($o['pages'] ?? [])),
                'annotate' => $pdf->annotate($files[0], $this->items($r, (array) ($o['items'] ?? []))),
                'numbers' => $pdf->numbers($files[0], $o),
                'protect' => $pdf->protect($files[0], (string) ($o['password'] ?? ''), $o['owner_password'] ?? null, (array) ($o['allow'] ?? [])),
                'unlock' => $pdf->unlock($files[0], (string) ($o['password'] ?? '')),
                'images' => $pdf->images(array_map(fn ($f) => $this->imageFile($f), $r->file('images') ?? []), $o['format'] ?? 'A4'),
            };
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        ActivityLog::record('pdf.'.$op, null, ['files' => count($r->file('files') ?? []) + count($r->file('images') ?? [])]);
        if ($op === 'info') {
            return $out;
        }
        if (is_array($out)) {
            // Découpe : plusieurs PDF dans un zip
            $zip = tempnam(sys_get_temp_dir(), 'yzip');
            $z = new \ZipArchive();
            $z->open($zip, \ZipArchive::OVERWRITE);
            foreach ($out as $n => $content) {
                $z->addFromString($n, $content);
            }
            $z->close();
            $data = (string) file_get_contents($zip);
            @unlink($zip);

            return response($data)->header('Content-Type', 'application/zip')->header('Content-Disposition', 'attachment; filename="'.$this->name($r, 'pages.zip').'"');
        }
        $suffix = ['merge' => 'fusion', 'organize' => 'reorganise', 'rotate' => 'pivote', 'watermark' => 'filigrane', 'annotate' => 'annote', 'edit' => 'modifie', 'numbers' => 'numerote',
            'protect' => 'protege', 'unlock' => 'deverrouille', 'images' => 'images'][$op];

        return response($out)->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="'.$this->name($r, $suffix.'.pdf').'"');
    }

    /** Éléments à ajouter : les images envoyées sont référencées par leur position (image_index). */
    protected function items(Request $r, array $items, int $max = 100): array
    {
        $imgs = $r->file('images') ?? [];

        return array_map(function ($it) use ($imgs) {
            $it = is_array($it) ? $it : [];
            unset($it['image']); // jamais de chemin fourni par le client (lecture de fichiers, SSRF)
            if (($it['type'] ?? '') === 'image') {
                $it['image'] = $this->imageFile($imgs[(int) ($it['image_index'] ?? 0)] ?? null);
            }

            return $it;
        }, array_slice($items, 0, $max));
    }
}
