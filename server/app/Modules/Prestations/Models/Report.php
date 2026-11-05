<?php

namespace App\Modules\Prestations\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Rapport d'activité mensuel d'un client (PDF généré par le serveur). */
class Report extends Model
{
    use Syncable;

    protected $table = 'pr_reports';

    public static string $syncEntity = 'pr_reports';

    public static string $viewPermission = 'prestations.view|prestations.manage';

    public static string $managePermission = 'prestations.manage';

    protected $fillable = [];

    protected $hidden = ['company_id', 'deleted_at', 'file_path', 'token'];

    protected function casts(): array
    {
        return ['hours' => 'float', 'deliverables' => 'integer', 'sent_at' => 'datetime', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        throw new \DomainException('Les rapports d\'activité sont générés par le serveur.');
    }

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->token ??= Str::random(40));
    }

    public function publicUrl(): string
    {
        return url('/rapport/'.$this->token);
    }

    public function label(): string
    {
        $m = ['01' => 'janvier', '02' => 'février', '03' => 'mars', '04' => 'avril', '05' => 'mai', '06' => 'juin', '07' => 'juillet', '08' => 'août', '09' => 'septembre', '10' => 'octobre', '11' => 'novembre', '12' => 'décembre'];

        return ($m[substr($this->period, 5, 2)] ?? '').' '.substr($this->period, 0, 4);
    }

    public function toSyncArray(): array
    {
        return $this->toArray() + ['url' => $this->publicUrl(), 'label' => $this->label()];
    }
}
