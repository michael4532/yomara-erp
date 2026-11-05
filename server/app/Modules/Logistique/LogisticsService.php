<?php

namespace App\Modules\Logistique;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\Company;
use App\Models\User;
use App\Modules\Core\Models\Partner;
use App\Modules\Logistique\Models\Delivery;
use App\Modules\Logistique\Models\DeliveryEvent;
use App\Modules\Logistique\Models\Tour;
use App\Modules\Ventes\Models\SalesDocument;
use App\Modules\Ventes\Models\SalesPayment;
use App\Services\EntityWriter;
use Illuminate\Support\Str;

class LogisticsService
{
    public function __construct(protected MessagingService $messaging, protected EntityWriter $writer) {}

    /** Après chaque enregistrement : historique, tournée, encaissement, message au client. */
    public function afterSave(Delivery $d): void
    {
        $company = Company::find($d->company_id);
        if (! $company) {
            return;
        }
        $statusChanged = $d->wasRecentlyCreated || $d->wasChanged('status');
        if ($statusChanged) {
            DeliveryEvent::create(['company_id' => $d->company_id, 'delivery_id' => $d->id, 'status' => $d->status, 'user_id' => auth()->id(),
                'note' => $d->status === 'failed' ? $d->failed_reason : ($d->status === 'delivered' && $d->signed_by ? 'Reçu par '.$d->signed_by : null)]);
        }
        foreach (array_filter(array_unique([$d->tour_id, $d->getPrevious()['tour_id'] ?? null])) as $tid) {
            Tour::find($tid)?->refreshStatus();
        }
        if ($d->status === 'delivered' && $d->cod_collected > 0 && ! $d->payment_id) {
            $this->recordCod($company, $d);
        }
        if ($statusChanged && ! $d->wasRecentlyCreated && $d->notify && in_array($d->status, ['in_transit', 'delivered', 'failed'], true)) {
            $this->notifyCustomer($company, $d);
        }
    }

    /** Paiement à la livraison : enregistré sur la facture d'origine (si elle est validée). */
    public function recordCod(Company $company, Delivery $d): void
    {
        $doc = $d->source_document_id ? SalesDocument::find($d->source_document_id) : null;
        if (! $doc || $doc->type !== 'invoice' || $doc->status !== 'validated' || ! $company->hasModule('ventes')) {
            return;
        }
        $due = round($doc->total - $doc->amount_paid, 2);
        $amount = min($due, (float) $d->cod_collected);
        if ($amount <= 0) {
            return;
        }
        // Le livreur n'a pas le droit d'encaisser des factures : l'écriture est faite par le système, puis attribuée au livreur
        SalesPayment::$systemWrite = true;
        try {
            $res = $this->writer->upsert(SalesPayment::class, (string) Str::uuid(), [
                'document_id' => $doc->id, 'date' => ($d->delivered_at ?? now())->toDateString(), 'amount' => $amount,
                'method' => $d->cod_method ?: 'cash', 'reference' => $d->number, 'notes' => 'Encaissé à la livraison',
            ]);
        } finally {
            SalesPayment::$systemWrite = false;
        }
        if ($res['status'] === EntityWriter::APPLIED) {
            $p = $res['record'];
            $p->forceFill(['user_id' => $d->driver_id])->saveQuietly();
            $d->forceFill(['payment_id' => $p->id])->saveQuietly();
        }
    }

    public function notifyCustomer(Company $company, Delivery $d): void
    {
        if (($company->settings['delivery_notify'] ?? true) === false) {
            return;
        }
        $partner = $d->partner_id ? Partner::find($d->partner_id) : null;
        $phone = $d->recipient_phone ?: $partner?->phone;
        $email = $partner?->email;
        $name = $d->recipient_name ?: $partner?->name ?: '';
        $driver = $d->driver_id ? User::find($d->driver_id)?->name : null;
        $first = $driver ? explode(' ', $driver)[0] : null;
        $text = match ($d->status) {
            'in_transit' => "Bonjour $name, votre livraison {$d->number} de {$company->name} est en route".($first ? " avec $first" : '').'.',
            'delivered' => "Bonjour $name, votre livraison {$d->number} de {$company->name} a bien été remise".($d->signed_by ? " à {$d->signed_by}" : '').'. Merci !',
            default => "Bonjour $name, nous n'avons pas pu effectuer votre livraison {$d->number} aujourd'hui".($d->failed_reason ? " ({$d->failed_reason})" : '').'. Nous vous recontactons pour une nouvelle date.',
        };
        $text .= "\nSuivi : ".$d->trackingUrl();
        $available = $this->messaging->available($company);
        try {
            if ($phone && ($available['whatsapp'] ?? null)) {
                $this->messaging->send($company, 'whatsapp', new Message(to: $phone, toName: $name, text: $text), 'delivery:'.$d->id);
            } elseif ($phone && ($available['sms'] ?? null)) {
                $this->messaging->send($company, 'sms', new Message(to: $phone, toName: $name, text: $text), 'delivery:'.$d->id);
            } elseif ($email && ($available['email'] ?? null)) {
                $this->messaging->send($company, 'email', new Message(to: $email, toName: $name, subject: 'Votre livraison '.$d->number.' — '.$company->name,
                    html: nl2br(e($text)), text: $text, replyTo: $company->email), 'delivery:'.$d->id);
            }
        } catch (\Throwable) {
            // Un message non envoyé ne bloque jamais la livraison
        }
    }
}
