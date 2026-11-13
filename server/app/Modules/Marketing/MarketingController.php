<?php

namespace App\Modules\Marketing;

use App\Http\Controllers\Controller;
use App\Messaging\MessagingService;
use App\Messaging\Phone;
use App\Models\ActivityLog;
use App\Modules\Core\Models\Partner;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignMessage;
use App\Modules\Marketing\Models\Contact;
use App\Modules\Marketing\Models\MkList;
use App\Modules\Marketing\Models\Template;
use App\Modules\Marketing\Models\Segment;
use App\Modules\Marketing\Models\Form;
use App\Modules\Marketing\Models\Automation;
use App\Modules\Marketing\Models\Click;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MarketingController extends Controller
{
    public function __construct(protected CampaignService $campaigns, protected MessagingService $messaging) {}

    protected function fail(string $m)
    {
        return response()->json(['message' => $m], 422);
    }

    public function overview()
    {
        $company = app('tenant');

        return [
            'lists' => MkList::withCount('contacts')->orderBy('name')->get(),
            'segments' => Segment::orderBy('name')->get(),
            'forms_count' => Form::count(),
            'automations_active' => Automation::where('status', 'active')->count(),
            'templates' => Template::orderBy('channel')->orderBy('name')->get(),
            'campaigns' => Campaign::latest('id')->limit(200)->get()->map(fn ($c) => $c->only(['id', 'name', 'kind', 'channel', 'subject', 'status', 'scheduled_at', 'sent_at', 'stats', 'list_ids', 'created_at'])
                + ['status_label' => Campaign::STATUSES[$c->status] ?? $c->status, 'kind_label' => Campaign::KINDS[$c->kind] ?? $c->kind]),
            'contacts_total' => Contact::count(),
            'channels' => $this->messaging->available($company),
            'kinds' => Campaign::KINDS,
            'block_types' => EmailRenderer::BLOCK_TYPES,
            'variables' => ['prenom', 'nom', 'nom_complet', 'societe', 'email', 'telephone', 'ville', 'entreprise', 'lien_desinscription'],
            'has_crm' => $company->hasModule('crm'),
        ];
    }

    // ---------- Listes et contacts ----------

    public function storeList(Request $r)
    {
        return response()->json(MkList::create($r->validate(['name' => 'required|string|max:120', 'description' => 'nullable|string|max:255'])), 201);
    }

    public function updateList(Request $r, int $id)
    {
        $l = MkList::findOrFail($id);
        $l->update($r->validate(['name' => 'sometimes|string|max:120', 'description' => 'nullable|string|max:255']));

        return $l;
    }

    public function destroyList(int $id)
    {
        $l = MkList::findOrFail($id);
        $l->contacts()->detach();
        $l->delete();

        return ['ok' => true];
    }

    public function contacts(Request $r)
    {
        $q = Contact::query()->with('lists:id,name')->orderBy('last_name')->orderBy('first_name');
        if ($l = $r->query('list_id')) {
            $q->whereHas('lists', fn ($w) => $w->where('mk_lists.id', $l));
        }
        if ($s = $r->query('q')) {
            $q->where(fn ($w) => $w->where('first_name', 'like', "%$s%")->orWhere('last_name', 'like', "%$s%")->orWhere('email', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")->orWhere('company_name', 'like', "%$s%"));
        }

        if ($sid = $r->query('segment_id')) {
            $seg = Segment::findOrFail($sid);
            Segment::apply($q, $seg->match, (array) $seg->conditions);
        }
        if ($r->query('sort') === 'score') {
            $q->reorder('score', 'desc');
        }

        return ['data' => $q->limit(1000)->get(), 'total' => (clone $q)->count()];
    }

    protected function contactRules(): array
    {
        return [
            'first_name' => 'nullable|string|max:120', 'last_name' => 'nullable|string|max:120', 'company_name' => 'nullable|string|max:190',
            'email' => 'nullable|email|max:190', 'phone' => 'nullable|string|max:40', 'tags' => 'nullable|string|max:190',
            'list_ids' => 'nullable|array', 'list_ids.*' => ['integer', Rule::exists('mk_lists', 'id')->where('company_id', app('tenant')->id)],
            'unsubscribed_email' => 'nullable|boolean', 'unsubscribed_sms' => 'nullable|boolean', 'unsubscribed_whatsapp' => 'nullable|boolean',
            'birthday' => 'nullable|date', 'city' => 'nullable|string|max:120', 'fields' => 'nullable|array', 'fields.*' => 'nullable|string|max:500',
        ];
    }

    protected function fillContact(Contact $c, array $d): Contact
    {
        $oldTags = $c->exists ? $c->tagList() : [];
        $oldLists = $c->exists ? $c->lists()->pluck('mk_lists.id')->all() : [];
        $c->fill(collect($d)->only(['first_name', 'last_name', 'company_name', 'email', 'phone', 'tags', 'birthday', 'city', 'fields'])->all());
        foreach (['email', 'sms', 'whatsapp'] as $ch) {
            if (array_key_exists('unsubscribed_'.$ch, $d)) {
                $c->{'unsubscribed_'.$ch.'_at'} = $d['unsubscribed_'.$ch] ? ($c->{'unsubscribed_'.$ch.'_at'} ?? now()) : null;
            }
        }
        $c->save();
        if (array_key_exists('list_ids', $d)) {
            $c->lists()->sync($d['list_ids'] ?? []);
        }
        $engine = app(AutomationEngine::class);
        if ($new = array_values(array_diff(array_map('intval', $d['list_ids'] ?? []), $oldLists))) {
            $engine->listJoined($c, $new);
        }
        if ($added = array_values(array_diff($c->tagList(), $oldTags))) {
            $engine->tagsAdded($c, $added);
        }

        return $c->fresh('lists');
    }

    public function storeContact(Request $r)
    {
        $d = $r->validate($this->contactRules());
        if (empty($d['email']) && empty($d['phone'])) {
            return $this->fail('Indiquez au moins un e-mail ou un téléphone.');
        }
        if ($dup = $this->find($d['email'] ?? null, $d['phone'] ?? null)) {
            $before = $dup->lists()->pluck('mk_lists.id')->all();
            $dup->lists()->syncWithoutDetaching($d['list_ids'] ?? []);
            if ($new = array_values(array_diff(array_map('intval', $d['list_ids'] ?? []), $before))) {
                app(AutomationEngine::class)->listJoined($dup, $new);
            }

            return response()->json($dup->fresh('lists'), 200);
        }

        return response()->json($this->fillContact(new Contact(['source' => 'manual']), $d), 201);
    }

    public function updateContact(Request $r, int $id)
    {
        return $this->fillContact(Contact::findOrFail($id), $r->validate($this->contactRules()));
    }

    public function destroyContact(int $id)
    {
        $c = Contact::findOrFail($id);
        $c->lists()->detach();
        $c->delete();

        return ['ok' => true];
    }

    protected function find(?string $email, ?string $phone): ?Contact
    {
        if ($email) {
            if ($c = Contact::where('email', mb_strtolower(trim($email)))->first()) {
                return $c;
            }
        }
        if ($phone && ($digits = Phone::digits($phone))) {
            return Contact::whereNotNull('phone')->get()->first(fn ($c) => Phone::digits($c->phone) === $digits);
        }

        return null;
    }

    /** Import : clients (contacts de l'ERP), prospects du CRM ou texte collé depuis Excel. */
    public function import(Request $r)
    {
        $d = $r->validate([
            'list_id' => ['required', 'integer', Rule::exists('mk_lists', 'id')->where('company_id', app('tenant')->id)],
            'source' => 'required|in:partners,leads,text',
            'text' => 'required_if:source,text|nullable|string|max:500000',
            'partner_type' => 'nullable|in:prospect,customer,supplier,both,all',
        ]);
        $list = MkList::findOrFail($d['list_id']);
        $rows = [];
        if ($d['source'] === 'partners') {
            $q = Partner::query()->where('active', true);
            if (($d['partner_type'] ?? 'customer') !== 'all') {
                $t = $d['partner_type'] ?? 'customer';
                $q->whereIn('type', $t === 'prospect' ? ['prospect'] : [$t, 'both']);
            }
            foreach ($q->get() as $p) {
                $rows[] = ['company_name' => $p->is_company ? $p->name : null, 'last_name' => $p->is_company ? null : $p->name, 'email' => $p->email, 'phone' => $p->phone, 'source' => 'partner', 'partner_id' => $p->id];
            }
        } elseif ($d['source'] === 'leads') {
            abort_unless(app('tenant')->hasModule('crm'), 422, 'Le module CRM n\'est pas activé.');
            foreach (\App\Modules\Crm\Models\Lead::where('status', 'open')->get() as $l) {
                $rows[] = ['last_name' => $l->contact_name, 'company_name' => $l->company_name, 'email' => $l->email, 'phone' => $l->phone, 'source' => 'lead', 'lead_id' => $l->id];
            }
        } else {
            foreach (preg_split('/\R/', (string) $d['text']) as $i => $line) {
                $cells = array_map('trim', str_getcsv($line, str_contains($line, ';') ? ';' : (str_contains($line, "\t") ? "\t" : ',')));
                if (count(array_filter($cells)) === 0 || ($i === 0 && preg_match('/e-?mail|nom|t[ée]l/i', $line) && ! preg_match('/@/', $line))) {
                    continue;
                }
                $email = collect($cells)->first(fn ($c) => filter_var($c, FILTER_VALIDATE_EMAIL));
                $phone = collect($cells)->first(fn ($c) => ! filter_var($c, FILTER_VALIDATE_EMAIL) && preg_match('/^\+?[\d\s.\-()]{8,}$/', $c));
                $names = array_values(array_filter($cells, fn ($c) => $c !== $email && $c !== $phone && $c !== ''));
                $rows[] = ['first_name' => $names[0] ?? null, 'last_name' => $names[1] ?? null, 'company_name' => $names[2] ?? null, 'email' => $email, 'phone' => $phone, 'source' => 'import'];
            }
        }
        $added = $updated = $skipped = 0;
        foreach ($rows as $row) {
            if (empty($row['email']) && empty($row['phone'])) {
                $skipped++;

                continue;
            }
            if ($c = $this->find($row['email'] ?? null, $row['phone'] ?? null)) {
                $updated++;
            } else {
                $c = Contact::create($row);
                $added++;
            }
            $was = $c->lists()->where('mk_lists.id', $list->id)->exists();
            $c->lists()->syncWithoutDetaching([$list->id]);
            if (! $was && $r->boolean('trigger_automations', true)) {
                app(AutomationEngine::class)->listJoined($c, [$list->id]);
            }
        }
        ActivityLog::record('marketing.import', $list, compact('added', 'updated', 'skipped'));

        return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'list_total' => $list->contacts()->count()];
    }

    // ---------- Modèles ----------

    protected function contentRules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:190', 'channel' => 'sometimes|in:email,sms,whatsapp', 'subject' => 'nullable|string|max:190', 'preheader' => 'nullable|string|max:190',
            'blocks' => 'nullable|array|max:80', 'blocks.*.type' => ['required', Rule::in(EmailRenderer::BLOCK_TYPES)],
            'html' => 'nullable|string|max:300000', 'text' => 'nullable|string|max:2000', 'style' => 'nullable|array',
        ];
    }

    /** validate() ne garde que les clés décrites : les blocs et le style sont repris tels quels. */
    protected function content(Request $r, array $rules): array
    {
        $d = $r->validate($rules);
        foreach (['blocks', 'style'] as $k) {
            if ($r->has($k)) {
                $d[$k] = $r->input($k);
            }
        }

        return $d;
    }

    public function template(int $id)
    {
        return Template::findOrFail($id);
    }

    public function storeTemplate(Request $r)
    {
        $d = $this->content($r, ['name' => 'required|string|max:190', 'layout' => 'nullable|string|max:40'] + $this->contentRules());
        if (! empty($d['layout']) && ($l = EmailLayouts::find($d['layout']))) {
            $d += ['subject' => $l['subject'], 'preheader' => $l['preheader'], 'blocks' => $l['blocks'], 'style' => $l['style']];
        } elseif (! empty($d['layout']) && ($t = EmailLayouts::findText($d['layout']))) {
            // Message SMS / WhatsApp prêt à l'emploi
            $d += ['text' => $t['text']];
        }
        unset($d['layout']);

        return response()->json(Template::create($d), 201);
    }

    public function updateTemplate(Request $r, int $id)
    {
        $t = Template::findOrFail($id);
        $t->update($this->content($r, $this->contentRules()));

        return $t;
    }

    public function destroyTemplate(int $id)
    {
        Template::findOrFail($id)->delete();

        return ['ok' => true];
    }

    // ---------- Campagnes ----------

    public function campaign(int $id)
    {
        $c = Campaign::findOrFail($id);
        if (in_array($c->status, ['sending', 'sent', 'cancelled'], true)) {
            $c->refreshStats(); // ouvertures, clics et désinscriptions arrivent après l'envoi
        }
        $failed = CampaignMessage::where('campaign_id', $c->id)->where('status', 'failed')->limit(20)->get(['to', 'error']);
        $links = Click::where('campaign_id', $c->id)->selectRaw('url, COUNT(*) as clicks, COUNT(DISTINCT message_id) as people')->groupBy('url')->orderByDesc('clicks')->limit(30)->get();
        $variants = null;
        if (! empty($c->ab['status'])) {
            foreach (['A', 'B'] as $v) {
                $q = fn () => CampaignMessage::where('campaign_id', $c->id)->where('variant', $v)->where('status', 'sent');
                $sent = $q()->count();
                $variants[$v] = ['subject' => $v === 'A' ? $c->subject : ($c->ab['subject_b'] ?? ''), 'sent' => $sent, 'opened' => $q()->whereNotNull('opened_at')->count(), 'clicked' => $q()->whereNotNull('clicked_at')->count()];
            }
        }
        // Ouvertures heure par heure sur les 48 premières heures (heures sans ouverture incluses)
        $timeline = [];
        if ($c->started_at) {
            $counts = CampaignMessage::where('campaign_id', $c->id)->whereNotNull('opened_at')->get(['opened_at'])
                ->groupBy(fn ($m) => $m->opened_at->format('Y-m-d H:00'))->map->count();
            if ($counts->isNotEmpty()) {
                $start = $c->started_at->copy()->startOfHour();
                $last = min(47, max(5, (int) $start->diffInHours(\Carbon\Carbon::parse($counts->keys()->max()))));
                for ($h = 0; $h <= $last; $h++) {
                    $k = $start->copy()->addHours($h)->format('Y-m-d H:00');
                    $timeline[$k] = $counts[$k] ?? 0;
                }
            }
        }

        return $c->toArray() + ['status_label' => Campaign::STATUSES[$c->status], 'recipients_estimate' => in_array($c->status, ['draft', 'scheduled'], true) ? $this->campaigns->recipients($c)->count() : null,
            'failures' => $failed, 'links' => $links, 'variants' => $variants, 'opens_timeline' => $timeline];
    }

    public function storeCampaign(Request $r)
    {
        $d = $this->content($r, ['name' => 'required|string|max:190', 'kind' => ['nullable', Rule::in(array_keys(Campaign::KINDS))], 'template_id' => 'nullable|integer', 'layout' => 'nullable|string|max:40',
            'copy_of' => 'nullable|integer', 'list_ids' => 'nullable|array', 'segment_ids' => 'nullable|array'] + $this->contentRules());
        if (! empty($d['template_id']) && ($t = Template::find($d['template_id']))) {
            $d += ['channel' => $t->channel, 'subject' => $t->subject, 'preheader' => $t->preheader, 'blocks' => $t->blocks, 'html' => $t->html, 'text' => $t->text, 'style' => $t->style];
        } elseif (! empty($d['layout']) && ($l = EmailLayouts::find($d['layout']))) {
            $d += ['channel' => 'email', 'subject' => $l['subject'], 'preheader' => $l['preheader'], 'blocks' => $l['blocks'], 'style' => $l['style']];
        } elseif (! empty($d['copy_of']) && ($src = Campaign::find($d['copy_of']))) {
            $d += ['channel' => $src->channel, 'subject' => $src->subject, 'preheader' => $src->preheader, 'blocks' => $src->blocks, 'html' => $src->html, 'text' => $src->text, 'style' => $src->style, 'list_ids' => $src->list_ids, 'segment_ids' => $src->segment_ids];
        }
        unset($d['template_id'], $d['layout'], $d['copy_of']);

        return response()->json(Campaign::create($d + ['user_id' => $r->user()->id, 'status' => 'draft']), 201);
    }

    public function updateCampaign(Request $r, int $id)
    {
        $c = Campaign::findOrFail($id);
        if (! in_array($c->status, ['draft', 'scheduled'], true)) {
            return $this->fail('Une campagne envoyée ne peut plus être modifiée (dupliquez-la).');
        }
        $d = $this->content($r, ['kind' => ['nullable', Rule::in(array_keys(Campaign::KINDS))], 'list_ids' => 'nullable|array', 'list_ids.*' => 'integer', 'segment_ids' => 'nullable|array', 'segment_ids.*' => 'integer',
            'ab' => 'nullable|array', 'ab.enabled' => 'nullable|boolean', 'ab.subject_b' => 'nullable|string|max:190', 'ab.percent' => 'nullable|integer|min:5|max:50',
            'ab.wait_hours' => 'nullable|integer|min:1|max:72', 'ab.metric' => 'nullable|in:open,click'] + $this->contentRules());
        $c->update($d);

        return $this->campaign($id);
    }

    public function duplicate(int $id)
    {
        $c = Campaign::findOrFail($id)->replicate(['status', 'scheduled_at', 'started_at', 'sent_at', 'stats']);
        $c->ab = ! empty($c->ab) ? array_intersect_key((array) $c->ab, array_flip(['enabled', 'subject_b', 'percent', 'wait_hours', 'metric'])) : null;
        $c->name .= ' (copie)';
        $c->status = 'draft';
        $c->save();

        return response()->json($c, 201);
    }

    public function destroyCampaign(int $id)
    {
        $c = Campaign::findOrFail($id);
        if ($c->status === 'sending') {
            return $this->fail('Annulez d\'abord l\'envoi en cours.');
        }
        CampaignMessage::where('campaign_id', $c->id)->delete();
        $c->delete();

        return ['ok' => true];
    }

    public function send(Request $r, int $id)
    {
        $c = Campaign::findOrFail($id);
        $d = $r->validate(['scheduled_at' => 'nullable|date|after:now']);
        try {
            if (! empty($d['scheduled_at'])) {
                if ($this->campaigns->recipients($c)->isEmpty()) {
                    return $this->fail('Aucun destinataire joignable dans les listes choisies.');
                }
                $c->update(['status' => 'scheduled', 'scheduled_at' => $d['scheduled_at']]);
            } else {
                $this->campaigns->launch($c);
            }
        } catch (\DomainException $e) {
            return $this->fail($e->getMessage());
        }
        ActivityLog::record('marketing.campaign.'.(! empty($d['scheduled_at']) ? 'scheduled' : 'sent'), $c);

        return $this->campaign($id);
    }

    public function cancel(int $id)
    {
        $c = Campaign::findOrFail($id);
        if (! in_array($c->status, ['scheduled', 'sending'], true)) {
            return $this->fail('Rien à annuler.');
        }
        CampaignMessage::where('campaign_id', $c->id)->whereIn('status', ['queued', 'held'])->delete();
        $c->update(['status' => $c->status === 'scheduled' ? 'draft' : 'cancelled', 'scheduled_at' => null]);
        $c->refreshStats();

        return $this->campaign($id);
    }

    public function test(Request $r, int $id)
    {
        $c = Campaign::findOrFail($id);
        $d = $r->validate(['to' => 'required|string|max:190']);
        try {
            $res = $this->campaigns->test($c, $d['to']);
        } catch (\DomainException $e) {
            return $this->fail($e->getMessage());
        }

        return $res->ok ? ['ok' => true] : $this->fail('Échec : '.$res->error);
    }

    /** Aperçu dans le navigateur (lien valable 1 h). */
    public function preview(Request $r)
    {
        $d = $this->content($r, $this->contentRules());
        $html = (new EmailRenderer(app('tenant')))->render($d['blocks'] ?? [], $d['html'] ?? null, $d['style'] ?? null,
            ['prenom' => 'Awa', 'nom' => 'Koné', 'nom_complet' => 'Awa Koné', 'societe' => 'Société exemple', 'email' => 'awa@exemple.ci', 'telephone' => '+225 07 00 00 00 00', 'subject' => $d['subject'] ?? ''], $d['preheader'] ?? null);
        $key = Str::random(40);
        Cache::put('mk_preview_'.$key, $html, 3600);

        return ['url' => url('/m/preview/'.$key), 'html' => $html];
    }

    public function attach(Request $r, int $id)
    {
        $c = Campaign::findOrFail($id);
        $r->validate(['file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,ppt,pptx']);
        $f = $r->file('file');
        $path = $f->storeAs('marketing/'.app('tenant')->id, Str::random(16).'.'.$f->getClientOriginalExtension(), 'local');
        $c->update(['attachments' => array_values(array_merge((array) $c->attachments, [['name' => $f->getClientOriginalName(), 'path' => $path, 'mime' => $f->getMimeType(), 'size' => $f->getSize()]]))]);

        return $this->campaign($id);
    }

    public function detach(Request $r, int $id)
    {
        $c = Campaign::findOrFail($id);
        $path = (string) $r->input('path');
        $c->update(['attachments' => array_values(array_filter((array) $c->attachments, fn ($a) => $a['path'] !== $path))]);

        return $this->campaign($id);
    }

    /** Images des modèles (hébergées pour être visibles dans les messageries). */
    public function image(Request $r)
    {
        $r->validate(['image' => 'required|image|mimes:png,jpg,jpeg,gif,webp|max:4096']);
        $dir = public_path('uploads/marketing/'.app('tenant')->id);
        File::ensureDirectoryExists($dir);
        $name = Str::random(20).'.'.$r->file('image')->extension();
        $r->file('image')->move($dir, $name);

        return ['url' => url('uploads/marketing/'.app('tenant')->id.'/'.$name)];
    }
}
