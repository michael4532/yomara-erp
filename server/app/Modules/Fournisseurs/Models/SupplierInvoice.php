<?php

namespace App\Modules\Fournisseurs\Models;

use App\Models\Concerns\Syncable;
use App\Modules\Fournisseurs\FournisseursService;
use Illuminate\Database\Eloquent\Model;

/** Facture déposée par un fournisseur ; acceptée, elle devient une facture fournisseur des Achats. */
class SupplierInvoice extends Model
{
    use Syncable;

    protected $table = 'sp_invoices';

    public static string $syncEntity = 'sp_invoices';

    public static string $viewPermission = 'portail_fournisseurs.view|portail_fournisseurs.manage';

    public static string $managePermission = 'portail_fournisseurs.manage';

    protected $fillable = ['status', 'reject_reason'];

    protected $hidden = ['company_id', 'deleted_at', 'file_path'];

    public ?string $fromStatus = null;

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'amount' => 'float', 'version' => 'integer', 'extracted' => 'array'];
    }

    public static function syncRules(): array
    {
        return ['status' => 'required|in:accepted,rejected', 'reject_reason' => 'nullable|string|max:1000'];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if ($creating) {
            throw new \DomainException('Les factures se déposent sur le portail fournisseur ou s\'importent depuis un PDF.');
        }
        if ($this->status !== 'received') {
            throw new \DomainException('Facture déjà '.($this->status === 'accepted' ? 'acceptée' : 'refusée').'.');
        }
        if (($data['status'] ?? null) === 'rejected' && trim((string) ($data['reject_reason'] ?? '')) === '') {
            throw new \DomainException('Indiquez au fournisseur pourquoi la facture est refusée.');
        }
        $this->fromStatus = $this->status;
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        app(FournisseursService::class)->afterInvoiceDecision($this);
    }

    public function beforeEntityDelete(): void
    {
        throw new \DomainException('Une facture déposée ne se supprime pas : refusez-la.');
    }

    public function toSyncArray(): array
    {
        $m = rescue(fn () => app(FournisseursService::class)->match($this), null, false);

        return $this->toArray() + ['has_file' => (bool) $this->file_path, 'match' => $m ? array_intersect_key($m, array_flip(['status', 'label', 'order_number', 'ordered', 'received', 'invoiced_before', 'expected', 'gap'])) : null];
    }
}
