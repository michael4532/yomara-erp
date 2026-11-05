<?php

namespace App\Integrations;

/** Catalogue des événements diffusés par webhook. */
class Events
{
    /** Données suivies : {entité}.created / .updated / .deleted */
    public const ENTITIES = [
        'partners' => 'Clients & fournisseurs',
        'products' => 'Articles',
        'sales_documents' => 'Documents de vente (devis, commandes, factures, tickets)',
        'sales_payments' => 'Encaissements clients',
        'purchase_documents' => 'Documents d\'achat',
        'purchase_payments' => 'Règlements fournisseurs',
        'stock_moves' => 'Mouvements de stock',
        'crm_leads' => 'Opportunités CRM',
        'ag_events' => 'Événements d\'agenda',
        'rdv_appointments' => 'Rendez-vous',
        'hd_tickets' => 'Tickets du service client',
        'prj_tasks' => 'Tâches de projet',
        'rh_employees' => 'Salariés',
        'tr_operations' => 'Opérations de trésorerie',
        'lg_deliveries' => 'Livraisons',
        'it_interventions' => 'Interventions',
        'rs_orders' => 'Commandes restaurant',
    ];

    /** Événements métier */
    public const BUSINESS = [
        'invoice.validated' => 'Facture validée',
        'invoice.paid' => 'Facture entièrement payée',
        'quote.created' => 'Devis créé',
        'order.created' => 'Commande client créée (dont boutique en ligne)',
        'order.validated' => 'Commande client confirmée',
        'order.cancelled' => 'Commande client annulée',
        'receipt.created' => 'Vente en caisse',
        'payment.received' => 'Encaissement reçu',
        'payment.online' => 'Paiement en ligne confirmé (Mobile Money, carte)',
        'supplier_invoice.validated' => 'Facture fournisseur validée',
        'lead.won' => 'Opportunité gagnée',
        'lead.lost' => 'Opportunité perdue',
        'appointment.booked' => 'Rendez-vous pris',
        'ticket.created' => 'Ticket ouvert par un client',
        'ticket.resolved' => 'Ticket résolu ou fermé',
        'appointment.completed' => 'Rendez-vous honoré',
        'meeting.report_ready' => 'Compte rendu de réunion prêt',
        'webinar.registration' => 'Inscription à un webinaire',
        'ping' => 'Test de connexion',
    ];

    public static function catalog(): array
    {
        $out = [];
        foreach (self::BUSINESS as $k => $l) {
            if ($k !== 'ping') {
                $out[] = ['key' => $k, 'label' => $l, 'group' => 'Événements métier'];
            }
        }
        foreach (self::ENTITIES as $e => $l) {
            foreach (['created' => 'création', 'updated' => 'modification', 'deleted' => 'suppression'] as $op => $ol) {
                $out[] = ['key' => "$e.$op", 'label' => "$l : $ol", 'group' => $l];
            }
        }

        return $out;
    }

    /** L'abonnement couvre-t-il l'événement ? (« * », « partners.* », clé exacte) */
    public static function matches(array $subscribed, string $event): bool
    {
        foreach ($subscribed as $s) {
            if ($s === '*' || $s === $event || (str_ends_with($s, '.*') && str_starts_with($event, substr($s, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    /** Événements métier déduits d'une écriture (entité, création, champs modifiés, valeurs). */
    /** @var callable[] écouteurs internes des événements métier (avis Google, automatisations…) */
    protected static array $listeners = [];

    /** Abonne un module aux événements métier : fn (int $companyId, string $event, Model $model). */
    public static function listen(callable $fn): void
    {
        self::$listeners[] = $fn;
    }

    /** Appelé après chaque écriture d'une entité synchronisée : transmet les événements métier dérivés aux écouteurs. */
    public static function fire(\Illuminate\Database\Eloquent\Model $m, string $op): void
    {
        if (! self::$listeners || $op === 'deleted' || ! $m->company_id || ! property_exists($m, 'syncEntity')) {
            return;
        }
        foreach (self::derive($m::$syncEntity, $op === 'created', $m->getChanges(), $m->getAttributes()) as $ev) {
            foreach (self::$listeners as $fn) {
                try {
                    $fn((int) $m->company_id, $ev, $m);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Événement '.$ev.' : '.$e->getMessage());
                }
            }
        }
    }

    public static function derive(string $entity, bool $created, array $changes, array $attrs): array
    {
        $ev = [];
        $type = $attrs['type'] ?? null;
        $became = fn (string $f, $v) => array_key_exists($f, $changes) && $changes[$f] === $v || ($created && ($attrs[$f] ?? null) === $v);
        if ($entity === 'sales_documents') {
            if ($type === 'invoice' && $became('status', 'validated')) {
                $ev[] = 'invoice.validated';
            }
            if (in_array($type, ['invoice', 'receipt'], true) && $became('payment_status', 'paid') && ! $created) {
                $ev[] = 'invoice.paid';
            }
            if ($created && $type === 'quote') {
                $ev[] = 'quote.created';
            }
            if ($created && $type === 'order') {
                $ev[] = 'order.created';
            }
            if ($type === 'order' && ! $created && $became('status', 'validated')) {
                $ev[] = 'order.validated';
            }
            if ($type === 'order' && ! $created && $became('status', 'cancelled')) {
                $ev[] = 'order.cancelled';
            }
            if ($created && $type === 'receipt') {
                $ev[] = 'receipt.created';
            }
        } elseif ($entity === 'sales_payments' && $created) {
            $ev[] = 'payment.received';
        } elseif ($entity === 'purchase_documents' && $type === 'supplier_invoice' && $became('status', 'validated')) {
            $ev[] = 'supplier_invoice.validated';
        } elseif ($entity === 'crm_leads') {
            if ($became('status', 'won') && ! $created) {
                $ev[] = 'lead.won';
            }
            if ($became('status', 'lost') && ! $created) {
                $ev[] = 'lead.lost';
            }
        } elseif ($entity === 'rdv_appointments') {
            if ($created) {
                $ev[] = 'appointment.booked';
            }
            if ($became('status', 'done') && ! $created) {
                $ev[] = 'appointment.completed';
            }
        } elseif ($entity === 'hd_tickets') {
            if ($created) {
                $ev[] = 'ticket.created';
            }
            if (($became('status', 'resolved') || $became('status', 'closed')) && ! $created) {
                $ev[] = 'ticket.resolved';
            }
        }

        return $ev;
    }
}
