<?php

namespace App\Modules\Marketing;

/** Mises en page prêtes à l'emploi proposées à la création d'une campagne, d'un modèle ou d'une page. */
class EmailLayouts
{
    public static function img(string $name): string
    {
        return url('marketing/layouts/'.$name);
    }

    public static function all(): array
    {
        $i = fn ($n) => self::img($n);
        $social = ['type' => 'social', 'website' => 'https://', 'facebook' => 'https://facebook.com/', 'instagram' => 'https://instagram.com/', 'whatsapp' => '2250700000000'];

        return [
            [
                'key' => 'newsletter', 'name' => 'Newsletter', 'category' => 'Information', 'description' => 'Menu, bannière, édito et trois articles',
                'subject' => 'Les nouvelles de {{entreprise}}', 'preheader' => 'Ce qui change ce mois-ci',
                'style' => ['primary' => '#1F6FEB', 'background' => '#F4F6FB'],
                'blocks' => [
                    ['type' => 'menu', 'links' => "Accueil|https://\nBoutique|https://\nContact|https://"],
                    ['type' => 'hero', 'image' => $i('news.jpg'), 'title' => 'Les nouvelles du mois', 'text' => 'Bonjour {{prenom}}, voici ce qui change chez **{{entreprise}}**.', 'button_label' => 'Tout découvrir', 'url' => 'https://', 'bg_color' => '#13274F'],
                    ['type' => 'text', 'text' => "Ce mois-ci, nous avons pensé à vous : nouveaux produits, horaires élargis et une surprise pour nos clients fidèles.\n\nBonne lecture !"],
                    ['type' => 'columns3', 'cols' => [
                        ['image' => $i('article-1.jpg'), 'title' => 'Nouveauté', 'text' => 'Découvrez notre nouvelle gamme. [Lire](https://)'],
                        ['image' => $i('article-2.jpg'), 'title' => 'Conseil', 'text' => 'Trois astuces pour bien choisir. [Lire](https://)'],
                        ['image' => $i('article-3.jpg'), 'title' => 'Événement', 'text' => 'Rendez-vous samedi en boutique. [Lire](https://)'],
                    ]],
                    ['type' => 'button', 'label' => 'Visiter notre site', 'url' => 'https://', 'align' => 'center'],
                    ['type' => 'divider'],
                    $social,
                ],
            ],
            [
                'key' => 'promo', 'name' => 'Promotion flash', 'category' => 'Vente', 'description' => 'Bannière choc, code promo et trois produits',
                'subject' => '{{prenom}}, -30 % ce week-end seulement', 'preheader' => 'Code promo à l\'intérieur',
                'style' => ['primary' => '#D93025', 'background' => '#FFF7DB'],
                'blocks' => [
                    ['type' => 'hero', 'image' => $i('promo.jpg'), 'title' => '-30 % ce week-end', 'text' => 'Sur une sélection de produits, jusqu\'à dimanche minuit.', 'button_label' => 'J\'en profite', 'url' => 'https://', 'bg_color' => '#D93025', 'button_color' => '#0B1B3A'],
                    ['type' => 'coupon', 'text' => 'Votre code personnel', 'code' => 'WEEKEND30', 'expires' => 'Valable jusqu\'à dimanche 23 h 59'],
                    ['type' => 'products', 'title' => 'Nos meilleures ventes', 'button_label' => 'Voir', 'items' => [
                        ['image' => $i('product-1.jpg'), 'name' => 'Sac cabas', 'price' => '14 000 F', 'old_price' => '20 000 F', 'url' => 'https://'],
                        ['image' => $i('product-2.jpg'), 'name' => 'Baskets', 'price' => '24 500 F', 'old_price' => '35 000 F', 'url' => 'https://'],
                        ['image' => $i('product-3.jpg'), 'name' => 'Gourde', 'price' => '5 600 F', 'old_price' => '8 000 F', 'url' => 'https://'],
                    ]],
                    ['type' => 'text', 'text' => '*Offre valable dans la limite des stocks disponibles.*', 'align' => 'center'],
                ],
            ],
            [
                'key' => 'launch', 'name' => 'Lancement produit', 'category' => 'Vente', 'description' => 'Produit vedette, avantages et témoignage',
                'subject' => 'Nouveau : découvrez-le en avant-première', 'preheader' => 'Disponible dès aujourd\'hui',
                'style' => ['primary' => '#0E7C86'],
                'blocks' => [
                    ['type' => 'hero', 'image' => $i('launch.jpg'), 'title' => 'Il est enfin là', 'text' => 'Le produit que vous attendiez est disponible dès aujourd\'hui.', 'bg_color' => '#0E7C86'],
                    ['type' => 'product', 'image' => $i('product-6.jpg'), 'name' => 'Nouveau modèle', 'description' => 'Plus rapide, plus léger, garanti 12 mois.', 'price' => '89 000 F', 'button_label' => 'Commander', 'url' => 'https://'],
                    ['type' => 'columns3', 'cols' => [['title' => 'Livraison', 'text' => 'Abidjan en 24 h'], ['title' => 'Paiement', 'text' => 'Mobile Money ou carte'], ['title' => 'Garantie', 'text' => '12 mois']]],
                    ['type' => 'quote', 'text' => 'Je l\'utilise tous les jours, je ne pourrais plus m\'en passer.', 'author' => 'Un client satisfait'],
                    ['type' => 'button', 'label' => 'Je le veux', 'url' => 'https://', 'align' => 'center'],
                ],
            ],
            [
                'key' => 'proposal', 'name' => 'Proposition commerciale', 'category' => 'Vente', 'description' => 'Message personnalisé, points forts et prise de rendez-vous',
                'subject' => 'Notre proposition pour {{societe}}', 'preheader' => 'Proposition jointe',
                'style' => ['primary' => '#0B1B3A'],
                'blocks' => [
                    ['type' => 'heading', 'text' => 'Bonjour {{prenom}},', 'size' => 'h2'],
                    ['type' => 'text', 'text' => "Suite à nos échanges, vous trouverez ci-joint notre proposition pour **{{societe}}**.\n\nEn résumé :"],
                    ['type' => 'columns', 'left' => "**Ce que nous proposons**\nUne solution clé en main, installée et suivie par notre équipe.", 'right' => "**Pourquoi nous**\nRéactivité, transparence et accompagnement de proximité."],
                    ['type' => 'quote', 'text' => 'Nous restons disponibles pour adapter cette proposition à vos besoins.'],
                    ['type' => 'button', 'label' => 'Prendre rendez-vous', 'url' => 'https://', 'align' => 'left'],
                    ['type' => 'text', 'text' => "Bien cordialement,\nL'équipe {{entreprise}}"],
                ],
            ],
            [
                'key' => 'event', 'name' => 'Invitation', 'category' => 'Événement', 'description' => 'Bannière, date et lieu, inscription',
                'subject' => 'Vous êtes invité(e), {{prenom}}', 'preheader' => 'Réservez la date',
                'style' => ['primary' => '#1F6FEB', 'background' => '#0B1B3A'],
                'blocks' => [
                    ['type' => 'hero', 'image' => $i('event.jpg'), 'title' => 'Vous êtes invité(e) !', 'text' => 'Rejoignez-nous pour une matinée de découvertes et de rencontres.', 'bg_color' => '#0B1B3A'],
                    ['type' => 'columns3', 'cols' => [['title' => 'Quand', 'text' => 'Samedi 14 novembre, 10 h'], ['title' => 'Où', 'text' => 'Nos locaux, Abidjan'], ['title' => 'Prix', 'text' => 'Gratuit sur inscription']]],
                    ['type' => 'button', 'label' => 'Je m\'inscris', 'url' => 'https://', 'align' => 'center'],
                    ['type' => 'video', 'url' => 'https://www.youtube.com/watch?v=', 'caption' => 'Revoir l\'édition précédente'],
                ],
            ],
            [
                'key' => 'welcome', 'name' => 'Bienvenue', 'category' => 'Fidélisation', 'description' => 'Pour les nouveaux inscrits (idéal en automatisation)',
                'subject' => 'Bienvenue {{prenom}} !', 'preheader' => 'Un cadeau de bienvenue vous attend',
                'style' => ['primary' => '#12A150'],
                'blocks' => [
                    ['type' => 'hero', 'image' => $i('welcome.jpg'), 'title' => 'Bienvenue chez {{entreprise}}', 'text' => 'Merci de nous avoir rejoints, {{prenom}}.', 'bg_color' => '#12A150'],
                    ['type' => 'text', 'text' => 'Voici ce qui vous attend en tant que membre :'],
                    ['type' => 'columns3', 'cols' => [['title' => 'Offres exclusives', 'text' => 'Avant tout le monde'], ['title' => 'Conseils', 'text' => 'Chaque mois'], ['title' => 'Cadeaux', 'text' => 'Pour votre anniversaire']]],
                    ['type' => 'coupon', 'text' => 'Votre cadeau de bienvenue : -10 % sur votre première commande', 'code' => 'BIENVENUE10'],
                    ['type' => 'button', 'label' => 'Découvrir la boutique', 'url' => 'https://', 'align' => 'center'],
                ],
            ],
            [
                'key' => 'birthday', 'name' => 'Anniversaire', 'category' => 'Fidélisation', 'description' => 'Vœux et cadeau (automatisation « Anniversaire »)',
                'subject' => 'Joyeux anniversaire {{prenom}} 🎉', 'preheader' => 'Un cadeau rien que pour vous',
                'style' => ['primary' => '#E4407B'],
                'blocks' => [
                    ['type' => 'hero', 'image' => $i('birthday.jpg'), 'title' => 'Joyeux anniversaire, {{prenom}} !', 'text' => 'Toute l\'équipe de {{entreprise}} vous souhaite une excellente journée.', 'bg_color' => '#7B3FE4'],
                    ['type' => 'coupon', 'text' => 'Notre cadeau : -20 % pendant 7 jours', 'code' => 'ANNIV20', 'expires' => 'Valable 7 jours'],
                    ['type' => 'button', 'label' => 'En profiter', 'url' => 'https://', 'align' => 'center'],
                ],
            ],
            [
                'key' => 'digest', 'name' => 'Derniers articles (RSS)', 'category' => 'Information', 'description' => 'Reprend automatiquement les articles de votre blog',
                'subject' => 'Nos derniers articles', 'preheader' => 'À lire cette semaine',
                'style' => ['primary' => '#1F6FEB'],
                'blocks' => [
                    ['type' => 'heading', 'text' => 'À lire cette semaine'],
                    ['type' => 'text', 'text' => 'Bonjour {{prenom}}, voici nos derniers articles.'],
                    ['type' => 'rss', 'url' => 'https://', 'count' => 3, 'link_label' => 'Lire la suite'],
                    ['type' => 'button', 'label' => 'Tous les articles', 'url' => 'https://', 'align' => 'center'],
                ],
            ],
            [
                'key' => 'reminder', 'name' => 'Relance', 'category' => 'Vente', 'description' => 'Message court avec un bouton d\'action',
                'subject' => 'Petit rappel, {{prenom}}', 'preheader' => '',
                'style' => ['primary' => '#E8A317'],
                'blocks' => [
                    ['type' => 'text', 'text' => "Bonjour {{prenom}},\n\nSauf erreur de notre part, nous n'avons pas encore reçu votre retour. Pouvons-nous vous aider ?"],
                    ['type' => 'button', 'label' => 'Répondre', 'url' => 'mailto:', 'align' => 'left'],
                    ['type' => 'text', 'text' => "Cordialement,\n{{entreprise}}"],
                ],
            ],
            [
                'key' => 'letter', 'name' => 'Lettre simple', 'category' => 'Information', 'description' => 'Texte seul, comme un e-mail personnel',
                'subject' => '', 'preheader' => '',
                'style' => ['primary' => '#1F6FEB', 'background' => '#FFFFFF', 'show_header' => false],
                'blocks' => [['type' => 'text', 'text' => "Bonjour {{prenom}},\n\n…\n\nBien cordialement,\n{{entreprise}}"]],
            ],
            // ---------------- Prospection
            [
                'key' => 'prospect_intro', 'name' => 'Présentation à un prospect', 'category' => 'Prospection', 'description' => 'Premier contact : qui vous êtes, ce que vous apportez, prise de rendez-vous',
                'subject' => '{{prenom}}, une idée pour votre entreprise', 'preheader' => 'Deux minutes de lecture',
                'style' => ['primary' => '#1F4FB8', 'background' => '#F3F6FC'],
                'blocks' => [
                    ['type' => 'heading', 'text' => 'Bonjour {{prenom}},', 'size' => 'h2'],
                    ['type' => 'text', 'text' => "Je suis l'un des responsables de **{{entreprise}}**. Nous aidons des entreprises comme la vôtre à gagner du temps et à mieux servir leurs clients."],
                    ['type' => 'columns3', 'cols' => [['title' => 'Rapidité', 'text' => 'Mise en route en quelques jours.'], ['title' => 'Proximité', 'text' => 'Une équipe joignable, sur place.'], ['title' => 'Résultats', 'text' => 'Des gains mesurables dès le premier mois.']]],
                    ['type' => 'quote', 'text' => 'Depuis que nous travaillons ensemble, nos délais ont été divisés par deux.', 'author' => 'Un client de {{entreprise}}'],
                    ['type' => 'button', 'label' => 'Choisir un créneau de 20 minutes', 'url' => 'https://', 'align' => 'left'],
                    ['type' => 'text', 'text' => "Bien cordialement,\nL'équipe {{entreprise}}"],
                ],
            ],
            [
                'key' => 'quote_followup', 'name' => 'Relance après devis', 'category' => 'Prospection', 'description' => 'Rappel courtois de votre offre, avec les avantages et un bouton pour accepter',
                'subject' => 'Votre devis est prêt, {{prenom}}', 'preheader' => 'Une question ? Répondez simplement à cet e-mail',
                'style' => ['primary' => '#0E6B4F', 'background' => '#F2F7F4'],
                'blocks' => [
                    ['type' => 'text', 'text' => "Bonjour {{prenom}},\n\nVous avez reçu notre proposition il y a quelques jours. Avez-vous pu la consulter ?"],
                    ['type' => 'columns', 'left' => "**Inclus dans notre offre**\nInstallation, formation et suivi.", 'right' => "**Délai**\nDémarrage sous 7 jours après votre accord."],
                    ['type' => 'button', 'label' => 'J\'accepte la proposition', 'url' => 'mailto:', 'align' => 'left'],
                    ['type' => 'text', 'text' => "Un point à ajuster ? Répondez à cet e-mail : nous adapterons l'offre.\n\nCordialement,\n{{entreprise}}"],
                ],
            ],
            [
                'key' => 'appointment', 'name' => 'Prise de rendez-vous', 'category' => 'Prospection', 'description' => 'Invitation à un rendez-vous ou une démonstration, avec bouton de réservation',
                'subject' => '{{prenom}}, on en parle 20 minutes ?', 'preheader' => 'En visio, par téléphone ou dans vos locaux',
                'style' => ['primary' => '#4B2E83', 'background' => '#F6F3FB'],
                'blocks' => [
                    ['type' => 'hero', 'image' => $i('article-2.jpg'), 'title' => '20 minutes pour faire le point', 'text' => 'En visio, par téléphone ou dans vos locaux : vous choisissez.', 'button_label' => 'Réserver mon créneau', 'url' => 'https://', 'bg_color' => '#4B2E83'],
                    ['type' => 'text', 'text' => "Bonjour {{prenom}},\n\nJe vous propose un court échange pour comprendre vos besoins et vous montrer concrètement ce que nous pouvons faire pour vous. Sans engagement."],
                    ['type' => 'text', 'text' => "À très bientôt,\n{{entreprise}}"],
                ],
            ],
            [
                'key' => 'meeting_thanks', 'name' => 'Merci pour le rendez-vous', 'category' => 'Prospection', 'description' => 'Récapitulatif après une rencontre et prochaine étape',
                'subject' => 'Merci pour notre échange, {{prenom}}', 'preheader' => 'Récapitulatif et prochaine étape',
                'style' => ['primary' => '#1F6FEB', 'background' => '#FFFFFF', 'show_header' => false],
                'blocks' => [
                    ['type' => 'text', 'text' => "Bonjour {{prenom}},\n\nMerci pour le temps accordé. Voici ce que nous avons retenu :\n\n• vos besoins prioritaires ;\n• le calendrier souhaité ;\n• le budget envisagé.\n\nNous vous envoyons notre proposition sous 48 heures."],
                    ['type' => 'button', 'label' => 'Ajouter une précision', 'url' => 'mailto:', 'align' => 'left'],
                    ['type' => 'text', 'text' => "Bien cordialement,\n{{entreprise}}"],
                ],
            ],
            // ---------------- Fidélisation
            [
                'key' => 'reactivation', 'name' => 'Vous nous manquez', 'category' => 'Fidélisation', 'description' => 'Faire revenir un client absent depuis longtemps, avec un avantage',
                'subject' => '{{prenom}}, cela fait longtemps !', 'preheader' => 'Un cadeau vous attend',
                'style' => ['primary' => '#B35A00', 'background' => '#FFF6EC'],
                'blocks' => [
                    ['type' => 'hero', 'image' => $i('welcome.jpg'), 'title' => 'Vous nous avez manqué', 'text' => 'Pour votre retour, nous vous offrons un avantage sur votre prochaine commande.', 'bg_color' => '#B35A00'],
                    ['type' => 'coupon', 'text' => 'Votre avantage de retour', 'code' => 'RETOUR10', 'expires' => 'Valable 15 jours'],
                    ['type' => 'button', 'label' => 'Découvrir les nouveautés', 'url' => 'https://', 'align' => 'center'],
                    ['type' => 'text', 'text' => "À très vite,\n{{entreprise}}", 'align' => 'center'],
                ],
            ],
            [
                'key' => 'thanks_review', 'name' => 'Merci et avis client', 'category' => 'Fidélisation', 'description' => 'Remercier après un achat et demander un avis',
                'subject' => 'Merci {{prenom}} ! Votre avis compte', 'preheader' => 'Une minute pour nous aider',
                'style' => ['primary' => '#12A150', 'background' => '#F1FAF4'],
                'blocks' => [
                    ['type' => 'heading', 'text' => 'Merci pour votre confiance, {{prenom}} !', 'size' => 'h2'],
                    ['type' => 'text', 'text' => "Votre avis nous aide à progresser et aide d'autres clients à nous choisir. Cela ne prend qu'une minute."],
                    ['type' => 'button', 'label' => 'Donner mon avis', 'url' => 'https://', 'align' => 'center'],
                    ['type' => 'text', 'text' => "Merci d'avance,\nL'équipe {{entreprise}}", 'align' => 'center'],
                ],
            ],
            [
                'key' => 'loyalty', 'name' => 'Programme de fidélité', 'category' => 'Fidélisation', 'description' => 'Points, avantages réservés et parrainage',
                'subject' => '{{prenom}}, vos avantages fidélité', 'preheader' => 'Réservé à nos meilleurs clients',
                'style' => ['primary' => '#7A1F2B', 'background' => '#FBF3F4'],
                'blocks' => [
                    ['type' => 'heading', 'text' => 'Merci d\'être fidèle à {{entreprise}}'],
                    ['type' => 'columns3', 'cols' => [['title' => 'Points', 'text' => 'Gagnez des points à chaque achat.'], ['title' => 'Avantages', 'text' => 'Offres réservées aux membres.'], ['title' => 'Parrainage', 'text' => 'Un cadeau pour vous et vos proches.']]],
                    ['type' => 'button', 'label' => 'Voir mes avantages', 'url' => 'https://', 'align' => 'center'],
                    $social,
                ],
            ],
            // ---------------- Information
            [
                'key' => 'seasonal', 'name' => 'Vœux et fêtes', 'category' => 'Information', 'description' => 'Fin d\'année, Tabaski, Ramadan, fête des mères… message chaleureux et horaires',
                'subject' => 'Belles fêtes, {{prenom}} !', 'preheader' => 'Toute l\'équipe vous souhaite de belles fêtes',
                'style' => ['primary' => '#0B1B3A', 'background' => '#F7F3E9'],
                'blocks' => [
                    ['type' => 'hero', 'image' => $i('event.jpg'), 'title' => 'Belles fêtes !', 'text' => 'Toute l\'équipe de {{entreprise}} vous remercie pour votre confiance et vous souhaite de très belles fêtes.', 'bg_color' => '#0B1B3A'],
                    ['type' => 'text', 'text' => "**Nos horaires pendant les fêtes**\nNous restons ouverts, avec des horaires adaptés. Pour toute urgence, appelez-nous.", 'align' => 'center'],
                    $social,
                ],
            ],
            [
                'key' => 'catalog', 'name' => 'Nouveau catalogue', 'category' => 'Vente', 'description' => 'Collection ou catalogue avec six produits et lien de commande',
                'subject' => 'Notre nouvelle collection est arrivée', 'preheader' => 'Découvrez-la en avant-première',
                'style' => ['primary' => '#1F2937', 'background' => '#F5F5F4'],
                'blocks' => [
                    ['type' => 'hero', 'image' => $i('launch.jpg'), 'title' => 'La nouvelle collection', 'text' => 'Découvrez nos nouveautés en avant-première, {{prenom}}.', 'button_label' => 'Voir le catalogue', 'url' => 'https://', 'bg_color' => '#1F2937'],
                    ['type' => 'products', 'title' => 'Sélection du moment', 'button_label' => 'Commander', 'items' => [
                        ['image' => $i('product-1.jpg'), 'name' => 'Produit 1', 'price' => '15 000 F', 'url' => 'https://'],
                        ['image' => $i('product-4.jpg'), 'name' => 'Produit 2', 'price' => '22 000 F', 'url' => 'https://'],
                        ['image' => $i('product-5.jpg'), 'name' => 'Produit 3', 'price' => '9 500 F', 'url' => 'https://'],
                    ]],
                    ['type' => 'button', 'label' => 'Commander sur WhatsApp', 'url' => 'https://wa.me/', 'align' => 'center'],
                ],
            ],
            [
                'key' => 'survey', 'name' => 'Enquête de satisfaction', 'category' => 'Information', 'description' => 'Questionnaire court avec bouton vers le formulaire',
                'subject' => '{{prenom}}, 2 minutes pour nous aider ?', 'preheader' => '5 questions, pas une de plus',
                'style' => ['primary' => '#0E7C86', 'background' => '#F0F8F9'],
                'blocks' => [
                    ['type' => 'heading', 'text' => 'Votre avis nous intéresse'],
                    ['type' => 'text', 'text' => "Bonjour {{prenom}},\n\nPour mieux vous servir, nous aimerions connaître votre avis sur nos produits et notre service. Le questionnaire prend deux minutes."],
                    ['type' => 'button', 'label' => 'Répondre au questionnaire', 'url' => 'https://', 'align' => 'center'],
                    ['type' => 'text', 'text' => "Merci beaucoup,\n{{entreprise}}", 'align' => 'center'],
                ],
            ],
        ];
    }

    /** Messages SMS et WhatsApp prêts à l'emploi (courts, personnalisés avec {{prenom}} et {{entreprise}}). */
    public static function texts(): array
    {
        return [
            ['key' => 'txt_first_contact', 'category' => 'Prospection', 'name' => 'Premier contact', 'text' => 'Bonjour {{prenom}}, ici {{entreprise}}. Nous aidons les entreprises comme la vôtre à gagner du temps. Puis-je vous appeler 5 minutes cette semaine ?'],
            ['key' => 'txt_quote_followup', 'category' => 'Prospection', 'name' => 'Relance après devis', 'text' => 'Bonjour {{prenom}}, avez-vous pu consulter notre proposition ? Nous pouvons l\'ajuster à votre budget. Répondez à ce message, nous vous rappelons.'],
            ['key' => 'txt_appointment', 'category' => 'Prospection', 'name' => 'Proposer un rendez-vous', 'text' => 'Bonjour {{prenom}}, {{entreprise}} vous propose un échange de 20 min pour étudier votre besoin. Plutôt mardi ou jeudi ?'],
            ['key' => 'txt_no_answer', 'category' => 'Prospection', 'name' => 'Prospect sans réponse', 'text' => 'Bonjour {{prenom}}, votre projet est-il toujours d\'actualité ? Sans nouvelles, nous clôturerons votre demande. Un simple « oui » suffit pour la garder.'],
            ['key' => 'txt_flash_sale', 'category' => 'Vente', 'name' => 'Promotion flash', 'text' => '{{entreprise}} : -20 % sur une sélection jusqu\'à dimanche ! Profitez-en vite, {{prenom}}.'],
            ['key' => 'txt_new_arrival', 'category' => 'Vente', 'name' => 'Nouveautés en magasin', 'text' => 'Bonjour {{prenom}}, de nouveaux produits viennent d\'arriver chez {{entreprise}}. Passez nous voir ou répondez pour recevoir le catalogue.'],
            ['key' => 'txt_clearance', 'category' => 'Vente', 'name' => 'Déstockage', 'text' => '{{entreprise}} : grand déstockage cette semaine, jusqu\'à -50 %. Quantités limitées !'],
            ['key' => 'txt_thanks', 'category' => 'Fidélisation', 'name' => 'Merci pour votre achat', 'text' => 'Merci {{prenom}} pour votre confiance ! Toute l\'équipe de {{entreprise}} reste à votre écoute.'],
            ['key' => 'txt_review', 'category' => 'Fidélisation', 'name' => 'Demander un avis', 'text' => 'Bonjour {{prenom}}, êtes-vous satisfait de votre achat chez {{entreprise}} ? Votre avis nous aide beaucoup : répondez de 1 à 5.'],
            ['key' => 'txt_birthday', 'category' => 'Fidélisation', 'name' => 'Anniversaire', 'text' => 'Joyeux anniversaire {{prenom}} ! {{entreprise}} vous offre un cadeau en magasin ce mois-ci. 🎉'],
            ['key' => 'txt_comeback', 'category' => 'Fidélisation', 'name' => 'Client absent', 'text' => 'Bonjour {{prenom}}, vous nous manquez ! Un avantage vous attend chez {{entreprise}} pour votre prochaine visite.'],
            ['key' => 'txt_holidays', 'category' => 'Information', 'name' => 'Vœux et horaires des fêtes', 'text' => 'Toute l\'équipe de {{entreprise}} vous souhaite de belles fêtes, {{prenom}} ! Nous restons ouverts avec des horaires adaptés.'],
            ['key' => 'txt_appointment_reminder', 'category' => 'Information', 'name' => 'Rappel de rendez-vous', 'text' => 'Bonjour {{prenom}}, petit rappel de votre rendez-vous avec {{entreprise}} demain. Répondez si vous devez le déplacer.'],
            ['key' => 'txt_closing', 'category' => 'Information', 'name' => 'Fermeture exceptionnelle', 'text' => '{{entreprise}} : fermeture exceptionnelle ce jour. Réouverture demain aux horaires habituels. Merci de votre compréhension.'],
        ];
    }

    public static function findText(string $key): ?array
    {
        return collect(self::texts())->firstWhere('key', $key);
    }

    public static function find(string $key): ?array
    {
        return collect(self::all())->firstWhere('key', $key);
    }
}
