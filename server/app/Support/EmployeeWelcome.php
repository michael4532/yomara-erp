<?php

namespace App\Support;

use App\Models\AppNotification;
use App\Models\User;
use App\Platform\ClientComms;
use Illuminate\Support\Facades\URL;

/** Nouvel employé : e-mail de bienvenue (lien pour choisir son mot de passe, applications) et notification dans l'app. */
class EmployeeWelcome
{
    /** Lien signé, valable 7 jours, qui ne sert plus une fois le mot de passe changé. */
    public static function passwordLink(User $u): string
    {
        return URL::temporarySignedRoute('invite.password', now()->addDays(7), ['user' => $u->id, 'v' => substr(sha1((string) $u->password), 0, 12)]);
    }

    public static function send(User $u, ?User $by = null): void
    {
        $c = $u->company;
        rescue(fn () => AppNotification::send($u->company_id, [$u->id], 'welcome', 'Bienvenue chez '.($c?->name ?? config('erp.name')).' !',
            'Votre compte a été créé'.($by ? ' par '.$by->name : '').'. Découvrez votre espace : vos tâches, votre agenda et les modules auxquels vous avez accès.', 'dashboard'), null, false);
        if (! $u->email) {
            return;
        }
        $link = self::passwordLink($u);
        $vars = ['prenom' => trim(explode(' ', (string) $u->name)[0]) ?: $u->name, 'nom' => $u->name, 'email' => $u->email, 'entreprise' => $c?->name ?? '',
            'createur' => $by?->name ?? 'Votre administrateur', 'role' => $u->role?->name ?? '', 'lien' => url('/app/')];
        [$subject, $text] = MailTemplates::render('employee_welcome', $vars, $c);
        $apps = '<p style="margin:18px 0 0;font-size:13px;color:#5B6B85">Se connecter : <a href="'.e(url('/app/')).'">'.e(url('/app/')).'</a> · '
            .'Application mobile et ordinateur : <a href="'.e(url('/application-mobile')).'">télécharger</a></p>';
        $html = MailTemplates::layout($c, nl2br(e($text), false).$apps, ['html' => true, 'button' => ['Choisir mon mot de passe', $link]]);
        rescue(fn () => ClientComms::mail($u->email, $u->name, $subject, $html, $text."\n\nChoisir mon mot de passe : ".$link."\nSe connecter : ".url('/app/')), null, true);
    }
}
