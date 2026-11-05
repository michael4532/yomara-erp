<?php

namespace App\Messaging;

/** Message à envoyer (le contenu inutile au canal est ignoré : sujet / HTML pour l'e-mail, texte pour SMS et WhatsApp). */
class Message
{
    /** @param array<int, array{name: string, data: string, mime?: string}> $attachments */
    public function __construct(
        public string $to,
        public ?string $subject = null,
        public ?string $html = null,
        public ?string $text = null,
        public array $attachments = [],
        public ?string $toName = null,
        public array $headers = [],
        public ?string $replyTo = null,
        public ?string $mediaUrl = null, // WhatsApp : document / image à joindre (URL publique)
        public ?string $template = null, // WhatsApp Cloud : nom du modèle approuvé
        public array $templateParams = [],
        public ?string $mediaName = null, // nom du fichier joint (WhatsApp)
        public ?string $mediaMime = null, // type du fichier joint : une image part comme photo
        public array $cc = [], // e-mail : destinataires en copie (adresses)
    ) {}
}
