<?php

namespace App\Modules\Rh;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Rh\Models\Employee;
use App\Modules\Rh\Models\EmployeeRequest;
use App\Modules\Rh\Models\Leave;

/**
 * Circulation des demandes des salariés : notification dans l'application et e-mail,
 * avec le responsable direct du salarié toujours en copie.
 */
class RequestNotifier
{
    public function __construct(protected MessagingService $messaging) {}

    /** Responsable direct : celui de la fiche, sinon le titulaire du poste supérieur (fiche de poste). */
    public static function managerOf(Employee $e): ?Employee
    {
        if ($e->manager_id && $m = Employee::where('active', true)->find($e->manager_id)) {
            return $m->id === $e->id ? null : $m;
        }
        if ($e->position_id) {
            $parent = \App\Modules\Rh\Models\Position::whereKey($e->position_id)->value('parent_id');
            if ($parent) {
                return Employee::where('position_id', $parent)->where('active', true)->where('id', '!=', $e->id)->orderBy('hire_date')->first();
            }
        }

        return null;
    }

    public static function emailOf(?Employee $e): ?string
    {
        if (! $e) {
            return null;
        }
        $mail = $e->email ?: ($e->user_id ? User::whereKey($e->user_id)->value('email') : null);

        return $mail && filter_var($mail, FILTER_VALIDATE_EMAIL) ? $mail : null;
    }

    /** Comptes du service RH (droit rh.manage). */
    protected function hrUsers(int $companyId)
    {
        return User::where('company_id', $companyId)->where('is_active', true)->get()->filter(fn ($u) => ! $u->is_super_admin && $u->hasPermission('rh.manage'))->values();
    }

    /** Adresse(s) du service RH : celle des réglages, sinon les comptes RH. */
    protected function hrEmails(Company $c): array
    {
        $set = trim((string) data_get($c->settings, 'rh_requests.email'));
        if ($set !== '') {
            return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $set)), fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));
        }

        return $this->hrUsers($c->id)->pluck('email')->filter(fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL))->values()->all();
    }

    /** Personnes choisies en copie par le salarié (équipe RH, direction…). */
    protected function ccUsers(EmployeeRequest $r)
    {
        return $r->cc_user_ids ? User::whereIn('id', (array) $r->cc_user_ids)->where('is_active', true)->get() : collect();
    }

    protected function ccEmails(EmployeeRequest $r): array
    {
        return $this->ccUsers($r)->pluck('email')->filter(fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL))->values()->all();
    }

    public function submitted(EmployeeRequest $r): void
    {
        $c = app('tenant');
        $emp = $r->employee;
        $manager = $r->manager_id ? Employee::withTrashed()->find($r->manager_id) : null;
        $title = 'Nouvelle demande '.$r->number;
        $body = ($emp?->full_name ?? '').' : '.$this->summary($r);
        AppNotification::send($c->id, array_merge($this->hrUsers($c->id)->pluck('id')->all(), $this->ccUsers($r)->pluck('id')->all()), 'rh.request', $title, $body, 'rh', $r->id, auth()->id());
        if ($manager?->user_id) {
            AppNotification::send($c->id, [$manager->user_id], 'rh.request', 'Demande de votre équipe '.$r->number, $body, 'rh', $r->id, auth()->id());
        }
        // E-mail au service RH, en copie : le responsable et le salarié (accusé de réception)
        $this->mail($c, $this->hrEmails($c), array_merge(array_filter([self::emailOf($manager), self::emailOf($emp)]), $this->ccEmails($r)), $r,
            'Nouvelle demande de '.($emp?->full_name ?? 'un salarié'),
            'Une nouvelle demande a été envoyée depuis l\'espace salarié.');
    }

    public function statusChanged(EmployeeRequest $r): void
    {
        $c = app('tenant');
        $emp = $r->employee;
        $manager = $r->manager_id ? Employee::withTrashed()->find($r->manager_id) : null;
        $label = EmployeeRequest::STATUSES[$r->status] ?? $r->status;
        $actor = auth()->id();
        if ($r->status === 'cancelled' && $emp?->user_id === $actor) {
            // Annulée par le salarié : on prévient la RH (responsable en copie)
            AppNotification::send($c->id, $this->hrUsers($c->id)->pluck('id')->all(), 'rh.request', 'Demande '.$r->number.' annulée', ($emp?->full_name ?? '').' : '.$this->summary($r), 'rh', $r->id, $actor);
            $this->mail($c, $this->hrEmails($c), array_filter([self::emailOf($manager)]), $r, 'Demande annulée par '.($emp?->full_name ?? 'le salarié'), 'Le salarié a annulé sa demande.');

            return;
        }
        $text = $this->summary($r).($r->decision_note ? ' — '.$r->decision_note : '');
        if ($emp?->user_id && $emp->user_id !== $actor) {
            AppNotification::send($c->id, [$emp->user_id], 'rh.request', 'Demande '.$r->number.' : '.mb_strtolower($label), $text, 'rh', $r->id);
        }
        if ($manager?->user_id && $manager->user_id !== $actor) {
            AppNotification::send($c->id, [$manager->user_id], 'rh.request', 'Demande de '.($emp?->full_name ?? '').' : '.mb_strtolower($label), $text, 'rh', $r->id);
        }
        // E-mail au salarié, en copie : son responsable et le service RH
        $this->mail($c, array_filter([self::emailOf($emp)]), array_merge(array_filter([self::emailOf($manager)]), $this->hrEmails($c), $this->ccEmails($r)), $r,
            'Votre demande est '.mb_strtolower($label), match ($r->status) {
                'approved' => 'Votre demande a été acceptée.',
                'rejected' => 'Votre demande n\'a pas été acceptée.',
                'in_review' => 'Votre demande est en cours d\'examen par le service RH.',
                default => 'Le statut de votre demande a changé : '.mb_strtolower($label).'.',
            });
    }

    public function commented(EmployeeRequest $r, User $author, string $text): void
    {
        $c = app('tenant');
        $emp = $r->employee;
        $manager = $r->manager_id ? Employee::withTrashed()->find($r->manager_id) : null;
        $fromHr = $author->hasPermission('rh.manage') && $emp?->user_id !== $author->id;
        $title = 'Message sur la demande '.$r->number;
        $users = $fromHr ? [$emp?->user_id, $manager?->user_id] : array_merge($this->hrUsers($c->id)->pluck('id')->all(), [$emp?->user_id, $manager?->user_id]);
        AppNotification::send($c->id, array_values(array_unique(array_filter($users))), 'rh.request', $title, $author->name.' : '.mb_strimwidth($text, 0, 180, '…'), 'rh', $r->id, $author->id);
        $to = $fromHr ? array_filter([self::emailOf($emp)]) : $this->hrEmails($c);
        $cc = array_filter(array_merge([self::emailOf($manager)], $fromHr ? $this->hrEmails($c) : [self::emailOf($emp)], $this->ccEmails($r)));
        $this->mail($c, $to, $cc, $r, 'Nouveau message de '.$author->name, $text);
    }

    /** Justificatif ajouté (certificat médical…) : envoyé en pièce jointe à la RH, responsable et copies en copie. */
    public function attached(EmployeeRequest $r, User $author, array $file, string $bytes): void
    {
        $c = app('tenant');
        $emp = $r->employee;
        $manager = $r->manager_id ? Employee::withTrashed()->find($r->manager_id) : null;
        $fromHr = $author->hasPermission('rh.manage') && $emp?->user_id !== $author->id;
        AppNotification::send($c->id, array_values(array_unique(array_filter(array_merge($fromHr ? [] : $this->hrUsers($c->id)->pluck('id')->all(), [$emp?->user_id, $manager?->user_id])))),
            'rh.request', 'Justificatif ajouté à la demande '.$r->number, $author->name.' : '.$file['name'], 'rh', $r->id, $author->id);
        $to = $fromHr ? array_filter([self::emailOf($emp)]) : $this->hrEmails($c);
        $cc = array_filter(array_merge([self::emailOf($manager)], $fromHr ? $this->hrEmails($c) : [self::emailOf($emp)], $this->ccEmails($r)));
        $this->mail($c, $to, $cc, $r, 'Justificatif ajouté par '.$author->name, 'Pièce jointe : '.$file['name'], [['name' => $file['name'], 'data' => $bytes, 'mime' => $file['mime']]]);
    }

    protected function summary(EmployeeRequest $r): string
    {
        $parts = [EmployeeRequest::TYPES[$r->type] ?? $r->type];
        if ($r->subject && $r->subject !== $parts[0]) {
            $parts[] = $r->subject;
        }
        if (isset(EmployeeRequest::LEAVE_TYPES[$r->type]) && $r->start_date) {
            $parts[] = (Leave::TYPES[$r->leave_type] ?? '').' du '.$r->start_date->format('d/m/Y').' au '.$r->end_date?->format('d/m/Y');
        }
        if ($r->amount) {
            $parts[] = number_format($r->amount, 0, ',', ' ').' '.(app('tenant')->currency ?? 'F CFA');
        }
        if ($r->effective_date) {
            $parts[] = 'à compter du '.$r->effective_date->format('d/m/Y');
        }

        return implode(' · ', array_filter($parts));
    }

    /** Envoi par le canal e-mail de la société ; sans canal configuré, la notification dans l'application suffit. */
    protected function mail(Company $c, array $to, array $cc, EmployeeRequest $r, string $intro, string $message, array $attachments = []): void
    {
        $to = array_values(array_unique(array_filter($to)));
        if (! $to || ! $this->messaging->usable($c, 'email')) {
            return;
        }
        $cc = array_values(array_diff(array_unique(array_filter($cc)), $to));
        $emp = $r->employee;
        $rows = [
            'Demande' => $r->number,
            'Salarié' => $emp?->full_name,
            'Type' => EmployeeRequest::TYPES[$r->type] ?? $r->type,
            'Objet' => $r->subject,
            'Statut' => EmployeeRequest::STATUSES[$r->status] ?? $r->status,
            'Détail' => $this->summary($r),
        ];
        $table = '';
        foreach (array_filter($rows) as $k => $v) {
            $table .= '<tr><td style="padding:4px 12px 4px 0;color:#64748B">'.e($k).'</td><td style="padding:4px 0;font-weight:600">'.e($v).'</td></tr>';
        }
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#0F172A">'
            .'<p style="font-size:16px;font-weight:bold">'.e($intro).'</p>'
            .'<p>'.nl2br(e($message)).'</p>'
            .'<table style="border-collapse:collapse;margin:12px 0">'.$table.'</table>'
            .($r->body ? '<p style="padding:12px;background:#F5F7FB;border-radius:8px">'.nl2br(e($r->body)).'</p>' : '')
            .($r->decision_note && in_array($r->status, ['approved', 'rejected'], true) ? '<p><b>Réponse du service RH :</b> '.nl2br(e($r->decision_note)).'</p>' : '')
            .'<p style="color:#64748B;font-size:12px">Suivez la demande dans votre espace Yomara ERP. Les personnes en copie : le responsable du salarié et le service RH.</p></div>';
        $subject = '['.$r->number.'] '.(EmployeeRequest::TYPES[$r->type] ?? 'Demande').' — '.($emp?->full_name ?? '');
        $first = array_shift($to);
        rescue(fn () => $this->messaging->send($c, 'email', new Message(to: $first, subject: $subject, html: $html, attachments: $attachments, cc: array_merge($to, $cc)), 'rh.request'), null, false);
    }
}
