<?php

use App\Modules\Mailbox\Protocol\ImapClient;
use App\Modules\Mailbox\Store;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Noms de dossiers lisibles : « INBOX.Sent » → « Envoyés », « INBOX.Clients » → « Clients »
return new class extends Migration
{
    public function up(): void
    {
        DB::table('mb_folders')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $f) {
                $graph = DB::table('mb_accounts')->where('id', $f->account_id)->value('provider') === 'microsoft';
                $full = $graph ? $f->name : (str_starts_with($f->remote_id, 'local:') ? $f->name : ImapClient::decodeName($f->remote_id));
                $name = mb_substr(Store::label($full, $graph ? '/' : null, $f->role), 0, 190);
                if ($name !== $f->name) {
                    DB::table('mb_folders')->where('id', $f->id)->update(['name' => $name]);
                }
            }
        });
    }

    public function down(): void {}
};
