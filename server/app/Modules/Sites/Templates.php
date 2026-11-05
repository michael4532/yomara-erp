<?php

namespace App\Modules\Sites;

/**
 * Galerie des modèles de sites vitrines : 18 secteurs × 3 styles = 54 modèles.
 * Un modèle = un secteur (textes et sections par défaut) + un style (mise en page, typographies) + une palette.
 * Aucune image n'est fournie : les visuels décoratifs sont dessinés en SVG avec la palette ; le client ajoute ses photos.
 */
class Templates
{
    /** Mise en page et typographie de chaque style. */
    public const STYLES = [
        'eclat' => ['name' => 'Éclat', 'hero' => 'gradient', 'heading' => 'Poppins', 'body' => 'Poppins', 'radius' => 18, 'cards' => 'shadow', 'nav' => 'light',
            'description' => 'Dégradés vifs, coins arrondis, ambiance chaleureuse et dynamique.'],
        'elegance' => ['name' => 'Élégance', 'hero' => 'centered', 'heading' => 'Playfair Display', 'body' => 'Lato', 'radius' => 4, 'cards' => 'outline', 'nav' => 'transparent',
            'description' => 'Titres à empattements, grands espaces, lignes fines : sobre et haut de gamme.'],
        'moderne' => ['name' => 'Moderne', 'hero' => 'split', 'heading' => 'Space Grotesk', 'body' => 'Inter', 'radius' => 10, 'cards' => 'flat', 'nav' => 'dark',
            'description' => 'Deux colonnes, contrastes francs, typographie technique : net et efficace.'],
    ];

    /** Sections disponibles, dans l'ordre d'affichage par défaut. */
    public const SECTIONS = [
        'hero' => 'Bannière d\'accueil', 'about' => 'Présentation', 'services' => 'Services', 'stats' => 'Chiffres clés', 'products' => 'Produits (catalogue de l\'ERP)',
        'events' => 'Événements (billetterie)', 'gallery' => 'Galerie photos', 'testimonials' => 'Témoignages de vos clients', 'faq' => 'Questions fréquentes', 'blog' => 'Derniers articles du blog',
        'booking' => 'Prise de rendez-vous', 'jobs' => 'Offres d\'emploi', 'contact' => 'Contact et formulaire',
    ];

    public const ICONS = ['utensils', 'bag', 'scissors', 'heart', 'hammer', 'briefcase', 'home', 'book', 'calendar', 'bed', 'truck', 'leaf', 'cpu', 'scale', 'users', 'dumbbell',
        'camera', 'car', 'sparkles', 'shield', 'award', 'clock', 'phone', 'map', 'mail', 'check', 'star', 'globe', 'chat', 'gift'];

    /**
     * Secteurs : nom, icône, palettes (une par style : primaire, foncée, accent, fond), textes et services par défaut.
     * {societe} et {ville} sont remplacés par les données de l'entreprise.
     */
    public static function sectors(): array
    {
        return [
            'restaurant' => ['Restaurant & maquis', 'utensils', [['#E4572E', '#2B1B17', '#FFC857', '#FFF8F0'], ['#7A1F2B', '#1C1414', '#C9A227', '#FBF7F2'], ['#FF6B35', '#0F1115', '#F7C548', '#F6F6F4']],
                'La cuisine qui rassemble', 'Plats faits maison, produits frais du marché et accueil chaleureux à {ville}. Sur place, à emporter ou en livraison.', 'Réserver une table',
                'Notre histoire', '{societe} vous accueille dans un cadre convivial pour partager une cuisine généreuse, préparée chaque jour avec des produits locaux.',
                [['utensils', 'Cuisine maison', 'Des recettes authentiques préparées chaque jour.'], ['truck', 'Livraison', 'Vos plats livrés chauds à domicile ou au bureau.'], ['users', 'Groupes & événements', 'Anniversaires, déjeuners d\'affaires, mariages.'], ['clock', 'Ouvert 7j/7', 'Midi et soir, avec ou sans réservation.']],
                ['hero', 'about', 'services', 'products', 'gallery', 'testimonials', 'booking', 'contact']],
            'boutique' => ['Boutique & mode', 'bag', [['#D6336C', '#1E1B2E', '#FFD166', '#FFF5F8'], ['#111111', '#111111', '#C8A96A', '#FAF8F5'], ['#6C5CE7', '#14121F', '#00D2D3', '#F7F7FB']],
                'Votre style, nos collections', 'Vêtements, chaussures et accessoires sélectionnés avec soin. Nouveautés chaque semaine à {ville}.', 'Voir la collection',
                'La boutique', '{societe} propose des pièces tendance et intemporelles, avec des conseils personnalisés pour chaque occasion.',
                [['bag', 'Nouvelles collections', 'Des arrivages réguliers pour toute la famille.'], ['gift', 'Cartes cadeaux', 'Faites plaisir à vos proches.'], ['truck', 'Livraison rapide', 'Commandez sur WhatsApp, recevez chez vous.'], ['star', 'Conseil personnalisé', 'Nous vous aidons à composer votre tenue.']],
                ['hero', 'products', 'about', 'services', 'gallery', 'testimonials', 'contact']],
            'beaute' => ['Beauté & coiffure', 'scissors', [['#E36397', '#2D1E2F', '#F9C80E', '#FFF4F8'], ['#8E5572', '#231A20', '#D4AF7A', '#FBF6F4'], ['#B5179E', '#16091A', '#4CC9F0', '#F8F5FA']],
                'Prenez soin de vous', 'Coiffure, soins du visage, manucure et maquillage dans un espace dédié à votre bien-être.', 'Prendre rendez-vous',
                'Notre salon', 'Chez {societe}, une équipe passionnée vous accueille pour sublimer votre beauté naturelle avec des produits de qualité.',
                [['scissors', 'Coiffure', 'Coupes, tresses, tissages, soins capillaires.'], ['sparkles', 'Esthétique', 'Soins du visage, épilation, maquillage.'], ['heart', 'Manucure & pédicure', 'Pose de vernis, gel et nail art.'], ['gift', 'Forfaits mariée', 'Une mise en beauté complète pour le grand jour.']],
                ['hero', 'services', 'about', 'gallery', 'booking', 'testimonials', 'contact']],
            'sante' => ['Santé & clinique', 'heart', [['#0EA5E9', '#0B2545', '#22C55E', '#F2F9FD'], ['#1D4E89', '#0D1B2A', '#7FB7BE', '#F7FAFC'], ['#06B6D4', '#082F3A', '#F97316', '#F3FAFB']],
                'Votre santé entre de bonnes mains', 'Consultations, examens et suivi médical avec une équipe disponible et à l\'écoute, à {ville}.', 'Prendre rendez-vous',
                'Notre établissement', '{societe} met à votre service des professionnels qualifiés et des équipements modernes pour des soins de qualité.',
                [['heart', 'Médecine générale', 'Consultations pour toute la famille.'], ['shield', 'Prévention', 'Bilans de santé et vaccinations.'], ['clock', 'Urgences', 'Une équipe mobilisée pour vous recevoir rapidement.'], ['users', 'Spécialistes', 'Pédiatrie, gynécologie, cardiologie…']],
                ['hero', 'about', 'services', 'stats', 'booking', 'faq', 'contact']],
            'btp' => ['BTP & construction', 'hammer', [['#F59E0B', '#1F2937', '#EF4444', '#FFFBF2'], ['#7C5C3E', '#1B1611', '#C08B5C', '#F9F6F1'], ['#FACC15', '#111827', '#3B82F6', '#F5F5F4']],
                'Nous bâtissons vos projets', 'Construction, rénovation et génie civil : des ouvrages solides livrés dans les délais.', 'Demander un devis',
                'L\'entreprise', '{societe} accompagne particuliers, entreprises et collectivités de l\'étude à la livraison, avec des équipes expérimentées.',
                [['home', 'Construction', 'Villas, immeubles et bâtiments professionnels.'], ['hammer', 'Rénovation', 'Remise à neuf, extension, aménagement.'], ['truck', 'Génie civil', 'Voirie, terrassement, réseaux divers.'], ['shield', 'Suivi de chantier', 'Contrôle qualité et respect du budget.']],
                ['hero', 'services', 'stats', 'about', 'gallery', 'testimonials', 'contact']],
            'agence' => ['Agence & conseil', 'briefcase', [['#4F46E5', '#1E1B4B', '#F472B6', '#F7F7FE'], ['#1F3A5F', '#0F1E33', '#B08D57', '#F8F7F4'], ['#2563EB', '#0B1220', '#22D3EE', '#F4F6FA']],
                'Des idées qui font grandir votre entreprise', 'Stratégie, communication et accompagnement sur mesure pour atteindre vos objectifs.', 'Discutons de votre projet',
                'Qui sommes-nous', '{societe} réunit des experts engagés aux côtés de ses clients, des PME aux grands comptes, en Côte d\'Ivoire et dans la sous-région.',
                [['briefcase', 'Conseil', 'Diagnostic, stratégie et plan d\'action.'], ['globe', 'Communication', 'Image de marque, digital, réseaux sociaux.'], ['users', 'Formation', 'Montée en compétences de vos équipes.'], ['award', 'Accompagnement', 'Un suivi jusqu\'aux résultats.']],
                ['hero', 'about', 'services', 'stats', 'testimonials', 'faq', 'contact']],
            'immobilier' => ['Immobilier', 'home', [['#10B981', '#0F2A24', '#FBBF24', '#F3FBF8'], ['#2F4858', '#1A262E', '#C9A66B', '#F8F6F2'], ['#14B8A6', '#0A1F1E', '#F43F5E', '#F4F8F8']],
                'Trouvez le bien qui vous ressemble', 'Vente, location et gestion de biens à {ville} : appartements, villas, terrains et bureaux.', 'Nous contacter',
                'L\'agence', '{societe} vous accompagne dans tous vos projets immobiliers avec transparence et un réseau de biens vérifiés.',
                [['home', 'Vente', 'Villas, appartements, terrains titrés.'], ['map', 'Location', 'Logements et bureaux meublés ou vides.'], ['shield', 'Gestion locative', 'Loyers, entretien, état des lieux.'], ['scale', 'Conseil juridique', 'Accompagnement jusqu\'à la signature.']],
                ['hero', 'services', 'products', 'about', 'stats', 'testimonials', 'contact']],
            'education' => ['École & formation', 'book', [['#2563EB', '#172554', '#F59E0B', '#F5F8FF'], ['#7B2D26', '#1F1412', '#D4A373', '#FAF7F2'], ['#7C3AED', '#170F2E', '#34D399', '#F7F5FD']],
                'Apprendre, réussir, s\'épanouir', 'Un enseignement de qualité, un encadrement bienveillant et des résultats reconnus.', 'Inscrire mon enfant',
                'Notre établissement', '{societe} accueille élèves et apprenants dans un cadre sécurisé, avec des enseignants qualifiés et des programmes complets.',
                [['book', 'Enseignement', 'Programmes officiels et soutien scolaire.'], ['users', 'Encadrement', 'Petits effectifs et suivi individualisé.'], ['cpu', 'Numérique', 'Salle informatique et outils modernes.'], ['award', 'Activités', 'Sport, arts, clubs et sorties pédagogiques.']],
                ['hero', 'about', 'services', 'stats', 'events', 'faq', 'jobs', 'contact']],
            'evenementiel' => ['Événementiel', 'calendar', [['#EC4899', '#1F1235', '#FACC15', '#FFF5FB'], ['#3D2C8D', '#140F2D', '#D4AF37', '#F8F7FC'], ['#F97316', '#120E1F', '#A855F7', '#F7F5F9']],
                'Des événements inoubliables', 'Mariages, conférences, soirées d\'entreprise et salons : nous orchestrons chaque détail.', 'Organiser mon événement',
                'L\'agence', '{societe} conçoit et réalise des événements sur mesure, de la décoration à la logistique, avec un réseau de prestataires fiables.',
                [['calendar', 'Organisation', 'Planning, coordination et gestion le jour J.'], ['sparkles', 'Décoration', 'Scénographie, fleurs, éclairage.'], ['users', 'Traiteur & animation', 'Une équipe de partenaires sélectionnés.'], ['camera', 'Photo & vidéo', 'Des souvenirs à la hauteur de l\'événement.']],
                ['hero', 'services', 'events', 'gallery', 'testimonials', 'contact']],
            'hotel' => ['Hôtel & résidence', 'bed', [['#0891B2', '#0C2B36', '#F59E0B', '#F2FAFC'], ['#5B4636', '#1D1712', '#BFA27A', '#FAF7F3'], ['#0D9488', '#081B1A', '#FB7185', '#F3F8F7']],
                'Votre séjour, notre attention', 'Chambres confortables, service attentionné et emplacement idéal à {ville}.', 'Réserver',
                'L\'établissement', '{societe} vous accueille pour vos séjours d\'affaires ou de détente, avec des chambres équipées et un personnel disponible.',
                [['bed', 'Chambres & suites', 'Climatisées, Wi-Fi, télévision, eau chaude.'], ['utensils', 'Restaurant', 'Petit-déjeuner et cuisine locale.'], ['briefcase', 'Salle de réunion', 'Pour vos séminaires et formations.'], ['car', 'Navette', 'Transfert aéroport sur demande.']],
                ['hero', 'about', 'services', 'gallery', 'testimonials', 'booking', 'contact']],
            'transport' => ['Transport & logistique', 'truck', [['#EA580C', '#1C1917', '#0EA5E9', '#FFF7F2'], ['#264653', '#14232A', '#E9C46A', '#F7F8F6'], ['#0284C7', '#0B1324', '#F97316', '#F3F6FA']],
                'Vos marchandises, livrées à temps', 'Transport national et sous-régional, entreposage et livraison du dernier kilomètre.', 'Demander un devis',
                'L\'entreprise', '{societe} dispose d\'une flotte entretenue et de chauffeurs expérimentés pour acheminer vos marchandises en toute sécurité.',
                [['truck', 'Transport routier', 'Lots complets et groupage.'], ['home', 'Entreposage', 'Stockage sécurisé et gestion des stocks.'], ['clock', 'Livraison express', 'En ville, le jour même.'], ['globe', 'Sous-région', 'Burkina, Mali, Ghana, Togo…']],
                ['hero', 'services', 'stats', 'about', 'testimonials', 'contact']],
            'agriculture' => ['Agriculture & élevage', 'leaf', [['#16A34A', '#14261A', '#EAB308', '#F5FBF3'], ['#556B2F', '#1A1F12', '#C6A15B', '#F8F7F0'], ['#65A30D', '#101A0B', '#F97316', '#F5F7F1']],
                'Du champ à votre table', 'Production, transformation et vente de produits agricoles et d\'élevage de qualité.', 'Commander',
                'Notre exploitation', '{societe} produit dans le respect des sols et des animaux, et accompagne les producteurs avec des intrants et des conseils.',
                [['leaf', 'Production', 'Vivriers, maraîchage, cultures de rente.'], ['heart', 'Élevage', 'Volailles, bovins, porcs, poissons.'], ['truck', 'Distribution', 'Livraison aux marchés, restaurants et particuliers.'], ['users', 'Accompagnement', 'Formation et suivi des producteurs.']],
                ['hero', 'about', 'products', 'services', 'gallery', 'events', 'contact']],
            'tech' => ['Tech & startup', 'cpu', [['#7C3AED', '#13072E', '#22D3EE', '#F7F5FF'], ['#1E293B', '#0F172A', '#94A3B8', '#F8FAFC'], ['#06B6D4', '#020617', '#A3E635', '#F1F5F9']],
                'La technologie au service de votre croissance', 'Logiciels, applications et solutions numériques pensés pour les entreprises africaines.', 'Demander une démo',
                'Notre mission', '{societe} conçoit des solutions simples et fiables qui font gagner du temps et de l\'argent à ses clients.',
                [['cpu', 'Logiciels', 'Applications web, mobiles et de bureau.'], ['globe', 'Sites internet', 'Vitrines, boutiques en ligne, portails.'], ['shield', 'Cybersécurité', 'Audit, protection et sauvegardes.'], ['chat', 'Support', 'Une équipe réactive à votre écoute.']],
                ['hero', 'services', 'stats', 'about', 'products', 'testimonials', 'faq', 'jobs', 'contact']],
            'juridique' => ['Cabinet juridique & comptable', 'scale', [['#1D4ED8', '#0F1B3D', '#F59E0B', '#F5F7FD'], ['#3E2723', '#1A1210', '#B8975A', '#FAF8F4'], ['#334155', '#0B1120', '#38BDF8', '#F4F5F7']],
                'Un conseil rigoureux, des décisions sereines', 'Droit des affaires, fiscalité, comptabilité et accompagnement des entreprises.', 'Prendre rendez-vous',
                'Le cabinet', '{societe} conseille entreprises et particuliers avec rigueur, confidentialité et une parfaite maîtrise du droit OHADA.',
                [['scale', 'Droit des affaires', 'Création, contrats, contentieux.'], ['briefcase', 'Comptabilité', 'Tenue, bilans, états financiers SYSCOHADA.'], ['shield', 'Fiscalité', 'Déclarations, optimisation, contrôles.'], ['users', 'Social', 'Contrats de travail, paie, CNPS.']],
                ['hero', 'about', 'services', 'faq', 'booking', 'contact']],
            'association' => ['Association & ONG', 'users', [['#0EA5A4', '#0B2A2A', '#F97316', '#F2FBFB'], ['#6D4C41', '#1F1714', '#A5D6A7', '#F9F7F4'], ['#E11D48', '#1A0B10', '#FBBF24', '#FBF5F6']],
                'Agir ensemble pour changer des vies', 'Éducation, santé, environnement : rejoignez nos actions sur le terrain.', 'Nous soutenir',
                'Notre mission', '{societe} mène des projets concrets avec les communautés et ses partenaires, en toute transparence.',
                [['heart', 'Solidarité', 'Aide aux familles et aux personnes vulnérables.'], ['book', 'Éducation', 'Kits scolaires, alphabétisation, bourses.'], ['leaf', 'Environnement', 'Reboisement et sensibilisation.'], ['users', 'Bénévolat', 'Rejoignez nos équipes.']],
                ['hero', 'about', 'stats', 'services', 'events', 'gallery', 'contact']],
            'sport' => ['Sport & fitness', 'dumbbell', [['#EF4444', '#18181B', '#FACC15', '#FFF6F6'], ['#1F2937', '#0B0F15', '#D97706', '#F7F6F3'], ['#84CC16', '#0A0A0A', '#F43F5E', '#F4F5F2']],
                'Dépassez vos limites', 'Musculation, cardio, cours collectifs et coaching personnalisé à {ville}.', 'Essai gratuit',
                'La salle', '{societe} vous accueille dans un espace équipé avec des coachs diplômés pour atteindre vos objectifs.',
                [['dumbbell', 'Musculation', 'Matériel récent pour tous les niveaux.'], ['heart', 'Cardio', 'Tapis, vélos, rameurs.'], ['users', 'Cours collectifs', 'Zumba, boxe, cross training.'], ['award', 'Coaching', 'Programmes et suivi personnalisés.']],
                ['hero', 'services', 'stats', 'gallery', 'testimonials', 'booking', 'contact']],
            'photo' => ['Photo & création', 'camera', [['#F43F5E', '#1C1917', '#FBBF24', '#FFF7F7'], ['#0F0F0F', '#0F0F0F', '#BFA37E', '#F7F5F2'], ['#8B5CF6', '#0C0A14', '#F59E0B', '#F5F4F7']],
                'Des images qui racontent votre histoire', 'Photographie, vidéo et création graphique pour particuliers et entreprises.', 'Réserver une séance',
                'Le studio', '{societe} capture vos moments et valorise votre image avec un regard créatif et un matériel professionnel.',
                [['camera', 'Photographie', 'Portraits, mariages, produits, événements.'], ['sparkles', 'Vidéo', 'Clips, films d\'entreprise, drone.'], ['star', 'Graphisme', 'Logos, affiches, identité visuelle.'], ['gift', 'Albums & tirages', 'Des souvenirs à offrir.']],
                ['hero', 'gallery', 'services', 'about', 'testimonials', 'booking', 'contact']],
            'auto' => ['Garage & automobile', 'car', [['#DC2626', '#111827', '#F59E0B', '#FFF6F5'], ['#374151', '#111317', '#C0A062', '#F6F5F3'], ['#2563EB', '#05070D', '#FACC15', '#F3F4F6']],
                'Votre véhicule entre des mains expertes', 'Entretien, diagnostic, réparation et pièces détachées, toutes marques.', 'Prendre rendez-vous',
                'Le garage', '{societe} entretient et répare votre véhicule avec des techniciens qualifiés et des pièces de qualité.',
                [['car', 'Entretien', 'Vidange, freins, pneus, climatisation.'], ['cpu', 'Diagnostic', 'Valise électronique toutes marques.'], ['hammer', 'Carrosserie', 'Tôlerie et peinture.'], ['bag', 'Pièces détachées', 'Neuves et d\'origine.']],
                ['hero', 'services', 'about', 'products', 'testimonials', 'booking', 'contact']],
        ];
    }

    /** @return array<string, array> clé => modèle complet */
    public static function all(): array
    {
        static $all = null;
        if ($all !== null) {
            return $all;
        }
        $all = [];
        foreach (self::sectors() as $sk => [$sname, $icon, $palettes, $title, $subtitle, $cta, $aboutTitle, $about, $services, $sections]) {
            $i = 0;
            foreach (self::STYLES as $stk => $st) {
                [$primary, $dark, $accent, $bg] = $palettes[$i++];
                $all[$sk.'-'.$stk] = [
                    'key' => $sk.'-'.$stk, 'name' => $sname.' · '.$st['name'], 'sector' => $sk, 'sector_name' => $sname, 'style' => $stk, 'style_name' => $st['name'], 'icon' => $icon,
                    'description' => $st['description'],
                    'theme' => ['primary' => $primary, 'dark' => $dark, 'accent' => $accent, 'background' => $bg, 'hero' => $st['hero'], 'heading_font' => $st['heading'], 'body_font' => $st['body'],
                        'radius' => $st['radius'], 'cards' => $st['cards'], 'nav' => $st['nav']],
                    'content' => ['hero_title' => $title, 'hero_subtitle' => $subtitle, 'cta_label' => $cta, 'cta_target' => in_array('booking', $sections, true) ? 'booking' : 'contact',
                        'about_title' => $aboutTitle, 'about_text' => $about, 'services_title' => 'Nos services',
                        'services' => array_map(fn ($s) => ['icon' => $s[0], 'title' => $s[1], 'text' => $s[2]], $services)],
                    'sections' => $sections,
                ];
            }
        }

        return $all;
    }

    public static function get(?string $key): ?array
    {
        return $key ? (self::all()[$key] ?? null) : null;
    }

    /** Liste allégée pour la galerie. */
    public static function gallery(): array
    {
        return array_values(array_map(fn ($t) => array_intersect_key($t, array_flip(['key', 'name', 'sector', 'sector_name', 'style', 'style_name', 'icon', 'description', 'theme', 'sections']))
            + ['hero_title' => $t['content']['hero_title']], self::all()));
    }
}
