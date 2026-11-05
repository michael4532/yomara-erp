<?php

namespace App\Modules\Signature\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Signer extends Model
{
    use BelongsToCompany;

    protected $table = 'sg_signers';

    protected $fillable = ['position', 'name', 'label', 'email', 'phone', 'user_id'];

    protected $hidden = ['token', 'token_hash', 'otp_hash', 'signature_path', 'company_id', 'user_agent'];

    public const STATUSES = ['waiting' => 'En attente de son tour', 'pending' => 'À signer', 'viewed' => 'Document ouvert', 'signed' => 'Signé', 'declined' => 'Refusé'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'otp_expires_at' => 'datetime', 'otp_sent_at' => 'datetime', 'verified_at' => 'datetime', 'notified_at' => 'datetime',
            'viewed_at' => 'datetime', 'signed_at' => 'datetime', 'declined_at' => 'datetime', 'position' => 'integer', 'user_id' => 'integer'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SignRequest::class, 'request_id');
    }

    public function done(): bool
    {
        return in_array($this->status, ['signed', 'declined'], true);
    }

    public function link(): string
    {
        return rtrim(config('app.url'), '/').'/signer/'.$this->token;
    }

    /** Coordonnée masquée pour le certificat : +225 07 •• •• 44, a•••@exemple.ci */
    public static function mask(?string $v): ?string
    {
        if (! $v) {
            return null;
        }
        if (str_contains($v, '@')) {
            [$u, $d] = explode('@', $v, 2);

            return mb_substr($u, 0, 1).'•••@'.$d;
        }
        $n = preg_match_all('/\d/', $v);
        if ($n < 6) {
            return $v;
        }
        $keep = $n >= 12 ? $n - 8 : 2;
        $k = -1;

        return preg_replace_callback('/\d/', function ($m) use (&$k, $keep, $n) {
            $k++;

            return $k < $keep || $k >= $n - 2 ? $m[0] : '•';
        }, $v);
    }
}
