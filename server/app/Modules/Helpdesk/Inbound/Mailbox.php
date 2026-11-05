<?php

namespace App\Modules\Helpdesk\Inbound;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/** Réception d'une société : jeton des webhooks, boîte IMAP relevée toutes les 5 minutes. */
class Mailbox extends Model
{
    protected $table = 'hd_mailboxes';

    protected $guarded = ['id'];

    protected $hidden = ['imap_password_enc'];

    protected function casts(): array
    {
        return ['imap_enabled' => 'boolean', 'whatsapp_enabled' => 'boolean', 'imap_port' => 'integer', 'last_uid' => 'integer', 'last_check_at' => 'datetime', 'received' => 'integer'];
    }

    public static function for(int $companyId): self
    {
        return static::firstOrCreate(['company_id' => $companyId], ['token' => Str::random(40)]);
    }

    public function password(): ?string
    {
        try {
            return $this->imap_password_enc ? Crypt::decryptString($this->imap_password_enc) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function urls(): array
    {
        return ['mail_webhook' => url('/api/hooks/mail/'.$this->token), 'whatsapp_webhook' => url('/api/hooks/whatsapp/'.$this->token)];
    }

    public function toPublic(): array
    {
        return ['address' => $this->address, 'imap_enabled' => $this->imap_enabled, 'imap_host' => $this->imap_host, 'imap_port' => $this->imap_port, 'imap_encryption' => $this->imap_encryption,
            'imap_username' => $this->imap_username, 'imap_password_set' => (bool) $this->imap_password_enc, 'imap_folder' => $this->imap_folder, 'last_check_at' => $this->last_check_at?->toIso8601String(),
            'last_error' => $this->last_error, 'whatsapp_enabled' => $this->whatsapp_enabled, 'received' => $this->received, 'verify_token' => $this->token] + $this->urls();
    }
}
