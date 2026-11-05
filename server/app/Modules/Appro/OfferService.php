<?php

namespace App\Modules\Appro;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Achats\Models\PurchaseDocument;
use App\Modules\Achats\Models\PurchaseRequest;
use App\Modules\Appro\Models\Comparison;
use App\Modules\Appro\Models\OfferInvite;
use App\Modules\Appro\Models\OfferMessage;
use App\Modules\Appro\Models\Spec;
use App\Modules\Core\Models\Partner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Comparatif ouvert aux fournisseurs : création depuis n'importe quelle demande (demande d'achat, demande de prix,
 * appel de fonds, appel d'offres, cahier des charges), lien personnel pour répondre en ligne, questions / réponses, relances.
 */
class OfferService
{
    public const SOURCES = ['request' => 'Demande d\'achat', 'rfq' => 'Demande de prix', 'fund' => 'Appel de fonds', 'tender' => 'Appel d\'offres', 'spec' => 'Cahier des charges'];

    public const INVITE_STATUSES = ['sent' => 'Invité', 'opened' => 'Consulté', 'answered' => 'Offre reçue', 'declined' => 'Décliné'];

    public function __construct(private SourcingService $sourcing, private MessagingService $messaging) {}

    protected function assert(bool $ok, string $msg): void
    {
        if (! $ok) {
            throw new \DomainException($msg);
        }
    }

    // ------------------------------------------------------------------ « Comparer » depuis une demande

    /** Comparatif lié à une demande : renvoie celui qui est en cours s'il existe, sinon le crée avec les lignes (et offres connues) de la demande. */
    public function fromSource(Company $c, string $type, string $id, ?int $userId): Comparison
    {
        $this->assert(isset(self::SOURCES[$type]), 'Source inconnue.');
        $existing = Comparison::where('source_type', $type)->where('source_id', $id)->whereIn('status', ['open', 'awarded'])->orderByDesc('id')->first();
        if ($existing) {
            return $existing;
        }
        $offers = [];
        $deadline = null;
        switch ($type) {
            case 'request':
                $r = PurchaseRequest::findOrFail($id);
                $title = $r->title;
                $label = trim(($r->number ? $r->number.' · ' : '').$r->title);
                $lines = array_map(fn ($l) => ['product_id' => $l['product_id'] ?? null, 'description' => $l['description'], 'quantity' => $l['quantity'] ?? 1, 'unit' => $l['unit'] ?? null], (array) $r->lines);
                // Fournisseurs suggérés sur les lignes de la demande
                foreach (collect((array) $r->lines)->pluck('supplier_id')->filter()->unique() as $pid) {
                    $offers[] = ['partner_id' => $pid, 'currency' => $c->currency, 'prices' => []];
                }
                $deadline = $r->needed_by?->copy()->subDays(3)->max(now()->addDays(2))->toDateString();
                break;
            case 'rfq':
                $d = PurchaseDocument::with('lines')->findOrFail($id);
                $this->assert($d->type === 'rfq', 'Ce document n\'est pas une demande de prix.');
                $title = 'Demande de prix '.$d->number;
                $label = $d->number.($d->partner_name ? ' · '.$d->partner_name : '');
                $lines = $d->lines->map(fn ($l) => ['product_id' => $l->product_id, 'description' => $l->description, 'quantity' => (float) $l->quantity ?: 1, 'unit' => $l->unit])->values()->all();
                $deadline = $d->due_date?->toDateString();
                break;
            case 'fund':
                $f = \App\Modules\Fonds\Models\FundRequest::with('lines')->findOrFail((int) $id);
                $title = $f->title;
                $label = trim(($f->number ? $f->number.' · ' : '').$f->title);
                $src = $f->lines->whereIn('type', ['equipment', 'service']);
                $src = $src->isEmpty() ? $f->lines : $src;
                $lines = $src->map(fn ($l) => ['product_id' => $l->product_id, 'description' => $l->description, 'quantity' => (float) $l->quantity ?: 1, 'unit' => null])->values()->all();
                $deadline = $f->needed_by?->copy()->subDays(2)->max(now()->addDays(2))->toDateString();
                break;
            case 'tender':
                $t = \App\Modules\Fournisseurs\Models\Tender::findOrFail($id);
                $title = $t->title;
                $label = trim(($t->number ? $t->number.' · ' : '').$t->title);
                $lines = array_map(fn ($l) => ['key' => Str::limit((string) $l['id'], 40, ''), 'product_id' => $l['product_id'] ?? null, 'description' => $l['description'], 'quantity' => $l['quantity'], 'unit' => $l['unit'] ?? null], (array) $t->lines);
                $deadline = $t->deadline?->toDateString();
                foreach (\App\Modules\Fournisseurs\Models\Bid::where('tender_id', $t->id)->get() as $b) {
                    $offers[] = ['partner_id' => $b->partner_id, 'partner_name' => $b->partner_name, 'currency' => $t->currency ?: $c->currency, 'delay_days' => $b->delivery_days,
                        'validity' => $b->valid_until?->toDateString(), 'notes' => $b->comment, 'source' => 'portal',
                        'prices' => collect($b->lines)->filter(fn ($x) => $x['unit_price'] !== null)->mapWithKeys(fn ($x) => [Str::limit((string) $x['line_id'], 40, '') => $x['unit_price']])->all()];
                }
                break;
            default: // spec
                $s = Spec::findOrFail((int) $id);
                $title = $s->title;
                $label = $s->number.' · '.$s->title;
                $lines = array_map(fn ($r) => ['description' => trim($r['label'].($r['value'] ? ' — '.$r['value'] : '')), 'quantity' => $r['quantity'] ?? 1, 'unit' => $r['unit'] ?? null], (array) $s->requirements);
                $deadline = $s->deadline?->toDateString();
                foreach ((array) $s->sent_to as $x) {
                    if (! empty($x['partner_id'] ?? $x['id'] ?? null)) {
                        $offers[] = ['partner_id' => $x['partner_id'] ?? $x['id'], 'currency' => $c->currency, 'prices' => []];
                    }
                }
        }
        $lines = array_values(array_filter($lines, fn ($l) => trim((string) $l['description']) !== ''));
        $this->assert($lines !== [], 'Cette demande n\'a aucune ligne à comparer.');
        $cmp = $this->sourcing->saveComparison($c, null, ['title' => Str::limit($title, 200, ''), 'deadline' => $deadline, 'lines' => $lines, 'spec_id' => $type === 'spec' ? (int) $id : null,
            'request_id' => $type === 'request' ? $id : null], $userId);
        // Les clés des lignes d'un appel d'offres sont celles du portail : on les conserve pour reprendre les offres
        if ($type === 'tender') {
            $cmp->lines = array_map(fn ($l, $i) => ['key' => $lines[$i]['key']] + $l, $cmp->lines, array_keys($cmp->lines));
            $cmp->save();
        }
        $cmp->forceFill(['source_type' => $type, 'source_id' => $id, 'source_label' => Str::limit($label, 200, '')])->save();
        if ($offers) {
            $cmp = $this->sourcing->saveComparison($c, $cmp, ['offers' => collect($offers)->unique('partner_id')->values()->all()], $userId);
        }

        return $cmp->fresh();
    }

    /** Lien vers la demande d'origine (pour l'écran de l'acheteur). */
    public function sourceOf(Comparison $cmp): ?array
    {
        if (! $cmp->source_type) {
            return $cmp->spec_id ? ['type' => 'spec', 'id' => (string) $cmp->spec_id, 'label' => Spec::find($cmp->spec_id)?->number, 'type_label' => self::SOURCES['spec']] : null;
        }

        return ['type' => $cmp->source_type, 'id' => $cmp->source_id, 'label' => $cmp->source_label, 'type_label' => self::SOURCES[$cmp->source_type] ?? $cmp->source_type];
    }

    // ------------------------------------------------------------------ invitations

    public function url(OfferInvite $i): string
    {
        return url('/offre/'.$i->token);
    }

    /** Garantit une colonne d'offre pour le fournisseur et renvoie sa clé. */
    protected function offerKey(Company $c, Comparison $cmp, Partner $p): string
    {
        $offers = collect((array) $cmp->offers);
        $o = $offers->firstWhere('partner_id', $p->id);
        if ($o) {
            return $o['key'];
        }
        $cmp = $this->sourcing->saveComparison($c, $cmp, ['offers' => array_merge($offers->all(), [['partner_id' => $p->id, 'currency' => $c->currency, 'prices' => []]])], $cmp->user_id);

        return collect($cmp->offers)->firstWhere('partner_id', $p->id)['key'];
    }

    /** Invite des fournisseurs à répondre en ligne : lien personnel envoyé par e-mail et/ou WhatsApp, et renvoyé pour être copié. */
    public function invite(Company $c, Comparison $cmp, array $partnerIds, ?string $message, ?User $u, array $channels = ['email', 'whatsapp']): array
    {
        $this->assert($cmp->status === 'open', 'Ce comparatif est clos.');
        $this->assert(! $cmp->deadline || $cmp->deadline->gte(now()->startOfDay()), 'La date limite des offres est passée : reportez-la d\'abord.');
        $avail = $this->messaging->available($c);
        $out = [];
        foreach (Partner::whereIn('id', $partnerIds)->get() as $p) {
            $key = $this->offerKey($c, $cmp->fresh(), $p);
            $inv = OfferInvite::where('comparison_id', $cmp->id)->where('partner_id', $p->id)->first()
                ?? new OfferInvite(['company_id' => $c->id, 'comparison_id' => $cmp->id, 'partner_id' => $p->id, 'token' => Str::random(40), 'status' => 'sent', 'user_id' => $u?->id]);
            $inv->fill(['name' => $p->name, 'email' => $p->email, 'phone' => $p->phone, 'offer_key' => $key]);
            if ($inv->status === 'declined') {
                $inv->status = 'sent';
            }
            $inv->sent_at = now();
            $inv->save();
            $via = [];
            $url = $this->url($inv);
            $intro = $message ?: 'Nous vous consultons pour « '.$cmp->title.' ». Merci de nous indiquer vos prix, délai et conditions'.($cmp->deadline ? ' avant le '.$cmp->deadline->format('d/m/Y') : '').' en remplissant le formulaire en ligne.';
            if (in_array('email', $channels, true) && $p->email && ($avail['email'] ?? false)) {
                $html = '<p>Bonjour,</p><p>'.nl2br(e($intro)).'</p><p><a href="'.e($url).'" style="display:inline-block;background:#1f6feb;color:#fff;padding:11px 18px;border-radius:8px;text-decoration:none;font-weight:bold">Répondre en ligne</a></p>'
                    .'<p style="color:#666">Ou copiez ce lien : '.e($url).'</p><p>Cordialement,<br>'.e($c->name).'</p>';
                $attach = $cmp->spec_id && ($s = Spec::find($cmp->spec_id)) ? [['name' => 'cahier-des-charges-'.Str::slug($s->number).'.pdf', 'data' => $this->sourcing->specPdf($c, $s), 'mime' => 'application/pdf']] : [];
                if (rescue(fn () => $this->messaging->send($c, 'email', new Message($p->email, 'Demande de prix : '.$cmp->title.' ('.$cmp->number.')', $html, null, $attach, $p->name), 'appro')->ok, false, false)) {
                    $via[] = 'email';
                }
            }
            if (in_array('whatsapp', $channels, true) && $p->phone && ($avail['whatsapp'] ?? false)) {
                $text = $c->name.' : '.$intro."\n".$url;
                if (rescue(fn () => $this->messaging->send($c, 'whatsapp', new Message(to: $p->phone, text: $text, toName: $p->name), 'appro')->ok, false, false)) {
                    $via[] = 'whatsapp';
                }
            }
            $out[] = ['partner_id' => $p->id, 'name' => $p->name, 'url' => $url, 'phone' => $p->phone, 'email' => $p->email, 'via' => $via];
        }

        return ['invites' => $out, 'sent' => count(array_filter($out, fn ($x) => $x['via'])), 'to_share' => array_values(array_filter($out, fn ($x) => ! $x['via']))];
    }

    // ------------------------------------------------------------------ côté fournisseur (lien public)

    /** @return array{0: Company, 1: OfferInvite, 2: Comparison} */
    public function context(string $token): array
    {
        abort_unless(strlen($token) >= 32, 404);
        $inv = OfferInvite::withoutGlobalScopes()->where('token', $token)->first();
        abort_unless($inv, 404);
        $c = Company::find($inv->company_id);
        abort_unless($c && $c->status !== 'suspended' && $c->hasModule('appro'), 404);
        app()->instance('tenant', $c);
        $cmp = Comparison::find($inv->comparison_id);
        abort_unless($cmp && $cmp->status !== 'cancelled', 404);

        return [$c, $inv, $cmp];
    }

    public function isOpen(Comparison $cmp): bool
    {
        return $cmp->status === 'open' && (! $cmp->deadline || $cmp->deadline->gte(now()->startOfDay()));
    }

    public function opened(OfferInvite $inv): void
    {
        if (! $inv->opened_at) {
            $inv->forceFill(['opened_at' => now(), 'status' => $inv->status === 'sent' ? 'opened' : $inv->status])->save();
        }
    }

    /** Offre déposée (ou révisée) en ligne par le fournisseur : elle remplit sa colonne du comparatif. */
    public function submit(Company $c, OfferInvite $inv, Comparison $cmp, array $d, ?UploadedFile $file = null): Comparison
    {
        $this->assert($this->isOpen($cmp), $cmp->status === 'open' ? 'La date limite est passée : les offres sont closes.' : 'Ce comparatif n\'accepte plus d\'offres.');
        $num = fn ($v) => $v === null || trim((string) $v) === '' ? null : (float) str_replace([' ', "\u{202F}", "\u{A0}", ','], ['', '', '', '.'], (string) $v);
        $prices = [];
        $notes = [];
        foreach ((array) $cmp->lines as $l) {
            $p = $num($d['price'][$l['key']] ?? null);
            $this->assert($p === null || $p >= 0, 'Prix négatif refusé.');
            if ($p !== null) {
                $prices[$l['key']] = $p;
            }
            $n = trim((string) ($d['note'][$l['key']] ?? ''));
            if ($n !== '') {
                $notes[$l['key']] = Str::limit($n, 200, '');
            }
        }
        $this->assert($prices !== [], 'Indiquez au moins un prix.');
        $cur = strtoupper((string) ($d['currency'] ?? $c->currency)) ?: $c->currency;
        $this->assert((bool) preg_match('/^[A-Z]{3}$/', $cur), 'Devise invalide.');
        if ($file) {
            $this->assert($file->getSize() <= 10 * 1024 * 1024, 'Fichier trop lourd (10 Mo au plus).');
            $this->assert(in_array(strtolower($file->getClientOriginalExtension()), ['pdf', 'jpg', 'jpeg', 'png', 'xlsx', 'xls', 'docx', 'doc'], true), 'Format de fichier non accepté (PDF, image, Excel ou Word).');
            if ($inv->file_path) {
                Storage::disk('local')->delete($inv->file_path);
            }
            $path = $file->storeAs('appro/offers/'.$c->id, $inv->id.'-'.Str::random(8).'.'.strtolower($file->getClientOriginalExtension()), 'local');
            $inv->forceFill(['file_path' => $path, 'file_name' => Str::limit($file->getClientOriginalName(), 200, '')]);
        }
        $offers = collect((array) $cmp->offers);
        $o = $offers->firstWhere('key', $inv->offer_key) ?? ['key' => $inv->offer_key, 'partner_id' => $inv->partner_id];
        $o = array_merge($o, ['partner_id' => $inv->partner_id, 'partner_name' => $inv->name, 'currency' => $cur, 'rate' => $cur === $c->currency ? 1 : null, 'prices' => $prices,
            'delay_days' => $num($d['delay_days'] ?? null) === null ? null : (int) $num($d['delay_days']), 'extra' => $num($d['extra'] ?? null) ?? 0,
            'payment_terms' => $d['payment_terms'] ?? null, 'validity' => $d['validity'] ?? null, 'notes' => $d['comment'] ?? null, 'compliant' => ! empty($d['compliant'])]);
        // La colonne du fournisseur garde sa place dans la grille
        $list = $offers->contains('key', $inv->offer_key) ? $offers->map(fn ($x) => $x['key'] === $inv->offer_key ? $o : $x)->values()->all() : array_merge($offers->all(), [$o]);
        $cmp = $this->sourcing->saveComparison($c, $cmp, ['offers' => $list], $cmp->user_id);
        // Champs propres à la réponse en ligne (conservés hors du formulaire de l'acheteur)
        $cmp->offers = array_map(fn ($x) => $x['key'] === $inv->offer_key ? $x + ['source' => 'online', 'submitted_at' => now()->toDateTimeString(), 'line_notes' => $notes] : $x, $cmp->offers);
        $cmp->save();
        $first = ! $inv->answered_at;
        $inv->forceFill(['status' => 'answered', 'answered_at' => now(), 'opened_at' => $inv->opened_at ?? now(), 'revisions' => $first ? 0 : $inv->revisions + 1, 'decline_reason' => null])->save();
        $a = $this->sourcing->analyze($c, $cmp);
        $total = collect($a['offers'])->firstWhere('key', $inv->offer_key)['total'] ?? 0;
        AppNotification::send($c->id, $this->buyers($c, $cmp), 'appro', ($first ? 'Offre reçue' : 'Offre révisée').' : '.$inv->name,
            $cmp->number.' · '.$cmp->title.' · '.number_format($total, 0, ',', ' ').' '.$c->currency, 'appro', (string) $cmp->id);

        return $cmp;
    }

    public function decline(Company $c, OfferInvite $inv, Comparison $cmp, ?string $reason): void
    {
        $this->assert($this->isOpen($cmp), 'Ce comparatif est clos.');
        $inv->forceFill(['status' => 'declined', 'decline_reason' => $reason ? Str::limit($reason, 1000, '') : null])->save();
        AppNotification::send($c->id, $this->buyers($c, $cmp), 'appro', 'Consultation déclinée : '.$inv->name, $cmp->number.' · '.($reason ?: 'sans motif'), 'appro', (string) $cmp->id);
    }

    // ------------------------------------------------------------------ questions / réponses

    /** Message du fournisseur (question ou réponse) depuis son lien. */
    public function supplierMessage(Company $c, OfferInvite $inv, Comparison $cmp, string $body): OfferMessage
    {
        $body = trim($body);
        $this->assert($body !== '', 'Écrivez votre message.');
        $m = OfferMessage::create(['company_id' => $c->id, 'comparison_id' => $cmp->id, 'invite_id' => $inv->id, 'direction' => 'in', 'channel' => 'portal', 'body' => Str::limit($body, 5000, ''), 'author' => $inv->name]);
        AppNotification::send($c->id, $this->buyers($c, $cmp), 'appro', 'Message de '.$inv->name, $cmp->number.' · '.Str::limit($body, 120), 'appro', (string) $cmp->id);

        return $m;
    }

    /**
     * Question de l'acheteur à un fournisseur (envoyée par e-mail / WhatsApp avec le lien), ou réponse reçue
     * par téléphone que l'acheteur consigne ($direction = 'in').
     */
    public function buyerMessage(Company $c, Comparison $cmp, OfferInvite $inv, string $body, User $u, string $direction = 'out', string $channel = 'portal'): OfferMessage
    {
        $body = trim($body);
        $this->assert($body !== '', 'Écrivez votre message.');
        $direction = $direction === 'in' ? 'in' : 'out';
        $m = OfferMessage::create(['company_id' => $c->id, 'comparison_id' => $cmp->id, 'invite_id' => $inv->id, 'direction' => $direction, 'channel' => $direction === 'in' ? (in_array($channel, ['phone', 'email', 'whatsapp'], true) ? $channel : 'phone') : 'portal',
            'body' => Str::limit($body, 5000, ''), 'user_id' => $u->id, 'author' => $direction === 'in' ? $inv->name : $u->name, 'read_at' => $direction === 'in' ? now() : null]);
        if ($direction === 'out') {
            $url = $this->url($inv).'#messages';
            $avail = $this->messaging->available($c);
            $via = [];
            if ($inv->email && ($avail['email'] ?? false)) {
                $html = '<p>Bonjour,</p><p>Au sujet de votre offre pour « '.e($cmp->title).' » ('.e($cmp->number).') :</p><blockquote style="border-left:3px solid #1f6feb;margin:0;padding:6px 12px">'.nl2br(e($body)).'</blockquote>'
                    .'<p><a href="'.e($url).'">Répondre en ligne</a></p><p>Cordialement,<br>'.e($u->name).' — '.e($c->name).'</p>';
                if (rescue(fn () => $this->messaging->send($c, 'email', new Message($inv->email, 'Question sur votre offre : '.$cmp->title, $html, null, [], $inv->name), 'appro')->ok, false, false)) {
                    $via[] = 'email';
                }
            }
            if ($inv->phone && ($avail['whatsapp'] ?? false)) {
                if (rescue(fn () => $this->messaging->send($c, 'whatsapp', new Message(to: $inv->phone, text: $c->name.' — question sur votre offre « '.$cmp->title.' » : '.$body."\nRépondre : ".$url, toName: $inv->name), 'appro')->ok, false, false)) {
                    $via[] = 'whatsapp';
                }
            }
            $m->channel = $via ? implode('+', $via) : 'portal';
            $m->save();
        }

        return $m;
    }

    /** Marque comme lus les messages des fournisseurs d'un comparatif. */
    public function markRead(Comparison $cmp): void
    {
        OfferMessage::where('comparison_id', $cmp->id)->where('direction', 'in')->whereNull('read_at')->update(['read_at' => now()]);
    }

    // ------------------------------------------------------------------ relances

    /** Relance les fournisseurs qui n'ont pas répondu, 2 jours avant la date limite (une seule fois). */
    public function remind(Company $c): int
    {
        if (! ($this->messaging->available($c)['email'] ?? false)) {
            return 0;
        }
        $n = 0;
        $cmps = Comparison::where('status', 'open')->whereNotNull('deadline')->whereBetween('deadline', [now()->toDateString(), now()->addDays(2)->toDateString()])->get()->keyBy('id');
        if ($cmps->isEmpty()) {
            return 0;
        }
        foreach (OfferInvite::whereIn('comparison_id', $cmps->keys())->whereIn('status', ['sent', 'opened'])->whereNull('reminded_at')->whereNotNull('email')->get() as $inv) {
            $cmp = $cmps[$inv->comparison_id];
            $html = '<p>Bonjour,</p><p>Petit rappel : nous attendons votre offre pour « '.e($cmp->title).' » avant le <b>'.$cmp->deadline->format('d/m/Y').'</b>.</p><p><a href="'.e($this->url($inv)).'">Répondre en ligne</a> (ou nous dire que vous ne donnez pas suite).</p><p>Cordialement,<br>'.e($c->name).'</p>';
            if (rescue(fn () => $this->messaging->send($c, 'email', new Message($inv->email, 'Rappel : demande de prix '.$cmp->title, $html, null, [], $inv->name), 'appro')->ok, false, false)) {
                $inv->forceFill(['reminded_at' => now()])->save();
                $n++;
            }
        }

        return $n;
    }

    // ------------------------------------------------------------------ données

    protected function buyers(Company $c, Comparison $cmp)
    {
        return User::where('company_id', $c->id)->where('is_active', true)->get()->filter(fn ($u) => $u->id === $cmp->user_id || $u->hasPermission('appro.manage'))->pluck('id')->all();
    }

    public function invitesToApi(Comparison $cmp): array
    {
        $msgs = OfferMessage::where('comparison_id', $cmp->id)->orderBy('id')->get();
        $users = User::whereIn('id', $msgs->pluck('user_id')->filter()->unique())->pluck('name', 'id');

        return [
            'invites' => OfferInvite::where('comparison_id', $cmp->id)->orderBy('id')->get()->map(fn ($i) => ['id' => $i->id, 'partner_id' => $i->partner_id, 'name' => $i->name, 'email' => $i->email, 'phone' => $i->phone,
                'offer_key' => $i->offer_key, 'status' => $i->status, 'status_label' => self::INVITE_STATUSES[$i->status] ?? $i->status, 'url' => $this->url($i),
                'sent_at' => $i->sent_at, 'opened_at' => $i->opened_at, 'answered_at' => $i->answered_at, 'reminded_at' => $i->reminded_at, 'revisions' => $i->revisions,
                'decline_reason' => $i->decline_reason, 'file_name' => $i->file_name, 'has_file' => (bool) $i->file_path,
                'unread' => $msgs->where('invite_id', $i->id)->where('direction', 'in')->whereNull('read_at')->count()])->values()->all(),
            'messages' => $msgs->map(fn ($m) => ['id' => $m->id, 'invite_id' => $m->invite_id, 'direction' => $m->direction, 'channel' => $m->channel, 'body' => $m->body,
                'author' => $m->author ?? ($users[$m->user_id] ?? null), 'at' => $m->created_at, 'read' => $m->read_at !== null])->values()->all(),
        ];
    }

    public function counts(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $inv = OfferInvite::whereIn('comparison_id', $ids)->get()->groupBy('comparison_id');
        $unread = OfferMessage::whereIn('comparison_id', $ids)->where('direction', 'in')->whereNull('read_at')->get()->countBy('comparison_id');

        return collect($ids)->mapWithKeys(fn ($id) => [$id => ['invited' => ($inv[$id] ?? collect())->count(), 'answered' => ($inv[$id] ?? collect())->where('status', 'answered')->count(),
            'declined' => ($inv[$id] ?? collect())->where('status', 'declined')->count(), 'unread' => $unread[$id] ?? 0]])->all();
    }
}
