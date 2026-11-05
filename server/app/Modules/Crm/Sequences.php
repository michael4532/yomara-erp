<?php

namespace App\Modules\Crm;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Relances automatiques d'un prospect : une suite d'étapes (e-mail, SMS, WhatsApp, tâche pour le commercial)
 * espacées de quelques jours. Elles s'arrêtent quand l'affaire est gagnée ou perdue, ou à la demande.
 */
class Sequences
{
    public const CHANNELS = ['email' => 'E-mail', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'task' => 'Tâche pour le commercial', 'call' => 'Appel à passer'];

    public const VARS = ['prenom', 'nom', 'societe', 'entreprise', 'commercial', 'telephone_commercial', 'titre'];

    public function __construct(protected MessagingService $messaging) {}

    public static function validateSteps(array $steps): array
    {
        if (! $steps) {
            throw new \DomainException('Ajoutez au moins une étape.');
        }
        if (count($steps) > 20) {
            throw new \DomainException('20 étapes au maximum.');
        }
        $out = [];
        foreach (array_values($steps) as $i => $s) {
            $ch = (string) ($s['channel'] ?? '');
            if (! isset(self::CHANNELS[$ch])) {
                throw new \DomainException('Étape '.($i + 1).' : canal inconnu.');
            }
            $text = trim((string) ($s['text'] ?? ''));
            if ($text === '') {
                throw new \DomainException('Étape '.($i + 1).' : écrivez le '.(in_array($ch, ['task', 'call'], true) ? 'libellé de la tâche' : 'message').'.');
            }
            if ($ch === 'email' && trim((string) ($s['subject'] ?? '')) === '') {
                throw new \DomainException('Étape '.($i + 1).' : indiquez l\'objet de l\'e-mail.');
            }
            $out[] = ['channel' => $ch, 'delay_days' => max(0, min(365, (int) ($s['delay_days'] ?? 0))), 'hour' => isset($s['hour']) && $s['hour'] !== null && $s['hour'] !== '' ? max(0, min(23, (int) $s['hour'])) : null,
                'subject' => $ch === 'email' ? Str::limit(trim((string) $s['subject']), 190, '') : null, 'text' => Str::limit($text, 4000, '')];
        }

        return $out;
    }

    protected static function at(array $step, ?\Illuminate\Support\Carbon $from = null): \Illuminate\Support\Carbon
    {
        $t = ($from ?? now())->copy()->addDays($step['delay_days']);
        if ($step['hour'] !== null) {
            $t->setTime($step['hour'], 0);
            if ($t->lt(now())) {
                $t = now();
            }
        }

        return $t;
    }

    public function enroll(Company $c, Lead $lead, int $sequenceId, ?User $by = null): object
    {
        $seq = DB::table('crm_sequences')->where('company_id', $c->id)->where('id', $sequenceId)->first() ?: throw new \DomainException('Relance introuvable.');
        if (! $seq->active) {
            throw new \DomainException('Cette relance automatique est désactivée.');
        }
        if ($lead->status !== 'open') {
            throw new \DomainException('L\'affaire est déjà close.');
        }
        if (DB::table('crm_sequence_runs')->where('lead_id', $lead->id)->where('status', 'active')->exists()) {
            throw new \DomainException('Ce prospect suit déjà une relance automatique : arrêtez-la d\'abord.');
        }
        $steps = json_decode($seq->steps, true) ?: [];
        $id = DB::table('crm_sequence_runs')->insertGetId(['company_id' => $c->id, 'sequence_id' => $seq->id, 'lead_id' => $lead->id, 'step' => 0,
            'next_at' => self::at($steps[0]), 'status' => 'active', 'history' => json_encode([]), 'started_by' => $by?->id, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('crm_sequence_runs')->find($id);
    }

    public static function stopFor(int $companyId, string $leadId, string $why): void
    {
        foreach (DB::table('crm_sequence_runs')->where('company_id', $companyId)->where('lead_id', $leadId)->where('status', 'active')->get() as $r) {
            $h = json_decode((string) $r->history, true) ?: [];
            $h[] = ['at' => now()->toDateTimeString(), 'label' => 'Arrêtée : '.$why];
            DB::table('crm_sequence_runs')->where('id', $r->id)->update(['status' => 'stopped', 'next_at' => null, 'history' => json_encode($h, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        }
    }

    protected function vars(Company $c, Lead $l): array
    {
        $owner = $l->owner_id ? User::find($l->owner_id) : null;
        $name = trim((string) ($l->contact_name ?: $l->company_name));
        [$first] = explode(' ', $name.' ');

        return ['prenom' => $first ?: $name, 'nom' => $name, 'societe' => (string) $l->company_name, 'entreprise' => $c->name, 'commercial' => $owner?->name ?? $c->name,
            'telephone_commercial' => (string) ($owner?->phone ?? $c->phone), 'titre' => (string) $l->title];
    }

    public static function fill(string $text, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $text);
    }

    /** Étapes arrivées à échéance. */
    public function tick(Company $c, int $limit = 200): int
    {
        $n = 0;
        foreach (DB::table('crm_sequence_runs')->where('company_id', $c->id)->where('status', 'active')->where('next_at', '<=', now())->orderBy('next_at')->limit($limit)->get() as $run) {
            // Réservation de l'étape : deux passages simultanés n'envoient jamais le même message deux fois
            if (! DB::table('crm_sequence_runs')->where('id', $run->id)->where('status', 'active')->where('next_at', $run->next_at)->update(['next_at' => now()->addMinutes(15)])) {
                continue;
            }
            $this->advance($c, $run);
            $n++;
        }

        return $n;
    }

    protected function advance(Company $c, object $run): void
    {
        $seq = DB::table('crm_sequences')->find($run->sequence_id);
        $lead = Lead::withoutGlobalScopes()->where('company_id', $c->id)->find($run->lead_id);
        $h = json_decode((string) $run->history, true) ?: [];
        $end = function (string $status, string $label) use ($run, &$h) {
            $h[] = ['at' => now()->toDateTimeString(), 'label' => $label];
            DB::table('crm_sequence_runs')->where('id', $run->id)->update(['status' => $status, 'next_at' => null, 'history' => json_encode($h, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        };
        if (! $seq || ! $lead) {
            $end('stopped', 'Arrêtée : relance ou prospect supprimé');

            return;
        }
        if ($lead->status !== 'open') {
            $end('stopped', 'Arrêtée : affaire close');

            return;
        }
        $steps = json_decode($seq->steps, true) ?: [];
        $step = $steps[$run->step] ?? null;
        if (! $step) {
            $end('done', 'Terminée');

            return;
        }
        $vars = $this->vars($c, $lead);
        $text = self::fill($step['text'], $vars);
        $label = 'Étape '.($run->step + 1).' ('.self::CHANNELS[$step['channel']].') : ';
        try {
            if (in_array($step['channel'], ['task', 'call'], true)) {
                (new Activity)->forceFill(['company_id' => $c->id, 'lead_id' => $lead->id, 'type' => $step['channel'] === 'call' ? 'call' : 'task', 'subject' => Str::limit($text, 250, ''),
                    'due_at' => now(), 'owner_id' => $lead->owner_id, 'done' => false])->save();
                if ($lead->owner_id) {
                    AppNotification::send($c->id, [$lead->owner_id], 'crm', 'Relance : '.Str::limit($lead->title, 60), Str::limit($text, 200), 'crm', (string) $lead->id);
                }
                $label .= 'tâche créée';
            } else {
                $to = $step['channel'] === 'email' ? $lead->email : $lead->phone;
                if (! $to) {
                    $label .= 'non envoyé ('.($step['channel'] === 'email' ? 'pas d\'e-mail' : 'pas de téléphone').')';
                } else {
                    $m = $step['channel'] === 'email'
                        ? new Message(to: $to, toName: $lead->contact_name, subject: self::fill((string) $step['subject'], $vars), html: nl2br(e($text)), text: $text, replyTo: User::find($lead->owner_id)?->email ?? $c->email)
                        : new Message(to: $to, toName: $lead->contact_name, text: $text);
                    $res = $this->messaging->send($c, $step['channel'], $m, 'crm-sequence:'.$seq->id, $lead->owner_id);
                    $label .= $res->ok ? 'envoyé' : 'échec ('.Str::limit((string) $res->error, 100).')';
                    if ($res->ok) {
                        (new Activity)->forceFill(['company_id' => $c->id, 'lead_id' => $lead->id, 'type' => ['email' => 'email', 'whatsapp' => 'whatsapp'][$step['channel']] ?? 'task',
                            'subject' => 'Relance automatique : '.Str::limit($step['channel'] === 'email' ? self::fill((string) $step['subject'], $vars) : $text, 200, ''), 'due_at' => now(), 'done' => true, 'done_at' => now(), 'owner_id' => $lead->owner_id])->save();
                    }
                }
            }
        } catch (\Throwable $e) {
            $label .= 'échec ('.Str::limit($e->getMessage(), 100).')';
        }
        $h[] = ['at' => now()->toDateTimeString(), 'label' => $label];
        $nextIdx = $run->step + 1;
        if (! isset($steps[$nextIdx])) {
            $end('done', 'Terminée');

            return;
        }
        DB::table('crm_sequence_runs')->where('id', $run->id)->update(['step' => $nextIdx, 'next_at' => self::at($steps[$nextIdx]), 'history' => json_encode($h, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
    }
}
