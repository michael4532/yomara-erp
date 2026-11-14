<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Platform\ClientComms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Mot de passe oublié : lien à usage unique (60 min) envoyé par e-mail, nouveau mot de passe choisi sur une page web. */
class PasswordResetController extends Controller
{
    public const TTL_MINUTES = 60;

    /** Demande du lien. Réponse identique que le compte existe ou non (pas d'énumération des comptes). */
    public function request(Request $r)
    {
        $d = $r->validate(['email' => 'required|email|max:190']);
        $email = mb_strtolower(trim($d['email']));
        $user = User::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [$email])->where('is_active', true)->first();
        if ($user) {
            $token = Str::random(64);
            DB::table('password_reset_tokens')->updateOrInsert(['email' => $email], ['token' => Hash::make($token), 'created_at' => now()]);
            $link = url('/mot-de-passe/'.$token.'?email='.rawurlencode($email));
            $app = e(config('erp.name', 'Yomara ERP'));
            $html = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#0B1530;max-width:560px;margin:0 auto;padding:24px">'
                .'<h2 style="margin:0 0 12px">Réinitialiser votre mot de passe</h2>'
                .'<p style="line-height:1.6">Bonjour '.e($user->name).',</p><p style="line-height:1.6">Vous avez demandé à changer le mot de passe de votre compte '.$app.'. Ce lien est valable '.self::TTL_MINUTES.' minutes et ne sert qu\'une fois :</p>'
                .'<p style="margin:22px 0"><a href="'.e($link).'" style="display:inline-block;background:#2E6BE6;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:10px">Choisir un nouveau mot de passe</a></p>'
                .'<p style="color:#64748B;font-size:13px;line-height:1.6">Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet e-mail : votre mot de passe actuel reste valable.</p></div>';
            $loc = in_array($user->locale ?? null, \App\Support\Tr::LOCALES, true) ? $user->locale : app()->getLocale();
            if ($loc !== 'fr') {
                $html = \App\Support\HtmlTranslator::translate($html, $loc);
            }
            rescue(fn () => ClientComms::mail($user->email, $user->name, \App\Support\Tr::f('Réinitialiser votre mot de passe {}', [config('erp.name', 'Yomara ERP')], $loc), $html, "Lien pour choisir un nouveau mot de passe (valable 60 minutes) : $link"), null, true);
            ActivityLog::record('auth.password.reset_requested', $user);
        }

        return ['message' => 'Si un compte existe avec cette adresse, un lien pour choisir un nouveau mot de passe vient d\'y être envoyé. Pensez à regarder dans les indésirables.'];
    }

    /** Lien de bienvenue d'un nouvel employé : refusé si le mot de passe a déjà été changé depuis l'envoi. */
    protected function invited(Request $r, int $id): User
    {
        $u = User::withoutGlobalScopes()->where('is_active', true)->findOrFail($id);
        abort_unless(hash_equals(substr(sha1((string) $u->password), 0, 12), (string) $r->query('v')), 410, 'Ce lien a déjà servi. Utilisez « Mot de passe oublié » sur la page de connexion.');

        return $u;
    }

    public function inviteForm(Request $r, int $user)
    {
        $u = $this->invited($r, $user);

        return response()->view('auth.reset', ['token' => '', 'email' => $u->email, 'ok' => false, 'expired' => false]);
    }

    public function inviteSave(Request $r, int $user)
    {
        $u = $this->invited($r, $user);
        $d = $r->validate(['password' => 'required|string|min:8|confirmed']);
        $u->forceFill(['password' => Hash::make($d['password'])])->save();
        ActivityLog::record('auth.password.invite_set', $u);

        return response()->view('auth.reset', ['token' => '', 'email' => $u->email, 'ok' => true, 'expired' => false]);
    }

    protected function valid(string $email, string $token): ?object
    {
        $row = DB::table('password_reset_tokens')->where('email', mb_strtolower($email))->first();
        if (! $row || ! $row->created_at || now()->diffInMinutes($row->created_at, true) > self::TTL_MINUTES || ! Hash::check($token, $row->token)) {
            return null;
        }

        return $row;
    }

    public function form(Request $r, string $token)
    {
        $email = (string) $r->query('email', '');

        return response()->view('auth.reset', ['token' => $token, 'email' => $email, 'ok' => false, 'expired' => ! $this->valid($email, $token)]);
    }

    public function reset(Request $r, string $token)
    {
        $d = $r->validate(['email' => 'required|email', 'password' => 'required|string|min:8|confirmed']);
        if (! $this->valid($d['email'], $token)) {
            return response()->view('auth.reset', ['token' => $token, 'email' => $d['email'], 'ok' => false, 'expired' => true], 422);
        }
        $user = User::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [mb_strtolower($d['email'])])->firstOrFail();
        $user->forceFill(['password' => Hash::make($d['password'])])->save();
        $user->tokens()->delete(); // toutes les sessions déconnectées
        DB::table('password_reset_tokens')->where('email', mb_strtolower($d['email']))->delete();
        ActivityLog::record('auth.password.reset', $user);

        return response()->view('auth.reset', ['token' => $token, 'email' => $d['email'], 'ok' => true, 'expired' => false]);
    }
}
