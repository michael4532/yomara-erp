<?php

namespace App\Modules\Questionnaires;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Questionnaires : questions, réponses (lien public ou application), résultats, satisfaction et NPS. */
class QuestionnaireService
{
    public const KINDS = ['satisfaction' => 'Satisfaction client', 'nps' => 'Recommandation (NPS)', 'form' => 'Formulaire (inscription, demande…)', 'poll' => 'Sondage / vote',
        'evaluation' => 'Évaluation (formation, événement)', 'staff' => 'Enquête interne (salariés)'];

    public const STATUSES = ['draft' => ['Brouillon', '#64748B'], 'open' => ['Ouvert', '#16A34A'], 'closed' => ['Fermé', '#94A3B8']];

    public const MOODS = ['happy' => ['Satisfait', '#16A34A'], 'neutral' => ['Neutre', '#D97706'], 'unhappy' => ['Mécontent', '#DC2626']];

    public const SOURCES = ['link' => 'Lien / QR code', 'app' => 'Saisie dans l\'application'];

    public const TYPES = ['short' => 'Texte court', 'long' => 'Texte long', 'single' => 'Choix unique', 'multi' => 'Choix multiples', 'rating' => 'Note sur 5',
        'nps' => 'Recommandation de 0 à 10', 'yesno' => 'Oui / non', 'number' => 'Nombre', 'date' => 'Date', 'email' => 'E-mail', 'phone' => 'Téléphone'];

    /** Questions de départ selon le type de questionnaire. */
    public static function starter(string $kind): array
    {
        $q = fn ($type, $label, $req = false, $opts = []) => ['id' => Str::lower(Str::random(8)), 'type' => $type, 'label' => $label, 'required' => $req, 'options' => $opts];

        return match ($kind) {
            'satisfaction' => [$q('rating', 'Dans l\'ensemble, êtes-vous satisfait de notre service ?', true), $q('rating', 'Accueil et amabilité de l\'équipe'), $q('rating', 'Rapidité du service'),
                $q('rating', 'Qualité des produits ou de la prestation'), $q('nps', 'Recommanderiez-vous notre entreprise à un proche ?'), $q('long', 'Que pourrions-nous améliorer ?')],
            'nps' => [$q('nps', 'Quelle est la probabilité que vous recommandiez notre entreprise à un ami ou un collègue ?', true), $q('long', 'Quelle est la principale raison de votre note ?')],
            'evaluation' => [$q('rating', 'Note globale', true), $q('rating', 'Contenu'), $q('rating', 'Animation / intervenant'), $q('rating', 'Organisation et logistique'),
                $q('yesno', 'Recommanderiez-vous cette session ?'), $q('long', 'Commentaires')],
            'staff' => [$q('rating', 'Je me sens bien dans mon travail', true), $q('rating', 'J\'ai les moyens de bien faire mon travail'), $q('rating', 'Je suis informé de ce qui se passe dans l\'entreprise'),
                $q('nps', 'Recommanderiez-vous l\'entreprise comme employeur ?'), $q('long', 'Une idée pour améliorer notre quotidien ?')],
            'poll' => [$q('single', 'Votre choix', true, ['Option 1', 'Option 2', 'Option 3'])],
            default => [$q('short', 'Nom et prénom', true), $q('phone', 'Téléphone', true), $q('email', 'E-mail'), $q('long', 'Votre demande')],
        };
    }

    public static function questions(object|array $form): array
    {
        $q = is_array($form) ? ($form['questions'] ?? null) : $form->questions;

        return is_string($q) ? (json_decode($q, true) ?: []) : (array) $q;
    }

    public static function url(object|array $form): string
    {
        return url('/q/'.(is_array($form) ? $form['token'] : $form->token));
    }

    public static function isOpen(object $f): bool
    {
        return $f->status === 'open' && (! $f->closes_on || $f->closes_on >= now()->toDateString()) && (! $f->max_responses || $f->responses_count < $f->max_responses);
    }

    /** Valide et normalise la liste des questions. */
    public static function normalize(array $questions): array
    {
        $out = [];
        $ids = [];
        foreach (array_values($questions) as $i => $q) {
            $type = $q['type'] ?? '';
            if (! isset(self::TYPES[$type])) {
                throw new \DomainException('Question '.($i + 1).' : type inconnu.');
            }
            $label = trim((string) ($q['label'] ?? ''));
            if ($label === '') {
                throw new \DomainException('Question '.($i + 1).' : le libellé est obligatoire.');
            }
            $opts = array_values(array_unique(array_filter(array_map(fn ($o) => Str::limit(trim((string) $o), 150, ''), (array) ($q['options'] ?? [])), fn ($o) => $o !== '')));
            if (in_array($type, ['single', 'multi'], true) && count($opts) < 2) {
                throw new \DomainException('« '.Str::limit($label, 50).' » : indiquez au moins deux choix.');
            }
            $id = preg_match('/^[a-z0-9]{4,16}$/', (string) ($q['id'] ?? '')) && ! in_array($q['id'], $ids, true) ? $q['id'] : Str::lower(Str::random(8));
            $ids[] = $id;
            $out[] = ['id' => $id, 'type' => $type, 'label' => Str::limit($label, 300, ''), 'required' => (bool) ($q['required'] ?? false),
                'options' => in_array($type, ['single', 'multi'], true) ? $opts : [], 'help' => isset($q['help']) ? Str::limit(trim((string) $q['help']), 200, '') : null];
        }
        if (count($out) > 100) {
            throw new \DomainException('100 questions au maximum.');
        }

        return $out;
    }

    /** Contrôle les réponses d'un répondant ; renvoie [réponses propres, erreurs par question]. */
    public static function check(array $questions, array $input): array
    {
        $ans = [];
        $err = [];
        foreach ($questions as $q) {
            $v = $input[$q['id']] ?? null;
            if (is_string($v)) {
                $v = trim($v);
            }
            $empty = $v === null || $v === '' || $v === [];
            if ($empty) {
                if ($q['required']) {
                    $err[$q['id']] = 'Réponse obligatoire.';
                }

                continue;
            }
            $bad = match ($q['type']) {
                'single' => ! in_array($v, $q['options'], true),
                'multi' => ! is_array($v) || array_diff($v, $q['options']) !== [],
                'rating' => ! is_numeric($v) || (int) $v < 1 || (int) $v > 5,
                'nps' => ! is_numeric($v) || (int) $v < 0 || (int) $v > 10,
                'yesno' => ! in_array($v, ['oui', 'non', 'yes', 'no', true, false, 1, 0, '1', '0'], true),
                'number' => ! is_numeric(str_replace([' ', ','], ['', '.'], (string) $v)),
                'date' => strtotime((string) $v) === false,
                'email' => ! filter_var($v, FILTER_VALIDATE_EMAIL),
                'phone' => ! preg_match('/^[+0-9 ().-]{6,25}$/', (string) $v),
                default => ! is_string($v),
            };
            if ($bad) {
                $err[$q['id']] = 'Réponse invalide.';

                continue;
            }
            $ans[$q['id']] = match ($q['type']) {
                'rating', 'nps' => (int) $v,
                'number' => (float) str_replace([' ', ','], ['', '.'], (string) $v),
                'yesno' => in_array($v, ['oui', 'yes', true, 1, '1'], true) ? 'oui' : 'non',
                'date' => date('Y-m-d', strtotime((string) $v)),
                'multi' => array_values($v),
                'long' => Str::limit($v, 5000, ''),
                default => Str::limit((string) $v, 500, ''),
            };
        }

        return [$ans, $err];
    }

    /** Humeur du répondant : première note sur 5 ou première note NPS. */
    public static function mood(array $questions, array $ans): array
    {
        foreach ($questions as $q) {
            if (! isset($ans[$q['id']])) {
                continue;
            }
            if ($q['type'] === 'rating') {
                $v = $ans[$q['id']];

                return [$v, $v >= 4 ? 'happy' : ($v === 3 ? 'neutral' : 'unhappy')];
            }
            if ($q['type'] === 'nps') {
                $v = $ans[$q['id']];

                return [$v, $v >= 9 ? 'happy' : ($v >= 7 ? 'neutral' : 'unhappy')];
            }
        }

        return [null, null];
    }

    /** Enregistre une réponse (lien public ou saisie dans l'application). */
    public function store(object $form, array $input, array $contact, string $source, ?User $u = null, ?string $ip = null): array
    {
        $qs = self::questions($form);
        [$ans, $err] = self::check($qs, $input);
        if ($err) {
            throw new QuestionnaireErrors($err);
        }
        if (! $ans) {
            throw new \DomainException('Répondez au moins à une question.');
        }
        [$score, $mood] = self::mood($qs, $ans);
        $id = DB::table('qs_responses')->insertGetId([
            'company_id' => $form->company_id, 'created_by' => $u?->id, 'form_id' => $form->id, 'answers' => json_encode($ans, JSON_UNESCAPED_UNICODE),
            'name' => Str::limit(trim((string) ($contact['name'] ?? '')), 150, '') ?: null, 'email' => filter_var($contact['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null,
            'phone' => preg_match('/^[+0-9 ().-]{6,25}$/', (string) ($contact['phone'] ?? '')) ? $contact['phone'] : null, 'partner_id' => $contact['partner_id'] ?? null,
            'score' => $score, 'mood' => $mood, 'source' => $source, 'ip_hash' => $ip ? hash('sha256', $ip.'|'.$form->token) : null, 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('qs_forms')->where('id', $form->id)->increment('responses_count');
        if ($mood === 'unhappy' && $form->alert_unhappy && $form->owner_id) {
            $who = trim(($contact['name'] ?? '').(! empty($contact['phone']) ? ' ('.$contact['phone'].')' : '')) ?: 'Un répondant anonyme';
            AppNotification::send($form->company_id, [(int) $form->owner_id], 'questionnaires', 'Répondant mécontent : '.Str::limit($form->title, 60),
                $who.' a donné la note '.$score.'. Rappelez-le rapidement pour comprendre et corriger.', 'questionnaires', (string) $id);
        }
        if ($form->max_responses && $form->responses_count + 1 >= $form->max_responses) {
            DB::table('qs_forms')->where('id', $form->id)->update(['status' => 'closed']);
        }

        return (array) DB::table('qs_responses')->find($id);
    }

    /** Résultats question par question. */
    public function results(object $form, ?string $from = null, ?string $to = null): array
    {
        $qs = self::questions($form);
        $rows = DB::table('qs_responses')->where('form_id', $form->id)->when($from, fn ($q) => $q->where('submitted_at', '>=', $from))->when($to, fn ($q) => $q->where('submitted_at', '<=', $to.' 23:59:59'))
            ->orderByDesc('submitted_at')->orderByDesc('id')->get(['answers', 'submitted_at', 'mood', 'name']);
        $all = $rows->map(fn ($r) => json_decode($r->answers, true) ?: [])->all();
        $n = count($all);
        $out = [];
        foreach ($qs as $q) {
            $vals = array_values(array_filter(array_map(fn ($a) => $a[$q['id']] ?? null, $all), fn ($v) => $v !== null && $v !== ''));
            $r = ['id' => $q['id'], 'type' => $q['type'], 'label' => $q['label'], 'answered' => count($vals)];
            switch ($q['type']) {
                case 'single':
                case 'multi':
                case 'yesno':
                    $opts = $q['type'] === 'yesno' ? ['oui', 'non'] : $q['options'];
                    $counts = array_fill_keys($opts, 0);
                    foreach ($vals as $v) {
                        foreach ((array) $v as $o) {
                            if (array_key_exists($o, $counts)) {
                                $counts[$o]++;
                            }
                        }
                    }
                    $r['options'] = array_map(fn ($o) => ['label' => $o, 'count' => $counts[$o], 'pct' => count($vals) ? round($counts[$o] / count($vals) * 100) : 0], array_keys($counts));
                    break;
                case 'rating':
                    $dist = array_fill(1, 5, 0);
                    foreach ($vals as $v) {
                        $dist[(int) $v]++;
                    }
                    $r['average'] = $vals ? round(array_sum($vals) / count($vals), 2) : null;
                    $r['distribution'] = array_values($dist);
                    $r['satisfied'] = $vals ? round(($dist[4] + $dist[5]) / count($vals) * 100) : null;
                    break;
                case 'nps':
                    $dist = array_fill(0, 11, 0);
                    foreach ($vals as $v) {
                        $dist[(int) $v]++;
                    }
                    $k = count($vals);
                    $pro = $k ? array_sum(array_slice($dist, 9)) / $k * 100 : 0;
                    $det = $k ? array_sum(array_slice($dist, 0, 7)) / $k * 100 : 0;
                    $r['distribution'] = array_values($dist);
                    $r['promoters'] = round($pro);
                    $r['detractors'] = round($det);
                    $r['passives'] = $k ? 100 - round($pro) - round($det) : 0;
                    $r['nps'] = $k ? (int) round($pro - $det) : null;
                    break;
                case 'number':
                    $r['average'] = $vals ? round(array_sum($vals) / count($vals), 2) : null;
                    $r['min'] = $vals ? min($vals) : null;
                    $r['max'] = $vals ? max($vals) : null;
                    break;
                default:
                    $r['answers'] = array_slice($vals, 0, 50);
            }
            $out[] = $r;
        }
        $moods = ['happy' => 0, 'neutral' => 0, 'unhappy' => 0];
        foreach ($rows as $row) {
            if ($row->mood) {
                $moods[$row->mood]++;
            }
        }
        $days = [];
        for ($d = now()->subDays(29)->startOfDay(); $d->lte(now()); $d->addDay()) {
            $days[$d->toDateString()] = 0;
        }
        foreach ($rows as $row) {
            $k = substr((string) $row->submitted_at, 0, 10);
            if (isset($days[$k])) {
                $days[$k]++;
            }
        }

        return ['form' => ['id' => $form->id, 'title' => $form->title, 'status' => $form->status, 'url' => self::url($form)], 'responses' => $n, 'questions' => $out, 'moods' => $moods,
            'satisfaction' => array_sum($moods) ? round($moods['happy'] / array_sum($moods) * 100) : null, 'days' => array_map(fn ($k, $v) => ['date' => $k, 'count' => $v], array_keys($days), $days)];
    }

    // ------------------------------------------------------------------ fiches

    public function saving(string $res, array $data, ?array $old, User $u): array
    {
        if ($res === 'responses' && ! $old) {
            throw new \DomainException('Utilisez « Saisir une réponse » sur le questionnaire.');
        }
        if ($res === 'forms') {
            if (! $old) {
                $data['token'] = Str::random(24);
                $data['questions'] = json_encode(self::starter($data['kind'] ?? 'satisfaction'), JSON_UNESCAPED_UNICODE);
                $data['thank_you'] ??= 'Merci pour vos réponses ! Elles nous aident à nous améliorer.';
            }
            if (($data['status'] ?? null) === 'open' && ! self::questions($old ?? $data)) {
                throw new \DomainException('Ajoutez au moins une question avant d\'ouvrir le questionnaire.');
            }
        }

        return $data;
    }

    public function present(string $res, array $row): array
    {
        if ($res === 'forms') {
            $row['url'] = self::url($row);
            $row['questions_count'] = count(self::questions($row));
            unset($row['questions']);
            $m = DB::table('qs_responses')->where('form_id', $row['id'])->whereNotNull('mood')->selectRaw('mood, count(*) as n')->groupBy('mood')->pluck('n', 'mood');
            $t = array_sum($m->all());
            $row['summary'] = $t ? round(($m['happy'] ?? 0) / $t * 100).' % satisfaits'.(($m['unhappy'] ?? 0) ? ' · '.$m['unhappy'].' mécontent(s)' : '') : null;
        } elseif ($res === 'responses') {
            $form = DB::table('qs_forms')->where('id', $row['form_id'])->first();
            $ans = is_string($row['answers']) ? (json_decode($row['answers'], true) ?: []) : (array) $row['answers'];
            $lines = [];
            foreach ($form ? self::questions($form) : [] as $q) {
                if (isset($ans[$q['id']])) {
                    $v = $ans[$q['id']];
                    $lines[] = $q['label'].' : '.(is_array($v) ? implode(', ', $v) : ($q['type'] === 'rating' ? $v.'/5' : ($q['type'] === 'nps' ? $v.'/10' : $v)));
                }
            }
            $row['digest'] = implode("\n", $lines);
            unset($row['ip_hash']);
        }

        return $row;
    }

    public function deleting(string $res, array $row, User $u): void
    {
        if ($res === 'forms') {
            DB::table('qs_responses')->where('form_id', $row['id'])->delete();
        } elseif ($res === 'responses') {
            DB::table('qs_forms')->where('id', $row['form_id'])->where('responses_count', '>', 0)->decrement('responses_count');
        }
    }

    /** Ferme les questionnaires arrivés à leur date de fermeture. */
    public function closeExpired(Company $c): int
    {
        return DB::table('qs_forms')->where('company_id', $c->id)->where('status', 'open')->whereNotNull('closes_on')->where('closes_on', '<', now()->toDateString())->update(['status' => 'closed', 'updated_at' => now()]);
    }
}
