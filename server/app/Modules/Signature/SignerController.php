<?php

namespace App\Modules\Signature;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Signature\Models\SignEvent;
use App\Modules\Signature\Models\Signer;
use App\Modules\Signature\Models\SignRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Pages publiques : signature par lien personnel, vérification d'un document signé. */
class SignerController extends Controller
{
    public function __construct(protected SignatureService $svc) {}

    /** @return array{0: Company, 1: SignRequest, 2: Signer} */
    protected function ctx(string $token): array
    {
        $s = strlen($token) === 40 ? Signer::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->first() : null;
        abort_unless($s && ! $s->user_id, 404);
        $c = Company::find($s->company_id);
        abort_unless($c && $c->status !== 'suspended', 404);
        app()->instance('tenant', $c);
        $r = SignRequest::findOrFail($s->request_id);
        abort_if($r->status === 'draft', 404);
        $this->svc->expireIfNeeded($r);

        return [$c, $r, $s];
    }

    public function show(Request $req, string $token)
    {
        [$c, $r, $s] = $this->ctx($token);
        $this->svc->viewed($r, $s, $req->ip(), $req->userAgent());
        $s->refresh();
        $otp = in_array($s->status, ['pending', 'viewed'], true) && $r->status === 'pending' ? $this->svc->otpChannel($c, $s) : null;

        return response()->view('signature.sign', [
            'company' => $c, 'r' => $r, 's' => $s, 'token' => $token, 'otp' => $otp, 'verified' => (bool) $s->verified_at,
            'otpTo' => $otp ? Signer::mask($otp === 'email' ? $s->email : $s->phone) : null, 'otpSent' => (bool) $s->otp_hash,
            'others' => Signer::where('request_id', $r->id)->orderBy('position')->orderBy('id')->get(),
        ])->header('X-Robots-Tag', 'noindex');
    }

    public function document(Request $req, string $token)
    {
        [, $r, $s] = $this->ctx($token);
        $signed = $r->status === 'completed' && $r->signed_path && $req->query('v') !== 'original';
        $path = $signed ? $r->signed_path : $r->original_path;
        abort_unless(Storage::disk('local')->exists($path), 404);
        if (! SignEvent::where('request_id', $r->id)->where('signer_id', $s->id)->where('type', 'downloaded')->exists()) {
            $this->svc->log($r, 'downloaded', $s, ['signed' => $signed], $req->ip(), $req->userAgent());
        }
        $name = pathinfo($r->original_name, PATHINFO_FILENAME).($signed ? ' - signé' : '').'.pdf';

        return response()->file(Storage::disk('local')->path($path), ['Content-Type' => 'application/pdf', 'Content-Disposition' => ($req->boolean('dl') ? 'attachment' : 'inline').'; filename="'.str_replace('"', '', $name).'"', 'X-Robots-Tag' => 'noindex']);
    }

    public function code(Request $req, string $token)
    {
        [$c, $r, $s] = $this->ctx($token);
        try {
            $ch = $this->svc->sendOtp($c, $r, $s, $req->ip());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('ok', 'Code envoyé par '.['sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'email' => 'e-mail'][$ch].'. Saisissez-le ci-dessous.');
    }

    public function verify(Request $req, string $token)
    {
        [$c, $r, $s] = $this->ctx($token);
        $code = (string) $req->validate(['code' => 'required|string|max:12'], ['code.required' => 'Saisissez le code reçu.'])['code'];
        try {
            $this->svc->verifyOtp($c, $r, $s, $code, $req->ip());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('ok', 'Identité vérifiée : vous pouvez signer.');
    }

    public function sign(Request $req, string $token)
    {
        [$c, $r, $s] = $this->ctx($token);
        $v = $req->validate(['signature' => 'required|string', 'agree' => 'accepted'], [
            'signature.required' => 'Tracez votre signature dans le cadre.', 'agree.accepted' => 'Cochez la case pour confirmer que vous avez lu le document.',
        ]);
        try {
            $r = $this->svc->sign($c, $r, $s, $v['signature'], $req->ip(), $req->userAgent());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect('/signer/'.$token)->with('ok', $r->status === 'completed'
            ? 'Merci ! Le document est signé par toutes les parties. Téléchargez votre exemplaire signé ci-dessous.'
            : 'Merci ! Votre signature est enregistrée. Vous recevrez le document final quand toutes les parties auront signé.');
    }

    public function decline(Request $req, string $token)
    {
        [$c, $r, $s] = $this->ctx($token);
        $reason = $req->validate(['reason' => 'nullable|string|max:500'])['reason'] ?? null;
        try {
            $this->svc->decline($c, $r, $s, $reason, $req->ip(), $req->userAgent());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect('/signer/'.$token)->with('ok', 'Votre refus a été transmis à '.$c->name.'.');
    }

    public function verifyForm()
    {
        return response()->view('signature.verify', ['result' => null, 'checked' => false]);
    }

    public function verifyUpload(Request $req)
    {
        $req->validate(['file' => 'required|file|max:20480'], ['file.required' => 'Choisissez le fichier PDF à vérifier.']);
        $res = $this->svc->verify($req->file('file')->get());

        return response()->view('signature.verify', ['result' => $res, 'checked' => true, 'svc' => $this->svc]);
    }
}
