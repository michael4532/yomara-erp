<?php

namespace App\Modules\Prestations;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Core\Models\Partner;
use App\Modules\Prestations\Models\Deliverable;
use App\Modules\Prestations\Models\Entry;
use App\Modules\Prestations\Models\Pack;
use App\Modules\Ventes\Models\SalesDocument;
use App\Services\EntityWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrestationService
{
    public const FILE_EXT = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'zip', 'docx', 'xlsx', 'pptx', 'mp4', 'mov', 'mp3', 'psd', 'ai', 'txt'];

    // ------------------------------------------------------------------ forfaits

    protected function invoice(Pack $p, array $lines, string $note): string
    {
        $id = (string) Str::uuid();
        SalesDocument::$systemWrite = true;
        try {
            $res = app(EntityWriter::class)->upsert(SalesDocument::class, $id, ['type' => 'invoice', 'partner_id' => $p->partner_id, 'date' => now()->toDateString(),
                'status' => 'validated', 'reference' => $p->number, 'notes' => $note, 'lines' => $lines]);
        } finally {
            SalesDocument::$systemWrite = false;
        }
        if ($res['status'] !== EntityWriter::APPLIED) {
            throw new \DomainException('Facture refusée : '.($res['message'] ?? ''));
        }

        return $id;
    }

    public function invoicePack(Pack $p): SalesDocument
    {
        if ($p->invoice_id) {
            throw new \DomainException('Forfait déjà facturé.');
        }
        $h = rtrim(rtrim(number_format($p->hours, 2, ',', ''), '0'), ',');
        $id = $this->invoice($p, [['id' => (string) Str::uuid(), 'description' => 'Forfait '.$h.' h — '.$p->name.($p->expiry_date ? ' (valable jusqu\'au '.$p->expiry_date->format('d/m/Y').')' : ''),
            'quantity' => 1, 'unit' => 'forfait', 'unit_price' => (float) $p->price, 'tax_rate' => (float) $p->tax_rate, 'discount_percent' => 0]],
            'Forfait d\'heures prépayé '.$p->number.'. Consommation consultable sur votre espace client.');
        $p->forceFill(['invoice_id' => $id])->save();

        return SalesDocument::find($id);
    }

    public function invoiceOverage(Pack $p): SalesDocument
    {
        $over = round(max(0, $p->consumed() - $p->hours) - $p->overage_billed, 2);
        if ($over <= 0) {
            throw new \DomainException('Aucun dépassement à facturer.');
        }
        if (! $p->hourly_rate) {
            throw new \DomainException('Indiquez le taux horaire de dépassement du forfait.');
        }
        $id = $this->invoice($p, [['id' => (string) Str::uuid(), 'description' => 'Dépassement du forfait '.$p->number.' — '.$p->name, 'quantity' => $over, 'unit' => 'h',
            'unit_price' => (float) $p->hourly_rate, 'tax_rate' => (float) $p->tax_rate, 'discount_percent' => 0]], 'Heures au-delà du forfait '.$p->number.'.');
        $p->forceFill(['overage_billed' => round($p->overage_billed + $over, 2), 'overage_invoices' => array_merge($p->overage_invoices ?? [], [$id])])->save();

        return SalesDocument::find($id);
    }

    /** Nouveau forfait identique (facturé), l'ancien est clos. */
    public function renew(Pack $p, bool $invoice = true): Pack
    {
        $id = (string) Str::uuid();
        $months = $p->expiry_date ? max(1, (int) round($p->start_date->diffInMonths($p->expiry_date))) : null;
        $res = app(EntityWriter::class)->upsert(Pack::class, $id, array_filter([
            'partner_id' => $p->partner_id, 'name' => $p->name, 'hours' => $p->hours, 'price' => $p->price, 'tax_rate' => $p->tax_rate, 'hourly_rate' => $p->hourly_rate,
            'start_date' => now()->toDateString(), 'expiry_date' => $months ? now()->addMonthsNoOverflow($months)->toDateString() : null, 'alert_percent' => $p->alert_percent,
            'notes' => $p->notes, 'invoice' => $invoice,
        ], fn ($v) => $v !== null));
        if ($res['status'] !== EntityWriter::APPLIED) {
            throw new \DomainException($res['message'] ?? 'Renouvellement refusé.');
        }
        $new = Pack::find($id);
        $new->forceFill(['renewed_from' => $p->id])->save();
        if ($p->status === 'active') {
            $p->status = 'exhausted';
            $p->save();
        }

        return $new;
    }

    /** État et alertes de consommation (seuil réglable, puis 100 %). */
    public function refreshPack(?Pack $p): void
    {
        if (! $p) {
            return;
        }
        $used = $p->consumed();
        $pct = $p->hours > 0 ? 100 * $used / $p->hours : 0;
        $sent = array_filter(explode(',', (string) $p->alerted));
        $changed = false;
        if (in_array($p->status, ['active', 'exhausted'], true)) {
            $status = $used + 0.001 >= $p->hours ? 'exhausted' : 'active';
            if ($status !== $p->status && ! ($p->status === 'exhausted' && Pack::where('renewed_from', $p->id)->exists())) {
                $p->status = $status;
                $changed = true;
            }
        }
        foreach ([$p->alert_percent, 100] as $th) {
            if ($pct + 0.001 >= $th && ! in_array((string) $th, $sent, true)) {
                $sent[] = (string) $th;
                $changed = true;
                $this->alert($p, $th, $used);
            }
        }
        // Temps retiré : les alertes peuvent repartir
        $sent = array_values(array_filter($sent, fn ($th) => $pct + 0.001 >= (int) $th));
        if ($changed || implode(',', $sent) !== (string) $p->alerted) {
            $p->alerted = implode(',', $sent) ?: null;
            $p->save();
        }
    }

    protected function alert(Pack $p, int $th, float $used): void
    {
        $company = app('tenant');
        $h = fn ($v) => rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
        $title = $th >= 100 ? 'Forfait épuisé : '.$p->partner_name : 'Forfait consommé à '.$th.' % : '.$p->partner_name;
        $users = User::where('company_id', $company->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('prestations.manage'))->pluck('id');
        AppNotification::send($company->id, $users, 'pack', $title, $p->name.' · '.$h($used).' h sur '.$h($p->hours).' h', 'prestations', $p->id);
        $partner = Partner::find($p->partner_id);
        $messaging = app(MessagingService::class);
        if ($partner?->email && ($messaging->available($company)['email'] ?? null)) {
            $text = "Bonjour {$partner->name},\n\nVotre forfait « {$p->name} » est consommé à ".min(100, (int) round(100 * $used / $p->hours))." % ({$h($used)} h sur {$h($p->hours)} h)."
                .($th >= 100 ? "\nLes prochaines interventions seront facturées au-delà du forfait, sauf renouvellement." : '')."\n\nDétail sur votre espace client.\n\n{$company->name}";
            try {
                $messaging->send($company, 'email', new Message(to: $partner->email, subject: $title, text: $text, html: nl2br(e($text)), toName: $partner->name), 'prestations');
            } catch (\Throwable) {
            }
        }
    }

    // ------------------------------------------------------------------ liaison avec les projets

    /** Le temps facturable d'un projet rattaché à un forfait est décompté du forfait (et n'est plus facturé par le projet). */
    public function syncTimesheet(\App\Modules\Projets\Models\Timesheet $t): void
    {
        if (! app()->bound('tenant') || ! app('tenant')->hasModule('prestations')) {
            return;
        }
        $project = \App\Modules\Projets\Models\Project::find($t->project_id);
        $entry = Entry::where('timesheet_id', $t->id)->first();
        $pack = ($project?->pack_id && $t->billable && ! $t->invoice_id && ! $t->trashed()) ? Pack::find($project->pack_id) : null;
        // Forfait clos : le temps déjà décompté y reste, le nouveau temps redevient facturable par le projet
        if ($pack && ! in_array($pack->status, ['active', 'exhausted'], true) && $entry?->pack_id !== $pack->id) {
            $pack = null;
        }
        $old = $entry?->pack_id;
        if (! $pack) {
            if ($entry) {
                $entry->delete();
            }
            if ($t->pack_id) {
                $t->forceFill(['pack_id' => null])->save();
            }

            return;
        }
        $entry ??= new Entry;
        $entry->forceFill([
            'pack_id' => $pack->id, 'date' => $t->date, 'hours' => $t->hours, 'timesheet_id' => $t->id, 'project_name' => $project->name,
            'description' => Str::limit($t->description ?: 'Temps passé sur le projet', 255, ''),
            'user_id' => $t->user_id, 'user_name' => $t->user_id ? User::find($t->user_id)?->name : null,
        ]);
        if ($entry->isDirty()) {
            $entry->save();
        }
        if ($t->pack_id !== $pack->id) {
            $t->forceFill(['pack_id' => $pack->id])->save();
        }
        $this->refreshPack($pack->fresh());
        if ($old && $old !== $pack->id) {
            $this->refreshPack(Pack::find($old));
        }
    }

    public function unlinkTimesheet(\App\Modules\Projets\Models\Timesheet $t): void
    {
        Entry::where('timesheet_id', $t->id)->get()->each->delete();
    }

    /** Forfait du projet changé : temps non facturés reportés sur le nouveau forfait (ou rendus au projet). */
    public function syncProject(\App\Modules\Projets\Models\Project $p, ?string $previous): void
    {
        foreach (\App\Modules\Projets\Models\Timesheet::where('project_id', $p->id)->whereNull('invoice_id')->get() as $t) {
            $this->syncTimesheet($t);
        }
    }

    // ------------------------------------------------------------------ livrables

    public function attach(Deliverable $d, UploadedFile $f): Deliverable
    {
        if ($d->status === 'sent' || $d->status === 'approved') {
            throw new \DomainException('Livrable envoyé ou validé : créez une nouvelle version pour changer le fichier.');
        }
        $ext = strtolower($f->getClientOriginalExtension());
        if (! in_array($ext, self::FILE_EXT, true)) {
            throw new \DomainException('Type de fichier refusé (.'.$ext.').');
        }
        if ($d->file_path) {
            Storage::disk('local')->delete($d->file_path);
        }
        $name = Str::limit(preg_replace('/[^\pL\pN._ -]/u', '_', $f->getClientOriginalName()), 120, '');
        $path = $f->storeAs('deliverables/'.$d->company_id.'/'.$d->id.'/v'.$d->version_no, Str::random(8).'-'.$name, 'local');
        $d->forceFill(['file_path' => $path, 'file_name' => $name, 'file_size' => $f->getSize()])->save();

        return $d;
    }

    public function sendDeliverable(Company $company, Deliverable $d): bool
    {
        $p = Partner::find($d->partner_id);
        $text = "Bonjour ".($p?->name ?? '').",\n\nNous vous soumettons « {$d->title} »".($d->version_no > 1 ? ' (version '.$d->version_no.')' : '')." pour validation.\n\nConsulter et donner votre accord : ".$d->publicUrl()
            .($d->due_date ? "\nMerci de nous répondre avant le ".$d->due_date->format('d/m/Y').'.' : '')."\n\n{$company->name}";
        $messaging = app(MessagingService::class);
        $avail = $messaging->available($company);
        $sent = false;
        if ($p?->email && ($avail['email'] ?? null)) {
            $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;line-height:1.5">'.nl2br(e($text)).'<p><a href="'.e($d->publicUrl()).'" style="display:inline-block;background:#1f6feb;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">Voir et valider</a></p></div>';
            try {
                $sent = $messaging->send($company, 'email', new Message(to: $p->email, subject: 'À valider : '.$d->title, html: $html, text: $text, toName: $p->name), 'prestations')->ok;
            } catch (\Throwable) {
            }
        }
        if ($p?->phone && ($avail['whatsapp'] ?? null)) {
            try {
                $sent = $messaging->send($company, 'whatsapp', new Message(to: $p->phone, text: $text, toName: $p->name), 'prestations')->ok || $sent;
            } catch (\Throwable) {
            }
        }

        return $sent;
    }

    /** Décision du client (page publique ou espace client). */
    public function decide(Deliverable $d, bool $approve, string $name, ?string $comment): Deliverable
    {
        if ($d->status !== 'sent') {
            throw new \DomainException($d->status === 'approved' ? 'Ce livrable est déjà validé.' : 'Ce livrable n\'attend pas de décision.');
        }
        if (! $approve && trim((string) $comment) === '') {
            throw new \DomainException('Décrivez les modifications souhaitées.');
        }
        $d->status = $approve ? 'approved' : 'changes';
        $d->decided_at = now();
        $d->decided_by = mb_substr(trim($name), 0, 120);
        $d->client_comment = $comment ? mb_substr(trim($comment), 0, 3000) : null;
        $d->history = array_merge($d->history ?? [], [['v' => $d->version_no, 'event' => $d->status, 'at' => now()->toIso8601String(), 'by' => $d->decided_by, 'comment' => $d->client_comment]]);
        $d->save();
        $company = app('tenant');
        $users = User::where('company_id', $company->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('prestations.manage') || $u->hasPermission('prestations.log'))->pluck('id');
        AppNotification::send($company->id, $users, 'deliverable', ($approve ? 'Livrable validé : ' : 'Modifications demandées : ').$d->title,
            ($d->partner_name ?? '').' · '.$d->decided_by.($d->client_comment ? ' — « '.Str::limit($d->client_comment, 120).' »' : ''), 'prestations', $d->id);
        if (class_exists(\App\Modules\Portail\PortalService::class) && $company->hasModule('portail')) {
            try {
                app(\App\Modules\Portail\PortalService::class)->log($d->partner_id, 'message', null, ['subject' => ($approve ? 'Livrable validé : ' : 'Modifications demandées : ').$d->title, 'body' => (string) $d->client_comment]);
            } catch (\Throwable) {
            }
        }

        return $d;
    }

    // ------------------------------------------------------------------ tâches quotidiennes

    public function runDaily(Company $company): array
    {
        $out = ['expired' => 0, 'reminders' => 0];
        foreach (Pack::whereIn('status', ['active', 'exhausted'])->whereNotNull('expiry_date')->where('expiry_date', '<', now()->toDateString())->get() as $p) {
            $p->status = 'expired';
            $p->save();
            $out['expired']++;
            $users = User::where('company_id', $company->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('prestations.manage'))->pluck('id');
            $left = round($p->hours - $p->consumed(), 2);
            AppNotification::send($company->id, $users, 'pack', 'Forfait expiré : '.$p->partner_name, $p->name.($left > 0 ? ' · '.rtrim(rtrim(number_format($left, 2, ',', ''), '0'), ',').' h non utilisées' : ''), 'prestations', $p->id);
        }
        foreach (Deliverable::where('status', 'sent')->where('reminded', false)->where('sent_at', '<', now()->subDays(3))->get() as $d) {
            $this->sendDeliverable($company, $d);
            $d->forceFill(['reminded' => true])->save();
            $out['reminders']++;
        }

        return $out;
    }

    public function stats(): array
    {
        $packs = Pack::where('status', 'active')->get();

        return [
            'active_packs' => $packs->count(),
            'hours_left' => round($packs->sum(fn ($p) => max(0, $p->hours - $p->consumed())), 2),
            'low_packs' => $packs->filter(fn ($p) => $p->hours > 0 && $p->consumed() / $p->hours * 100 >= $p->alert_percent)->count(),
            'overage_unbilled' => round(Pack::whereIn('status', ['active', 'exhausted', 'expired'])->get()->sum(fn ($p) => max(0, max(0, $p->consumed() - $p->hours) - $p->overage_billed)), 2),
            'awaiting' => Deliverable::where('status', 'sent')->count(),
            'changes' => Deliverable::where('status', 'changes')->count(),
            'approved_month' => Deliverable::where('status', 'approved')->where('decided_at', '>=', now()->startOfMonth())->count(),
        ];
    }
}
