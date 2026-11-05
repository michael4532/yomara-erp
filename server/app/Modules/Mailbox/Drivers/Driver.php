<?php

namespace App\Modules\Mailbox\Drivers;

use App\Modules\Mailbox\Models\Account;
use App\Modules\Mailbox\Models\Folder;
use App\Modules\Mailbox\Models\Message;

/** Fournisseur de messagerie : IMAP, POP3, Microsoft Graph. */
interface Driver
{
    /** Vérifie la connexion (réception et envoi). Lève MailProtocolException avec un message clair. */
    public function test(Account $a): array;

    /** Synchronise dossiers et messages récents. @return array{new: int, updated: int, deleted: int} */
    public function sync(Account $a): array;

    /** Charge le corps complet d'un message volumineux ou partiel. */
    public function loadBody(Account $a, Message $m): void;

    /** @return array{name: string, mime: string, content: string}|null */
    public function attachment(Account $a, Message $m, string $attId): ?array;

    public function setFlags(Account $a, Message $m, array $flags): void;

    public function move(Account $a, Message $m, Folder $target): void;

    public function destroy(Account $a, Message $m): void;

    /** Dossiers de rangement créés par l'utilisateur. */
    public function createFolder(Account $a, string $name, ?Folder $parent = null): Folder;

    public function renameFolder(Account $a, Folder $f, string $name): void;

    /** Supprime le dossier côté serveur (les messages locaux sont retirés par l'appelant). */
    public function deleteFolder(Account $a, Folder $f): void;

    /** Envoie un message composé (voir Composer). */
    public function send(Account $a, array $mail): void;
}
