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
        ];
    }

    public static function find(string $key): ?array
    {
        return collect(self::all())->firstWhere('key', $key);
    }
}
