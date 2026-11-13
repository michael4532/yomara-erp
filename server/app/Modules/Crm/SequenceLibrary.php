<?php

namespace App\Modules\Crm;

/**
 * Bibliothèque de relances prospect prêtes à l'emploi : le client en choisit une, elle est copiée dans ses relances
 * et il l'adapte librement (textes, délais, canaux). Variables : {{prenom}}, {{nom}}, {{societe}}, {{entreprise}},
 * {{commercial}}, {{telephone_commercial}}, {{titre}}.
 */
class SequenceLibrary
{
    public static function all(): array
    {
        $sign = "\n\n{{commercial}}\n{{entreprise}} · {{telephone_commercial}}";

        return [
            // ---------------------------------------------------------------- situations
            [
                'key' => 'after_quote', 'category' => 'Situations', 'icon' => 'request_quote',
                'name' => 'Après l\'envoi d\'un devis', 'description' => 'Trois relances courtoises sur 10 jours, puis un appel : le prospect ne laisse pas filer votre offre.',
                'steps' => [
                    ['channel' => 'whatsapp', 'delay_days' => 2, 'hour' => 10, 'text' => 'Bonjour {{prenom}}, c\'est {{commercial}} de {{entreprise}}. Avez-vous bien reçu notre proposition pour « {{titre}} » ? Je reste disponible pour toute question.'],
                    ['channel' => 'email', 'delay_days' => 5, 'hour' => 9, 'subject' => 'Votre devis « {{titre}} » : avez-vous des questions ?', 'text' => "Bonjour {{prenom}},\n\nJe reviens vers vous au sujet de notre proposition pour « {{titre}} ».\n\nSi un point mérite d'être ajusté (délai, quantité, budget), dites-le-moi : nous trouverons la formule qui vous convient.\n\nBien cordialement,".$sign],
                    ['channel' => 'call', 'delay_days' => 10, 'hour' => 11, 'text' => 'Appeler {{prenom}} ({{societe}}) pour faire le point sur le devis « {{titre}} »'],
                    ['channel' => 'email', 'delay_days' => 15, 'hour' => 9, 'subject' => 'Dernière relance : notre offre reste valable', 'text' => "Bonjour {{prenom}},\n\nNotre offre pour « {{titre}} » arrive bientôt à échéance. Si votre projet est toujours d'actualité, il suffit de répondre « OK » à ce message pour que nous la confirmions.\n\nMerci et à bientôt,".$sign],
                ],
            ],
            [
                'key' => 'no_answer', 'category' => 'Situations', 'icon' => 'mark_email_unread',
                'name' => 'Prospect qui ne répond plus', 'description' => 'Relance douce, puis rappel de la valeur, puis message de clôture qui fait souvent réagir.',
                'steps' => [
                    ['channel' => 'email', 'delay_days' => 0, 'hour' => 9, 'subject' => '{{prenom}}, avez-vous eu le temps d\'y réfléchir ?', 'text' => "Bonjour {{prenom}},\n\nJe me permets de revenir vers vous : avez-vous pu avancer sur votre projet ?\n\nUn échange de 10 minutes suffit souvent à y voir plus clair. Quel moment vous arrange cette semaine ?".$sign],
                    ['channel' => 'whatsapp', 'delay_days' => 4, 'hour' => 11, 'text' => 'Bonjour {{prenom}} 👋 Petit message de {{entreprise}} : votre projet est-il toujours d\'actualité ? Je peux vous rappeler quand vous voulez.'],
                    ['channel' => 'email', 'delay_days' => 10, 'hour' => 9, 'subject' => 'Je ferme votre dossier ?', 'text' => "Bonjour {{prenom}},\n\nSans nouvelles de votre part, je vais classer votre demande pour ne pas vous déranger davantage.\n\nSi vous souhaitez la garder ouverte, répondez simplement à ce message : je m'en occupe.\n\nBelle journée,".$sign],
                ],
            ],
            [
                'key' => 'after_meeting', 'category' => 'Situations', 'icon' => 'handshake',
                'name' => 'Après un premier rendez-vous', 'description' => 'Remerciement le jour même, récapitulatif, puis proposition de la prochaine étape.',
                'steps' => [
                    ['channel' => 'email', 'delay_days' => 0, 'hour' => 17, 'subject' => 'Merci pour notre échange, {{prenom}}', 'text' => "Bonjour {{prenom}},\n\nMerci pour le temps que vous nous avez accordé aujourd'hui. Comme convenu, nous préparons une proposition adaptée à {{societe}}.\n\nN'hésitez pas à me transmettre tout document utile d'ici là.".$sign],
                    ['channel' => 'task', 'delay_days' => 1, 'hour' => 9, 'text' => 'Préparer et envoyer la proposition à {{prenom}} ({{societe}})'],
                    ['channel' => 'whatsapp', 'delay_days' => 4, 'hour' => 10, 'text' => 'Bonjour {{prenom}}, votre proposition est prête. Préférez-vous que je vous la présente en 15 minutes par téléphone ou en visio ?'],
                ],
            ],
            [
                'key' => 'web_lead', 'category' => 'Situations', 'icon' => 'language',
                'name' => 'Demande reçue par le site ou les réseaux', 'description' => 'Réponse immédiate (le prospect est encore là), appel dans l\'heure, puis relance le lendemain.',
                'steps' => [
                    ['channel' => 'whatsapp', 'delay_days' => 0, 'text' => 'Bonjour {{prenom}}, merci pour votre demande auprès de {{entreprise}} ! Je suis {{commercial}} et je m\'occupe de vous. Quel est le meilleur moment pour vous appeler ?'],
                    ['channel' => 'call', 'delay_days' => 0, 'text' => 'Appeler {{prenom}} dans l\'heure (demande reçue en ligne)'],
                    ['channel' => 'email', 'delay_days' => 1, 'hour' => 9, 'subject' => 'Votre demande auprès de {{entreprise}}', 'text' => "Bonjour {{prenom}},\n\nMerci de l'intérêt que vous portez à {{entreprise}}. Pour vous répondre au mieux, pourriez-vous me préciser votre besoin, vos délais et votre budget ?\n\nJe vous réponds dans la journée.".$sign],
                ],
            ],
            [
                'key' => 'trade_show', 'category' => 'Situations', 'icon' => 'storefront',
                'name' => 'Rencontré sur un salon', 'description' => 'Remerciement après le salon, documentation, puis proposition de rendez-vous.',
                'steps' => [
                    ['channel' => 'email', 'delay_days' => 1, 'hour' => 9, 'subject' => 'Ravi de vous avoir rencontré, {{prenom}}', 'text' => "Bonjour {{prenom}},\n\nMerci pour votre visite sur notre stand. Comme promis, voici nos informations : n'hésitez pas à me dire ce qui vous intéresse le plus.\n\nAu plaisir d'échanger à nouveau,".$sign],
                    ['channel' => 'whatsapp', 'delay_days' => 4, 'hour' => 10, 'text' => 'Bonjour {{prenom}}, c\'est {{commercial}} ({{entreprise}}), rencontré au salon. Seriez-vous disponible pour un rendez-vous de 20 minutes la semaine prochaine ?'],
                    ['channel' => 'call', 'delay_days' => 8, 'hour' => 11, 'text' => 'Appeler {{prenom}} ({{societe}}) : contact du salon, proposer un rendez-vous'],
                ],
            ],
            [
                'key' => 'offer_expiring', 'category' => 'Situations', 'icon' => 'timer',
                'name' => 'Offre qui arrive à échéance', 'description' => 'Rappel 5 jours puis 1 jour avant la fin de validité de l\'offre.',
                'steps' => [
                    ['channel' => 'email', 'delay_days' => 0, 'hour' => 9, 'subject' => 'Votre offre expire bientôt', 'text' => "Bonjour {{prenom}},\n\nPetit rappel : notre offre « {{titre}} » reste valable encore quelques jours. Passé ce délai, les conditions pourraient changer.\n\nSouhaitez-vous que nous la confirmions ?".$sign],
                    ['channel' => 'sms', 'delay_days' => 4, 'hour' => 10, 'text' => '{{entreprise}} : votre offre « {{titre}} » expire demain. Répondez OK pour la confirmer ou appelez le {{telephone_commercial}}.'],
                ],
            ],
            [
                'key' => 'reactivation', 'category' => 'Situations', 'icon' => 'restart_alt',
                'name' => 'Réactiver un ancien client', 'description' => 'Message personnel, nouveauté, puis avantage de retour.',
                'steps' => [
                    ['channel' => 'whatsapp', 'delay_days' => 0, 'hour' => 10, 'text' => 'Bonjour {{prenom}}, cela fait un moment ! Toute l\'équipe de {{entreprise}} espère que vous allez bien. Avez-vous de nouveaux projets en vue ?'],
                    ['channel' => 'email', 'delay_days' => 5, 'hour' => 9, 'subject' => 'Ce qui a changé chez {{entreprise}}', 'text' => "Bonjour {{prenom}},\n\nDepuis votre dernière commande, nous avons élargi nos services et amélioré nos délais. Je serais ravi de vous présenter ces nouveautés.\n\nPour votre retour, nous vous réservons un geste sur votre prochaine commande.".$sign],
                    ['channel' => 'call', 'delay_days' => 12, 'hour' => 11, 'text' => 'Appeler {{prenom}} ({{societe}}) : ancien client, proposer l\'avantage de retour'],
                ],
            ],
            // ---------------------------------------------------------------- métiers
            [
                'key' => 'real_estate_visit', 'category' => 'Métiers', 'icon' => 'apartment',
                'name' => 'Immobilier : après une visite', 'description' => 'Avis sur le bien, autres biens proches, puis relance avant que le bien parte.',
                'steps' => [
                    ['channel' => 'whatsapp', 'delay_days' => 0, 'hour' => 18, 'text' => 'Bonsoir {{prenom}}, merci pour la visite d\'aujourd\'hui. Qu\'avez-vous pensé du bien ? Je peux vous envoyer d\'autres biens proches de vos critères.'],
                    ['channel' => 'email', 'delay_days' => 2, 'hour' => 9, 'subject' => 'D\'autres biens qui pourraient vous plaire', 'text' => "Bonjour {{prenom}},\n\nSuite à notre visite, j'ai sélectionné quelques biens correspondant à vos critères (quartier, budget, nombre de pièces). Dites-moi lesquels vous souhaitez visiter.".$sign],
                    ['channel' => 'call', 'delay_days' => 5, 'hour' => 11, 'text' => 'Appeler {{prenom}} : le bien visité intéresse d\'autres clients, faire le point'],
                ],
            ],
            [
                'key' => 'training_info', 'category' => 'Métiers', 'icon' => 'school',
                'name' => 'École et formation : demande d\'information', 'description' => 'Brochure, invitation à une séance d\'information, rappel des inscriptions.',
                'steps' => [
                    ['channel' => 'email', 'delay_days' => 0, 'subject' => 'Votre demande d\'information — {{entreprise}}', 'text' => "Bonjour {{prenom}},\n\nMerci pour votre intérêt. Vous trouverez nos programmes, nos tarifs et les modalités d'inscription auprès de notre équipe.\n\nVous pouvez aussi venir nous rencontrer : nous organisons régulièrement des séances d'information.".$sign],
                    ['channel' => 'whatsapp', 'delay_days' => 3, 'hour' => 10, 'text' => 'Bonjour {{prenom}}, avez-vous pu consulter nos programmes ? Souhaitez-vous réserver une place à notre prochaine séance d\'information ?'],
                    ['channel' => 'sms', 'delay_days' => 10, 'hour' => 9, 'text' => '{{entreprise}} : les inscriptions se terminent bientôt. Réservez votre place au {{telephone_commercial}}.'],
                ],
            ],
            [
                'key' => 'catering_event', 'category' => 'Métiers', 'icon' => 'restaurant',
                'name' => 'Restaurant et traiteur : devis d\'événement', 'description' => 'Menu proposé, dégustation, confirmation du nombre de couverts.',
                'steps' => [
                    ['channel' => 'whatsapp', 'delay_days' => 1, 'hour' => 11, 'text' => 'Bonjour {{prenom}}, avez-vous pu regarder notre proposition de menu pour votre événement ? Nous pouvons l\'adapter (plats, quantités, budget).'],
                    ['channel' => 'email', 'delay_days' => 4, 'hour' => 9, 'subject' => 'Une dégustation avant votre événement ?', 'text' => "Bonjour {{prenom}},\n\nPour être sûr que tout vous plaise le jour J, nous vous proposons une dégustation des plats choisis. Quel jour vous conviendrait ?".$sign],
                    ['channel' => 'call', 'delay_days' => 7, 'hour' => 10, 'text' => 'Appeler {{prenom}} pour confirmer le menu et le nombre de couverts'],
                ],
            ],
            [
                'key' => 'construction_quote', 'category' => 'Métiers', 'icon' => 'construction',
                'name' => 'BTP et travaux : après une visite de chantier', 'description' => 'Devis envoyé, planning proposé, relance avant la saison des pluies.',
                'steps' => [
                    ['channel' => 'email', 'delay_days' => 2, 'hour' => 9, 'subject' => 'Votre devis de travaux « {{titre}} »', 'text' => "Bonjour {{prenom}},\n\nSuite à notre visite, vous avez reçu notre devis. Nous pouvons démarrer rapidement et vous proposer un planning détaillé dès votre accord.".$sign],
                    ['channel' => 'whatsapp', 'delay_days' => 6, 'hour' => 10, 'text' => 'Bonjour {{prenom}}, des questions sur le devis ? Nos équipes ont encore des disponibilités ce mois-ci : réservez votre créneau avant qu\'il ne soit pris.'],
                    ['channel' => 'call', 'delay_days' => 12, 'hour' => 10, 'text' => 'Appeler {{prenom}} : point sur le devis de travaux « {{titre}} »'],
                ],
            ],
            [
                'key' => 'b2b_demo', 'category' => 'Métiers', 'icon' => 'computer',
                'name' => 'Services et logiciels : proposer une démonstration', 'description' => 'Invitation à une démo, rappel la veille, suivi après la démo.',
                'steps' => [
                    ['channel' => 'email', 'delay_days' => 0, 'hour' => 9, 'subject' => '{{prenom}}, 20 minutes pour vous montrer ?', 'text' => "Bonjour {{prenom}},\n\nPlutôt qu'une longue présentation, je vous propose une démonstration de 20 minutes, adaptée à {{societe}}. Vous verrez concrètement ce que vous pouvez gagner en temps et en argent.\n\nQuel créneau vous arrange ?".$sign],
                    ['channel' => 'whatsapp', 'delay_days' => 3, 'hour' => 10, 'text' => 'Bonjour {{prenom}}, {{commercial}} de {{entreprise}}. Je vous propose une démo rapide, en visio ou dans vos locaux. Mardi ou jeudi, ça vous irait ?'],
                    ['channel' => 'task', 'delay_days' => 7, 'hour' => 9, 'text' => 'Relancer {{prenom}} ({{societe}}) : démonstration non encore planifiée'],
                ],
            ],
        ];
    }

    public static function find(string $key): ?array
    {
        return collect(self::all())->firstWhere('key', $key);
    }
}
