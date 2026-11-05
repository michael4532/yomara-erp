<?php

namespace App\Modules\TableauxExcel;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\Company;
use App\Models\User;
use App\Modules\TableauxExcel\Models\Workbook;
use App\Support\BranchScope;
use Illuminate\Support\Str;

class TableauxExcelService
{
    public const FREQUENCIES = ['none' => 'Pas d\'envoi automatique', 'weekly' => 'Chaque lundi', 'monthly' => 'Le 1er de chaque mois'];

    /** Génère le classeur et renvoie le chemin du fichier temporaire. */
    public function generate(Company $c, array $o): string
    {
        $tpl = $o['template'] ?? '';
        if (! array_key_exists($tpl, Templates::available($c))) {
            throw new \DomainException('Ce modèle n\'est pas disponible avec vos modules.');
        }
        if (! array_key_exists($o['theme'] ?? 'ocean', XlsxDashboard::THEMES)) {
            $o['theme'] = 'ocean';
        }
        $branch = $o['branch_id'] ?? null;
        $run = function () use ($c, $o) {
            $spec = app(Templates::class)->spec($c, $o);
            $path = tempnam(sys_get_temp_dir(), 'xl').'.xlsx';
            app(XlsxDashboard::class)->build($spec, $path);

            return $path;
        };

        return $branch && BranchScope::canUse($branch) ? BranchScope::withFilter($branch, $run) : $run();
    }

    public static function filename(string $name): string
    {
        return (Str::slug($name) ?: 'tableau-de-bord').'-'.now()->format('Y-m-d').'.xlsx';
    }

    public function options(Workbook $w): array
    {
        return ['template' => $w->template, 'theme' => $w->theme, 'year' => $w->year, 'branch_id' => $w->branch_id, 'title' => $w->name, 'protect' => $w->protect];
    }

    /** Envois programmés : le lundi (hebdomadaire) ou le 1er du mois (mensuel), une fois par jour au plus. */
    public function runDue(Company $c): array
    {
        $t = ['sent' => 0, 'failed' => 0];
        $today = now();
        foreach (Workbook::where('frequency', '!=', 'none')->get() as $w) {
            $due = ($w->frequency === 'weekly' && $today->isMonday()) || ($w->frequency === 'monthly' && $today->day === 1);
            if (! $due || ($w->last_sent_at && $w->last_sent_at->isSameDay($today))) {
                continue;
            }
            $this->send($c, $w) ? $t['sent']++ : $t['failed']++;
        }

        return $t;
    }

    public function send(Company $c, Workbook $w): bool
    {
        $fail = function (string $msg) use ($w) {
            $w->forceFill(['last_status' => 'failed', 'last_error' => mb_substr($msg, 0, 250)])->save();

            return false;
        };
        $owner = User::where('company_id', $c->id)->find($w->owner_id);
        if (! $owner || ! $owner->is_active) {
            return $fail('L\'auteur de ce tableau n\'a plus accès à l\'application.');
        }
        $messaging = app(MessagingService::class);
        if (! ($messaging->available($c)['email'] ?? null)) {
            return $fail('Aucun serveur d\'e-mail configuré (Paramètres › Messagerie).');
        }
        if (! $w->recipients) {
            return $fail('Aucun destinataire.');
        }
        // Les chiffres sont ceux que l'auteur a le droit de voir (ses agences)
        $hadScope = app()->bound('branch_scope') ? app('branch_scope') : null;
        app()->instance('branch_scope', BranchScope::allowedFor($owner, $c));
        try {
            $path = $this->generate($c, $this->options($w));
        } catch (\DomainException $e) {
            return $fail($e->getMessage());
        } finally {
            app()->instance('branch_scope', $hadScope);
        }
        $attach = ['data' => file_get_contents($path), 'name' => self::filename($w->name), 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        @unlink($path);
        $intro = $w->message ? '<p>'.nl2br(e($w->message)).'</p>' : '<p>Bonjour,</p><p>Voici le tableau de bord Excel « '.e($w->name).' », à jour au '.now()->format('d/m/Y').'.</p>';
        $html = '<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;line-height:1.5;color:#1d2433">'.$intro
            .'<p>Ouvrez le fichier joint dans Excel : les listes déroulantes en haut du tableau de bord filtrent les indicateurs et les graphiques.</p>'
            .'<p style="color:#5B6B85;font-size:11px;margin-top:22px">Envoi programmé par '.e($owner->name).' depuis Yomara ERP — '.e($c->name).'.</p></div>';
        $errors = [];
        foreach ($w->recipients as $to) {
            try {
                $res = $messaging->send($c, 'email', new Message(to: $to, subject: $w->name.' — '.$c->name, html: $html, attachments: [$attach]), 'tableau_excel', $owner->id);
                if (! $res->ok) {
                    $errors[] = $to.' : '.($res->error ?? 'échec');
                }
            } catch (\Throwable $e) {
                $errors[] = $to.' : '.$e->getMessage();
            }
        }
        if (count($errors) === count($w->recipients)) {
            return $fail(implode(' ; ', $errors));
        }
        $w->forceFill(['last_status' => $errors ? 'partial' : 'sent', 'last_error' => $errors ? mb_substr(implode(' ; ', $errors), 0, 250) : null, 'last_sent_at' => now()])->save();

        return true;
    }
}
