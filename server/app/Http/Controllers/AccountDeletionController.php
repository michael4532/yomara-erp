<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SiteTraffic;
use App\Store\ModuleStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Politique de confidentialité et suppression de compte (exigées par Google Play et l'App Store) :
 * une page publique pour demander la suppression sans application, et la même demande depuis l'application.
 * Les demandes arrivent dans « Demandes du site » de la console éditeur, et par e-mail.
 */
class AccountDeletionController extends Controller
{
    public const SCOPES = [
        'user' => 'Mon compte utilisateur',
        'company' => 'Toute mon entreprise et ses données',
    ];

    protected function common(): array
    {
        return [
            'appName' => config('erp.name', 'Yomara ERP'),
            'contact' => ModuleStore::contact(),
            'chatEnabled' => false,
            'chatWelcome' => '',
        ];
    }

    public function privacy()
    {
        return view('landing.confidentialite', $this->common());
    }

    public function form()
    {
        return view('landing.suppression', $this->common() + ['scopes' => self::SCOPES]);
    }

    /** Demande depuis la page publique (sans connexion) : vérifiée ensuite par l'équipe auprès du titulaire de l'adresse. */
    public function submit(Request $request)
    {
        if ($request->filled('website')) { // piège à robots
            return redirect('/suppression-compte')->with('deletion_ok', true);
        }
        $d = $request->validate([
            'email' => 'required|email|max:160',
            'name' => 'nullable|string|max:120',
            'company' => 'nullable|string|max:160',
            'scope' => ['required', Rule::in(array_keys(self::SCOPES))],
            'message' => 'nullable|string|max:3000',
        ]);
        $user = User::where('email', strtolower($d['email']))->first();
        $this->record($request, [
            'name' => ($d['name'] ?? '') ?: ($user?->name ?? $d['email']),
            'company' => ($d['company'] ?? '') ?: $user?->company?->name,
            'email' => strtolower($d['email']),
            'scope' => $d['scope'],
            'message' => $d['message'] ?? '',
            'source' => 'page web',
            'known' => (bool) $user,
        ]);

        return redirect('/suppression-compte')->with('deletion_ok', true);
    }

    /** Demande depuis l'application, après confirmation du mot de passe. */
    public function fromApp(Request $request)
    {
        $d = $request->validate([
            'password' => 'required|string',
            'scope' => ['required', Rule::in(array_keys(self::SCOPES))],
            'message' => 'nullable|string|max:3000',
        ]);
        /** @var User $user */
        $user = $request->user();
        if (! Hash::check($d['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Mot de passe incorrect.']);
        }
        abort_if($user->is_super_admin, 422, 'Le compte de l\'éditeur de la plateforme ne peut pas être supprimé depuis l\'application.');

        $this->record($request, [
            'name' => $user->name,
            'company' => $user->company?->name,
            'email' => $user->email,
            'scope' => $d['scope'],
            'message' => $d['message'] ?? '',
            'source' => 'application (utilisateur n° '.$user->id.', entreprise n° '.$user->company_id.')',
            'known' => true,
        ]);

        // Compte utilisateur : désactivé tout de suite et déconnecté de tous les appareils ; les données sont effacées par l'équipe sous 30 jours.
        if ($d['scope'] === 'user') {
            $user->forceFill(['is_active' => false])->save();
            $user->tokens()->delete();
            \App\Models\ActivityLog::record('account.deletion_requested', $user);

            return ['ok' => true, 'signed_out' => true, 'message' => 'Votre compte est désactivé. Ses données personnelles seront supprimées sous 30 jours ; un e-mail vous le confirmera.'];
        }
        \App\Models\ActivityLog::record('company.deletion_requested', $user);

        return ['ok' => true, 'signed_out' => false, 'message' => 'Demande enregistrée. Notre équipe vous contacte pour confirmer, puis supprime l\'entreprise et toutes ses données sous 30 jours. Pensez à exporter ce que vous voulez garder.'];
    }

    protected function record(Request $request, array $r): void
    {
        $scope = self::SCOPES[$r['scope']];
        $body = 'Demande : '.$scope."\nOrigine : ".$r['source'].($r['known'] ? '' : "\nAttention : aucun compte trouvé avec cette adresse.").($r['message'] !== '' ? "\n\n".$r['message'] : '');
        $id = DB::table('landing_requests')->insertGetId([
            'type' => 'suppression',
            'name' => mb_substr((string) $r['name'], 0, 250),
            'company' => $r['company'] ? mb_substr((string) $r['company'], 0, 250) : null,
            'email' => $r['email'],
            'message' => $body,
            'status' => 'new',
            'ip' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        rescue(fn () => SiteTraffic::record($request, 'account_deletion', $r['scope']), null, false);

        $to = ModuleStore::contact()['email'] ?: config('mail.from.address');
        try {
            if ($to) {
                Mail::html('<div style="font-family:Segoe UI,Arial,sans-serif;color:#0B1530"><h2 style="margin:0 0 12px">Demande de suppression n° '.$id.'</h2>'
                    .'<p><b>'.e($scope).'</b><br>'.e($r['name']).' — '.e($r['email']).($r['company'] ? '<br>'.e($r['company']) : '').'</p>'
                    .'<p style="white-space:pre-wrap;background:#F7F9FD;border-radius:10px;padding:14px">'.e($body).'</p>'
                    .'<p style="color:#64748B;font-size:13px">À traiter sous 30 jours. Vérifiez l\'identité du demandeur avant toute suppression d\'entreprise.</p></div>',
                    fn ($m) => $m->to($to)->replyTo($r['email'])->subject('['.config('erp.name', 'Yomara ERP').'] Suppression de compte — '.$r['email']));
            }
            Mail::html('<div style="font-family:Segoe UI,Arial,sans-serif;color:#0B1530"><p>Bonjour,</p><p>Nous avons bien reçu votre demande de suppression (« '.e($scope).' »). Elle est traitée sous 30 jours au plus. Si vous n\'êtes pas à l\'origine de cette demande, répondez simplement à cet e-mail.</p><p>— '.e(ModuleStore::contact()['name'] ?: config('erp.name')).'</p></div>',
                fn ($m) => $m->to($r['email'])->subject('Votre demande de suppression a bien été reçue'));
        } catch (\Throwable $e) {
            Log::warning('Demande de suppression non envoyée par e-mail : '.$e->getMessage());
        }
    }
}
