<?php

namespace App\Modules\Documents;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Documents\Models\DocFile;
use App\Modules\Documents\Models\DocShare;
use App\Modules\Documents\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/** Lien de partage public d'un fichier ou d'un dossier (expiration, mot de passe, nombre de téléchargements). */
class ShareController extends Controller
{
    /** @return array{0: Company, 1: DocShare} */
    protected function ctx(string $token): array
    {
        $s = strlen($token) === 32 ? DocShare::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->first() : null;
        abort_unless($s, 404);
        $c = Company::find($s->company_id);
        abort_unless($c && $c->status !== 'suspended' && $c->hasModule('documents'), 404);
        app()->instance('tenant', $c);

        return [$c, $s];
    }

    protected function files(DocShare $s)
    {
        if ($s->file_id) {
            return DocFile::where('id', $s->file_id)->get();
        }
        if (! Folder::find($s->folder_id)) {
            return collect();
        }

        return DocFile::where('folder_id', $s->folder_id)->orderBy('name')->get();
    }

    protected function unlocked(Request $r, DocShare $s): bool
    {
        return ! $s->password_hash || $r->session()->get('dshare.'.$s->id) === true;
    }

    public function show(Request $r, string $token)
    {
        [$c, $s] = $this->ctx($token);
        $files = $s->active() && $this->unlocked($r, $s) ? $this->files($s) : collect();

        return response()->view('documents.share', ['company' => $c, 's' => $s, 'token' => $token, 'files' => $files, 'locked' => ! $this->unlocked($r, $s),
            'folder' => $s->folder_id ? Folder::find($s->folder_id) : null])->header('X-Robots-Tag', 'noindex');
    }

    public function unlock(Request $r, string $token)
    {
        [, $s] = $this->ctx($token);
        if ($s->password_hash && Hash::check((string) $r->input('password'), $s->password_hash)) {
            $r->session()->put('dshare.'.$s->id, true);

            return redirect('/d/'.$token);
        }

        return back()->with('error', 'Mot de passe incorrect.');
    }

    public function download(Request $r, string $token, ?string $fileId = null)
    {
        [, $s] = $this->ctx($token);
        abort_unless($s->active() && $this->unlocked($r, $s), 403, 'Lien expiré ou protégé.');
        $f = $this->files($s)->first(fn ($x) => ! $fileId || $x->id === $fileId);
        abort_unless($f && $f->path && Storage::disk('local')->exists($f->path), 404);
        $s->forceFill(['downloads' => $s->downloads + 1, 'last_access_at' => now()])->save();

        return response()->file(Storage::disk('local')->path($f->path), \App\Support\SafeDownload::headers($f->mime, (string) $f->name, $r->boolean('inline')) + ['X-Robots-Tag' => 'noindex']);
    }
}
