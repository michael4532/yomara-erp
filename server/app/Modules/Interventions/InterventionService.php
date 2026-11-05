<?php

namespace App\Modules\Interventions;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Core\Models\Partner;
use App\Modules\Core\Models\Product;
use App\Modules\Interventions\Models\Contract;
use App\Modules\Interventions\Models\Equipment;
use App\Modules\Interventions\Models\Intervention;
use App\Modules\Stock\Models\StockMove;
use App\Modules\Stock\Models\Warehouse;
use App\Modules\Ventes\Models\SalesDocument;
use App\Services\EntityWriter;
use Carbon\Carbon;
use Illuminate\Support\Str;

class InterventionService
{
    public function __construct(protected MessagingService $messaging, protected EntityWriter $writer) {}

    /** Après chaque enregistrement : stock, contrat, technicien et client prévenus. */
    public function afterSave(Intervention $i): void
    {
        $company = Company::find($i->company_id);
        if (! $company) {
            return;
        }
        if ($i->wasChanged('status') || $i->wasRecentlyCreated) {
            if ($i->status === 'done') {
                $this->postStock($company, $i);
                $this->advanceContract($i);
            } elseif ($i->stock_posted && in_array($i->status, ['planned', 'en_route', 'in_progress', 'cancelled'], true)) {
                $this->reverseStock($company, $i);
            }
            if (! $i->wasRecentlyCreated && $i->notify && in_array($i->status, ['en_route', 'done'], true)) {
                $this->notifyCustomer($company, $i);
            }
        } elseif ($i->status === 'done' && $i->wasChanged('parts') && $i->stock_posted) {
            // Pièces corrigées après coup par le responsable : on rejoue le stock
            $this->reverseStock($company, $i);
            $this->postStock($company, $i);
        }
        if ($i->technician_id && ($i->wasRecentlyCreated || $i->wasChanged('technician_id') || $i->wasChanged('scheduled_at')) && ! in_array($i->status, ['done', 'cancelled'], true)
            && $i->technician_id !== auth()->id()) {
            AppNotification::send($i->company_id, [$i->technician_id], 'intervention', 'Intervention '.$i->number.' : '.$i->title,
                trim(($i->scheduled_at ? 'Le '.$i->scheduled_at->format('d/m/Y à H:i') : 'À planifier').' · '.($i->address ?: '').' '.($i->city ?: '')), 'interventions', $i->id);
        }
    }

    // ---------- Stock ----------

    public function postStock(Company $company, Intervention $i): void
    {
        if ($i->stock_posted || ! $company->hasModule('stock') || empty($i->parts)) {
            return;
        }
        $products = Product::whereIn('id', collect($i->parts)->pluck('product_id')->filter())->get()->keyBy('id');
        $warehouse = Warehouse::defaultFor($company->id)->id;
        foreach ($i->parts as $p) {
            $prod = ! empty($p['product_id']) ? $products->get($p['product_id']) : null;
            if (! $prod || $prod->type !== 'product' || ! $prod->track_stock) {
                continue;
            }
            $m = new StockMove(['product_id' => $prod->id, 'warehouse_id' => $warehouse, 'quantity' => -abs((float) $p['quantity']), 'type' => 'sale',
                'unit_cost' => $prod->purchase_price, 'reference' => $i->number, 'date' => now()->toDateString(), 'notes' => 'Pièces posées — intervention '.$i->number]);
            $m->company_id = $company->id;
            $m->document_id = $i->id;
            $m->save();
        }
        $i->forceFill(['stock_posted' => true])->saveQuietly();
    }

    public function reverseStock(Company $company, Intervention $i): void
    {
        $moves = StockMove::where('document_id', $i->id)->get()->groupBy(fn ($m) => $m->product_id.'|'.$m->warehouse_id);
        foreach ($moves as $g) {
            $qty = round($g->sum('quantity'), 3);
            if ($qty == 0) {
                continue;
            }
            $m = new StockMove(['product_id' => $g->first()->product_id, 'warehouse_id' => $g->first()->warehouse_id, 'quantity' => -$qty, 'type' => 'adjustment',
                'reference' => $i->number, 'date' => now()->toDateString(), 'notes' => 'Annulation des pièces — intervention '.$i->number]);
            $m->company_id = $company->id;
            $m->document_id = $i->id;
            $m->save();
        }
        $i->forceFill(['stock_posted' => false])->saveQuietly();
    }

    // ---------- Contrats ----------

    protected function advanceContract(Intervention $i): void
    {
        if ($i->type !== 'preventive' || ! $i->contract_id) {
            return;
        }
        $c = Contract::find($i->contract_id);
        // La prochaine visite part de la date prévue de celle-ci (si elle n'a pas déjà été avancée à la création)
        if ($c && $c->next_visit && $i->scheduled_at && $c->next_visit->lte($i->scheduled_at->copy()->startOfDay())) {
            $c->next_visit = $c->advance($i->scheduled_at);
            $c->save();
        }
    }

    /** Crée les visites préventives des contrats dont l'échéance arrive (dans $days jours). */
    public function generateVisits(Company $company, int $days = 7): array
    {
        $created = [];
        $limit = today()->addDays($days);
        foreach (Contract::where('status', 'active')->where('frequency', '!=', 'none')->whereNotNull('next_visit')->whereDate('next_visit', '<=', $limit)->get() as $c) {
            $guard = 0;
            while ($c->next_visit && $c->next_visit->lte($limit) && (! $c->end_date || $c->next_visit->lte($c->end_date)) && $guard++ < 12) {
                $date = $c->next_visit->copy();
                $exists = Intervention::where('contract_id', $c->id)->where('type', 'preventive')->whereDate('scheduled_at', $date->toDateString())->exists();
                if (! $exists && $date->gte(today()->subDays(31))) {
                    $created[] = $this->createVisit($company, $c, $date);
                }
                $c->next_visit = $c->advance($date);
            }
            if ($c->end_date && $c->next_visit && $c->next_visit->gt($c->end_date)) {
                $c->next_visit = null;
            }
            $c->save();
        }

        return $created;
    }

    protected function createVisit(Company $company, Contract $c, Carbon $date): Intervention
    {
        $p = Partner::find($c->partner_id);
        $equipments = Equipment::where('contract_id', $c->id)->where('status', '!=', 'retired')->get();
        $list = $company->settings['intervention_checklist'] ?? ['Contrôle visuel et nettoyage', 'Vérification du fonctionnement', 'Relevé des mesures', 'Conseils au client'];
        $checklist = [];
        foreach ($equipments->count() > 1 ? $equipments : [null] as $e) {
            foreach ($list as $t) {
                $checklist[] = ['text' => ($e ? $e->name.' — ' : '').$t, 'done' => false];
            }
        }
        $i = new Intervention([
            'partner_id' => $c->partner_id, 'contract_id' => $c->id, 'equipment_id' => $equipments->count() === 1 ? $equipments->first()->id : null,
            'type' => 'preventive', 'priority' => 1, 'title' => 'Visite préventive — '.$c->name, 'address' => $p?->address, 'city' => $p?->city,
            'contact_name' => $p?->name, 'contact_phone' => $p?->phone, 'scheduled_at' => $date->copy()->setTime(8, 30), 'duration_minutes' => $c->visit_minutes,
            'technician_id' => $c->technician_id, 'source' => 'contract', 'checklist' => $checklist, 'billable' => ! $c->labor_included,
        ]);
        $i->status = $c->technician_id ? 'planned' : 'requested';
        $i->save();

        return $i;
    }

    // ---------- Facturation ----------

    public function invoice(Company $company, Intervention $i): SalesDocument
    {
        if (! $company->hasModule('ventes')) {
            throw new \DomainException('Le module Ventes & Facturation est nécessaire pour facturer.');
        }
        if ($i->invoice_id) {
            throw new \DomainException('Cette intervention est déjà facturée.');
        }
        if ($i->status !== 'done') {
            throw new \DomainException('Terminez l\'intervention avant de la facturer.');
        }
        if (! $i->partner_id) {
            throw new \DomainException('Indiquez le client de l\'intervention.');
        }
        $c = $i->contract_id ? Contract::find($i->contract_id) : null;
        $tax = (float) ($company->settings['tax_rate'] ?? 18);
        $lines = [];
        $laborCovered = $c && $c->labor_included && $c->isActiveOn($i->finished_at ?? now());
        $partsCovered = $c && $c->parts_included && $c->isActiveOn($i->finished_at ?? now());
        if ($i->billable && ! $laborCovered && $i->worked_minutes && $i->labor_rate > 0) {
            $hours = max(0.25, ceil($i->worked_minutes / 15) / 4); // au quart d'heure supérieur
            $lines[] = ['description' => 'Main-d\'œuvre — '.$i->title, 'quantity' => $hours, 'unit' => 'h', 'unit_price' => $i->labor_rate, 'tax_rate' => $tax];
        }
        if ($i->billable && ! $laborCovered && $i->travel_fee > 0) {
            $lines[] = ['description' => 'Déplacement', 'quantity' => 1, 'unit' => 'forfait', 'unit_price' => $i->travel_fee, 'tax_rate' => $tax];
        }
        if (! $partsCovered) {
            $taxes = Product::whereIn('id', collect($i->parts ?? [])->pluck('product_id')->filter())->pluck('tax_rate', 'id');
            foreach ($i->parts ?? [] as $p) {
                $lines[] = ['product_id' => $p['product_id'] ?? null, 'description' => $p['description'], 'quantity' => (float) $p['quantity'], 'unit_price' => (float) ($p['unit_price'] ?? 0),
                    'tax_rate' => isset($p['product_id'], $taxes[$p['product_id']]) ? (float) $taxes[$p['product_id']] : $tax];
            }
        }
        if (! $lines) {
            throw new \DomainException($c ? 'Rien à facturer : l\'intervention est couverte par le contrat '.$c->number.'.' : 'Rien à facturer (ni temps, ni déplacement, ni pièces).');
        }
        $id = (string) Str::uuid();
        SalesDocument::$systemWrite = true;
        try {
            $res = $this->writer->upsert(SalesDocument::class, $id, [
                'type' => 'invoice', 'partner_id' => $i->partner_id, 'date' => now()->toDateString(), 'reference' => $i->number,
                'notes' => 'Intervention '.$i->number.' du '.($i->finished_at ?? now())->format('d/m/Y').($i->signed_by ? ' — rapport signé par '.$i->signed_by : '').".\nRapport : ".$i->reportUrl(),
                'lines' => array_map(fn ($l) => $l + ['id' => (string) Str::uuid(), 'discount_percent' => 0], $lines),
            ]);
        } finally {
            SalesDocument::$systemWrite = false;
        }
        if ($res['status'] !== EntityWriter::APPLIED) {
            throw new \DomainException($res['message'] ?? 'Facture refusée.');
        }
        $doc = $res['record'];
        if ($i->stock_posted) {
            // Les pièces sont déjà sorties du stock à la fin de l'intervention
            $doc->stock_posted = true;
            $doc->saveQuietly();
        }
        $i->forceFill(['invoice_id' => $doc->id])->save();

        return $doc;
    }

    /** Facture de la période suivante d'un contrat. */
    public function invoiceContract(Company $company, Contract $c): SalesDocument
    {
        if (! $company->hasModule('ventes')) {
            throw new \DomainException('Le module Ventes & Facturation est nécessaire pour facturer.');
        }
        if ($c->billing === 'none' || $c->price <= 0) {
            throw new \DomainException('Ce contrat n\'a pas de montant à facturer.');
        }
        $from = $c->invoiced_until ? $c->invoiced_until->copy()->addDay() : $c->start_date->copy();
        if ($c->end_date && $from->gt($c->end_date)) {
            throw new \DomainException('Le contrat est entièrement facturé.');
        }
        $months = ['monthly' => 1, 'quarterly' => 3, 'annual' => 12][$c->billing] ?? 1;
        $to = $from->copy()->addMonthsNoOverflow($months)->subDay();
        if ($c->end_date && $to->gt($c->end_date)) {
            $to = $c->end_date->copy();
        }
        SalesDocument::$systemWrite = true;
        try {
            $res = $this->writer->upsert(SalesDocument::class, (string) Str::uuid(), [
                'type' => 'invoice', 'partner_id' => $c->partner_id, 'date' => now()->toDateString(), 'reference' => $c->number,
                'lines' => [['id' => (string) Str::uuid(), 'description' => 'Contrat de maintenance '.$c->number.' — '.$c->name.' — du '.$from->format('d/m/Y').' au '.$to->format('d/m/Y'),
                    'quantity' => 1, 'unit' => 'forfait', 'unit_price' => $c->price, 'tax_rate' => (float) ($company->settings['tax_rate'] ?? 18), 'discount_percent' => 0]],
            ]);
        } finally {
            SalesDocument::$systemWrite = false;
        }
        if ($res['status'] !== EntityWriter::APPLIED) {
            throw new \DomainException($res['message'] ?? 'Facture refusée.');
        }
        $c->forceFill(['invoiced_until' => $to])->save();

        return $res['record'];
    }

    // ---------- Client ----------

    public function notifyCustomer(Company $company, Intervention $i): void
    {
        if (($company->settings['intervention_notify'] ?? true) === false) {
            return;
        }
        $partner = $i->partner_id ? Partner::find($i->partner_id) : null;
        $phone = $i->contact_phone ?: $partner?->phone;
        $email = $partner?->email;
        $name = $i->contact_name ?: $partner?->name ?: '';
        $tech = $i->technician_id ? User::find($i->technician_id)?->name : null;
        $first = $tech ? explode(' ', $tech)[0] : null;
        $text = $i->status === 'en_route'
            ? "Bonjour $name, notre technicien".($first ? " $first" : '')." est en route pour l'intervention {$i->number} ({$i->title})."
            : "Bonjour $name, l'intervention {$i->number} ({$i->title}) est terminée. Merci de votre confiance. Rapport d'intervention : ".$i->reportUrl();
        $text .= "\n— {$company->name}";
        $available = $this->messaging->available($company);
        try {
            if ($phone && ($available['whatsapp'] ?? null)) {
                $this->messaging->send($company, 'whatsapp', new Message(to: $phone, toName: $name, text: $text), 'intervention:'.$i->id);
            } elseif ($phone && ($available['sms'] ?? null)) {
                $this->messaging->send($company, 'sms', new Message(to: $phone, toName: $name, text: $text), 'intervention:'.$i->id);
            } elseif ($email && ($available['email'] ?? null)) {
                $this->messaging->send($company, 'email', new Message(to: $email, toName: $name, subject: ($i->status === 'done' ? 'Rapport d\'intervention ' : 'Intervention ').$i->number.' — '.$company->name,
                    html: nl2br(e($text)), text: $text, replyTo: $company->email), 'intervention:'.$i->id);
            }
        } catch (\Throwable) {
            // Un message non envoyé ne bloque jamais l'intervention
        }
    }

    /** Demande d'intervention déposée par un client (portail). */
    public function request(Company $company, Partner $partner, array $data): Intervention
    {
        $i = new Intervention([
            'partner_id' => $partner->id, 'equipment_id' => $data['equipment_id'] ?? null, 'type' => 'corrective', 'priority' => (int) ($data['priority'] ?? 1),
            'title' => $data['title'], 'description' => $data['description'] ?? null, 'address' => $data['address'] ?? $partner->address, 'city' => $partner->city,
            'contact_name' => $data['contact_name'] ?? $partner->name, 'contact_phone' => $data['contact_phone'] ?? $partner->phone, 'source' => 'portal',
        ]);
        if (! empty($data['equipment_id'])) {
            $e = Equipment::find($data['equipment_id']);
            if ($e?->contract_id && Contract::find($e->contract_id)?->isActiveOn(now())) {
                $i->contract_id = $e->contract_id;
                $i->billable = ! Contract::find($e->contract_id)->labor_included;
            }
        }
        $i->status = 'requested';
        $i->save();
        $users = User::where('company_id', $company->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('interventions.manage'))->pluck('id');
        AppNotification::send($company->id, $users, 'intervention', 'Demande d\'intervention'.($i->priority >= 3 ? ' URGENTE' : '').' : '.$partner->name,
            $i->title.($i->description ? ' — '.Str::limit($i->description, 120) : ''), 'interventions', $i->id);

        return $i;
    }
}
