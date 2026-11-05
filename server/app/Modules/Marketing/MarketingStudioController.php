<?php

namespace App\Modules\Marketing;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Modules\Marketing\Models\Automation;
use App\Modules\Marketing\Models\AutomationRun;
use App\Modules\Marketing\Models\CampaignMessage;
use App\Modules\Marketing\Models\Contact;
use App\Modules\Marketing\Models\Form;
use App\Modules\Marketing\Models\MkList;
use App\Modules\Marketing\Models\Segment;
use App\Modules\Marketing\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Segments, formulaires et pages, automatisations, mises en page, fiche contact. */
class MarketingStudioController extends Controller
{
    protected function fail(string $m)
    {
        return response()->json(['message' => $m], 422);
    }

    // ---------- Mises en page et flux RSS ----------

    public function layouts()
    {
        return ['data' => EmailLayouts::all()];
    }

    public function rss(Request $r)
    {
        $d = $r->validate(['url' => 'required|url|max:500', 'count' => 'nullable|integer|min:1|max:10']);

        return ['data' => EmailRenderer::fetchRss($d['url'], $d['count'] ?? 3)];
    }

    // ---------- Fiche contact : activité ----------

    public function contact(int $id)
    {
        $c = Contact::with('lists:id,name')->findOrFail($id);
        $messages = CampaignMessage::where('contact_id', $c->id)->latest('id')->limit(50)->get()->map(fn ($m) => [
            'id' => $m->id, 'channel' => $m->channel, 'status' => $m->status, 'sent_at' => $m->sent_at, 'opened_at' => $m->opened_at, 'clicked_at' => $m->clicked_at,
            'opens' => $m->opens, 'clicks' => $m->clicks, 'unsubscribed_at' => $m->unsubscribed_at,
            'source' => $m->campaign_id ? ($m->campaign?->name ?? 'Campagne') : (Automation::find($m->automation_id)?->name ?? 'Automatisation'),
            'kind' => $m->campaign_id ? 'campaign' : 'automation',
        ]);
        $runs = AutomationRun::where('contact_id', $c->id)->latest('id')->limit(20)->get()->map(fn ($r) => [
            'automation' => $r->automation?->name, 'status' => $r->status, 'created_at' => $r->created_at, 'history' => $r->history,
        ]);

        return ['contact' => $c, 'messages' => $messages, 'automations' => $runs];
    }

    // ---------- Segments ----------

    protected function segmentRules(): array
    {
        return ['name' => 'required|string|max:120', 'match' => 'nullable|in:all,any', 'conditions' => 'nullable|array|max:20',
            'conditions.*.field' => ['required', Rule::in(array_keys(Segment::FIELDS))], 'conditions.*.value' => 'nullable'];
    }

    public function segmentFields()
    {
        return ['fields' => Segment::FIELDS, 'rfm' => Segment::RFM];
    }

    /** Nombre de contacts correspondant à des conditions (aperçu en direct). */
    public function segmentCount(Request $r)
    {
        $d = $r->validate(['match' => 'nullable|in:all,any', 'conditions' => 'nullable|array', 'conditions.*.field' => ['required', Rule::in(array_keys(Segment::FIELDS))]]);
        $q = Segment::apply(Contact::query(), $d['match'] ?? 'all', $r->input('conditions', []));

        return ['count' => (clone $q)->count(), 'sample' => $q->limit(5)->get(['id', 'first_name', 'last_name', 'company_name', 'email', 'phone'])];
    }

    public function storeSegment(Request $r)
    {
        $r->validate($this->segmentRules());

        return response()->json(Segment::create(['name' => $r->input('name'), 'match' => $r->input('match', 'all'), 'conditions' => $r->input('conditions', [])]), 201);
    }

    public function updateSegment(Request $r, int $id)
    {
        $s = Segment::findOrFail($id);
        $r->validate($this->segmentRules());
        $s->update(['name' => $r->input('name'), 'match' => $r->input('match', 'all'), 'conditions' => $r->input('conditions', [])]);

        return $s;
    }

    public function destroySegment(int $id)
    {
        Segment::findOrFail($id)->delete();

        return ['ok' => true];
    }

    // ---------- Formulaires et pages ----------

    public function forms()
    {
        return ['data' => Form::latest('id')->get(), 'fields' => Form::FIELDS];
    }

    protected function formData(Request $r, bool $create): array
    {
        $d = $r->validate([
            'kind' => [$create ? 'required' : 'sometimes', 'in:form,page'], 'name' => [$create ? 'required' : 'sometimes', 'string', 'max:190'],
            'slug' => 'nullable|string|max:60|regex:/^[a-z0-9-]+$/',
            'list_id' => ['nullable', 'integer', Rule::exists('mk_lists', 'id')->where('company_id', app('tenant')->id)],
            'title' => 'nullable|string|max:190', 'description' => 'nullable|string|max:2000', 'fields' => 'nullable|array|max:7',
            'fields.*.key' => ['required', Rule::in(array_keys(Form::FIELDS))], 'fields.*.required' => 'nullable|boolean',
            'button_label' => 'nullable|string|max:60', 'double_optin' => 'nullable|boolean', 'consent_text' => 'nullable|string|max:1000',
            'success_message' => 'nullable|string|max:1000', 'redirect_url' => 'nullable|url|max:500', 'blocks' => 'nullable|array|max:80',
            'blocks.*.type' => ['required', Rule::in(EmailRenderer::BLOCK_TYPES)], 'style' => 'nullable|array', 'with_form' => 'nullable|boolean', 'active' => 'nullable|boolean',
            'layout' => 'nullable|string|max:40',
        ]);
        foreach (['blocks', 'style', 'fields'] as $k) {
            if ($r->has($k)) {
                $d[$k] = $r->input($k);
            }
        }
        if (! empty($d['layout']) && ($l = EmailLayouts::find($d['layout']))) {
            $d += ['blocks' => $l['blocks'], 'style' => $l['style']];
        }
        unset($d['layout']);
        if (! empty($d['slug']) && Form::withoutGlobalScopes()->where('slug', $d['slug'])->when(! $create, fn ($q) => $q->where('id', '!=', $r->route('id')))->exists()) {
            abort(response()->json(['message' => 'Cette adresse est déjà utilisée.'], 422));
        }

        return $d;
    }

    public function storeForm(Request $r)
    {
        $d = $this->formData($r, true);
        if (empty($d['list_id'])) {
            $d['list_id'] = MkList::firstOrCreate(['name' => 'Inscrits'], ['description' => 'Inscriptions par formulaire'])->id;
        }
        $d += ['consent_text' => 'J\'accepte de recevoir les informations de '.app('tenant')->name.' et je peux me désinscrire à tout moment.'];

        return response()->json(Form::create($d), 201);
    }

    public function updateForm(Request $r, int $id)
    {
        $f = Form::findOrFail($id);
        $f->update($this->formData($r, false));

        return $f;
    }

    public function destroyForm(int $id)
    {
        Form::findOrFail($id)->delete();

        return ['ok' => true];
    }

    // ---------- Automatisations ----------

    public function automations()
    {
        return [
            'data' => Automation::latest('id')->get()->map(fn ($a) => $a->toArray() + ['trigger_label' => Automation::TRIGGERS[$a->trigger] ?? $a->trigger]),
            'triggers' => Automation::TRIGGERS, 'steps' => Automation::STEPS, 'conditions' => Automation::CONDITIONS,
            'recipes' => self::recipes(),
        ];
    }

    public function automation(int $id)
    {
        $a = Automation::findOrFail($id);
        $a->refreshStats();
        $runs = AutomationRun::where('automation_id', $a->id)->with('contact:id,first_name,last_name,email,phone,company_name')->latest('id')->limit(100)->get();
        // Passages par étape
        $perStep = [];
        foreach (CampaignMessage::where('automation_id', $a->id)->selectRaw('step_id, COUNT(*) as sent, SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened, SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked')->groupBy('step_id')->get() as $row) {
            $perStep[$row->step_id] = ['sent' => (int) $row->sent, 'opened' => (int) $row->opened, 'clicked' => (int) $row->clicked];
        }

        return $a->toArray() + ['runs' => $runs, 'per_step' => $perStep, 'trigger_label' => Automation::TRIGGERS[$a->trigger] ?? $a->trigger];
    }

    protected function automationData(Request $r, bool $create): array
    {
        $d = $r->validate([
            'name' => [$create ? 'required' : 'sometimes', 'string', 'max:190'],
            'trigger' => [$create ? 'required' : 'sometimes', Rule::in(array_keys(Automation::TRIGGERS))],
            'trigger_config' => 'nullable|array', 'steps' => 'nullable|array|max:60', 'allow_reentry' => 'nullable|boolean', 'recipe' => 'nullable|string|max:40',
        ]);
        foreach (['trigger_config', 'steps'] as $k) {
            if ($r->has($k)) {
                $d[$k] = $r->input($k);
            }
        }
        if (isset($d['steps'])) {
            $d['steps'] = $this->normalizeSteps((array) $d['steps']);
        }

        return $d;
    }

    /** Vérifie les types et donne un identifiant stable à chaque étape (pour les statistiques). */
    protected function normalizeSteps(array $steps, int $depth = 0): array
    {
        abort_if($depth > 4, 422, 'Trop de conditions imbriquées.');
        $out = [];
        foreach ($steps as $s) {
            if (! is_array($s) || ! isset(Automation::STEPS[$s['type'] ?? ''])) {
                continue;
            }
            $s['id'] = (string) ($s['id'] ?? '') ?: \Illuminate\Support\Str::random(8);
            $s['config'] = (array) ($s['config'] ?? []);
            if ($s['type'] === 'condition') {
                $s['yes'] = $this->normalizeSteps((array) ($s['yes'] ?? []), $depth + 1);
                $s['no'] = $this->normalizeSteps((array) ($s['no'] ?? []), $depth + 1);
            } else {
                unset($s['yes'], $s['no']);
            }
            $out[] = $s;
        }

        return $out;
    }

    public function storeAutomation(Request $r)
    {
        $d = $this->automationData($r, true);
        if (! empty($d['recipe']) && ($rec = collect(self::recipes())->firstWhere('key', $d['recipe']))) {
            $d += ['steps' => $this->normalizeSteps($this->materialize($rec['steps']))];
            $d['trigger_config'] ??= $rec['trigger_config'] ?? [];
        }
        unset($d['recipe']);

        return response()->json(Automation::create($d + ['status' => 'draft', 'user_id' => $r->user()->id, 'steps' => []]), 201);
    }

    public function updateAutomation(Request $r, int $id)
    {
        $a = Automation::findOrFail($id);
        $a->update($this->automationData($r, false));

        return $this->automation($id);
    }

    public function setAutomationStatus(Request $r, int $id)
    {
        $a = Automation::findOrFail($id);
        $d = $r->validate(['status' => 'required|in:active,paused,draft']);
        if ($d['status'] === 'active') {
            if (empty($a->steps)) {
                return $this->fail('Ajoutez au moins une étape.');
            }
            $cfg = (array) $a->trigger_config;
            if ($a->trigger === 'list_join' && empty($cfg['list_id'])) {
                return $this->fail('Choisissez la liste qui déclenche le scénario.');
            }
            if ($a->trigger === 'tag' && empty($cfg['tag'])) {
                return $this->fail('Indiquez l\'étiquette qui déclenche le scénario.');
            }
            if ($problem = $this->checkSteps((array) $a->steps)) {
                return $this->fail($problem);
            }
        }
        $a->update(['status' => $d['status']]);
        ActivityLog::record('marketing.automation.'.$d['status'], $a);

        return $this->automation($id);
    }

    protected function checkSteps(array $steps): ?string
    {
        foreach ($steps as $s) {
            $c = (array) ($s['config'] ?? []);
            $label = Automation::STEPS[$s['type']] ?? $s['type'];
            $missing = match ($s['type']) {
                'email' => empty($c['template_id']) && empty($c['blocks']) && empty($c['html']),
                'sms', 'whatsapp' => empty($c['template_id']) && trim((string) ($c['text'] ?? '')) === '',
                'add_list', 'remove_list' => empty($c['list_id']),
                'add_tag', 'remove_tag' => trim((string) ($c['tag'] ?? '')) === '',
                'condition' => empty($c['check']),
                default => false,
            };
            if ($missing) {
                return 'Étape « '.$label.' » incomplète.';
            }
            if ($s['type'] === 'condition' && ($p = $this->checkSteps((array) ($s['yes'] ?? [])) ?? $this->checkSteps((array) ($s['no'] ?? [])))) {
                return $p;
            }
        }

        return null;
    }

    /** Inscription manuelle de contacts (déclencheur « Ajout manuel » ou test). */
    public function enroll(Request $r, int $id, AutomationEngine $engine)
    {
        $a = Automation::findOrFail($id);
        $d = $r->validate(['contact_ids' => 'nullable|array', 'contact_ids.*' => 'integer', 'list_id' => 'nullable|integer', 'segment_id' => 'nullable|integer']);
        if ($a->status !== 'active') {
            return $this->fail('Activez d\'abord le scénario.');
        }
        $q = Contact::query();
        if (! empty($d['list_id'])) {
            $q->whereHas('lists', fn ($l) => $l->where('mk_lists.id', $d['list_id']));
        } elseif (! empty($d['segment_id'])) {
            $seg = Segment::findOrFail($d['segment_id']);
            Segment::apply($q, $seg->match, (array) $seg->conditions);
        } else {
            $q->whereIn('id', $d['contact_ids'] ?? [0]);
        }
        $n = 0;
        foreach ($q->limit(5000)->get() as $c) {
            if ($engine->enroll($a, $c)) {
                $n++;
            }
        }
        $engine->tick(app('tenant'), 100);

        return ['enrolled' => $n] + $this->automation($id);
    }

    public function destroyAutomation(int $id)
    {
        $a = Automation::findOrFail($id);
        AutomationRun::where('automation_id', $a->id)->delete();
        $a->delete();

        return ['ok' => true];
    }

    /** Remplace les références de modèle « layout:clé » par un modèle créé pour l'occasion. */
    protected function materialize(array $steps): array
    {
        foreach ($steps as &$s) {
            if (($s['type'] ?? '') === 'email' && ! empty($s['config']['layout'])) {
                $l = EmailLayouts::find($s['config']['layout']);
                if ($l) {
                    $t = Template::create(['name' => $l['name'].' (automatisation)', 'channel' => 'email', 'subject' => $s['config']['subject'] ?? $l['subject'], 'preheader' => $l['preheader'], 'blocks' => $l['blocks'], 'style' => $l['style']]);
                    $s['config'] = ['template_id' => $t->id];
                }
            }
            foreach (['yes', 'no'] as $b) {
                if (! empty($s[$b])) {
                    $s[$b] = $this->materialize($s[$b]);
                }
            }
        }

        return $steps;
    }

    /** Scénarios prêts à l'emploi. */
    public static function recipes(): array
    {
        return [
            ['key' => 'welcome', 'name' => 'Série de bienvenue', 'trigger' => 'form', 'description' => 'E-mail de bienvenue, puis relance avec code promo à J+3 si l\'e-mail n\'a pas été ouvert.', 'steps' => [
                ['type' => 'email', 'config' => ['layout' => 'welcome']],
                ['type' => 'wait', 'config' => ['amount' => 3, 'unit' => 'days']],
                ['type' => 'condition', 'config' => ['check' => 'opened'], 'yes' => [['type' => 'add_tag', 'config' => ['tag' => 'engagé']]], 'no' => [
                    ['type' => 'email', 'config' => ['layout' => 'reminder', 'subject' => '{{prenom}}, votre cadeau de bienvenue vous attend']],
                ]],
            ]],
            ['key' => 'birthday', 'name' => 'Anniversaire', 'trigger' => 'birthday', 'trigger_config' => ['days_before' => 0], 'description' => 'Vœux et code cadeau le jour de l\'anniversaire, avec un WhatsApp si le contact a un numéro.', 'steps' => [
                ['type' => 'email', 'config' => ['layout' => 'birthday']],
                ['type' => 'condition', 'config' => ['check' => 'has_phone'], 'yes' => [['type' => 'whatsapp', 'config' => ['text' => "Joyeux anniversaire {{prenom}} 🎉\nToute l'équipe de {{entreprise}} vous offre -20 % cette semaine avec le code ANNIV20."]]], 'no' => []],
            ]],
            ['key' => 'lead_nurture', 'name' => 'Prospect → opportunité CRM', 'trigger' => 'list_join', 'description' => 'Présente votre offre ; si le contact clique, une opportunité est créée dans le CRM et l\'équipe est prévenue.', 'steps' => [
                ['type' => 'email', 'config' => ['layout' => 'proposal']],
                ['type' => 'wait', 'config' => ['amount' => 2, 'unit' => 'days']],
                ['type' => 'condition', 'config' => ['check' => 'clicked'], 'yes' => [
                    ['type' => 'crm_lead', 'config' => ['title' => 'Intéressé par l\'offre — {{nom_complet}}']],
                    ['type' => 'notify', 'config' => ['message' => '{{nom_complet}} a cliqué sur la proposition : à rappeler.']],
                ], 'no' => [
                    ['type' => 'wait', 'config' => ['amount' => 3, 'unit' => 'days']],
                    ['type' => 'email', 'config' => ['layout' => 'reminder']],
                ]],
            ]],
            ['key' => 'reengage', 'name' => 'Réactivation des inactifs', 'trigger' => 'manual', 'description' => 'À lancer sur un segment « inactifs » : offre spéciale, puis retrait de la liste s\'il n\'y a toujours aucune ouverture.', 'steps' => [
                ['type' => 'email', 'config' => ['layout' => 'promo', 'subject' => '{{prenom}}, vous nous manquez']],
                ['type' => 'wait', 'config' => ['amount' => 7, 'unit' => 'days']],
                ['type' => 'condition', 'config' => ['check' => 'opened'], 'yes' => [['type' => 'add_tag', 'config' => ['tag' => 'réactivé']]], 'no' => [['type' => 'add_tag', 'config' => ['tag' => 'inactif']]]],
            ]],
        ];
    }
}
