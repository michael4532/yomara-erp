<?php

namespace App\Support;

/**
 * Villes et communes de Côte d'Ivoire proposées sur les formulaires (bornes d'accueil, inscriptions).
 * Une saisie sans accents ou en minuscules (« bouake », « YOPOUGON ») est ramenée au nom officiel pour les statistiques.
 * Même liste dans l'app : lib/core/localites_ci.dart.
 */
class Localites
{
    public const CI = [
        'Abidjan',
        'Abobo',
        'Adjamé',
        'Anyama',
        'Attécoubé',
        'Bingerville',
        'Cocody',
        'Koumassi',
        'Marcory',
        'Plateau',
        'Port-Bouët',
        'Songon',
        'Treichville',
        'Yopougon',
        'Abengourou',
        'Aboisso',
        'Adiaké',
        'Adzopé',
        'Agboville',
        'Agnibilékrou',
        'Akoupé',
        'Alépé',
        'Arrah',
        'Assinie',
        'Ayamé',
        'Azaguié',
        'Bangolo',
        'Béoumi',
        'Biankouma',
        'Bocanda',
        'Bondoukou',
        'Bongouanou',
        'Bonoua',
        'Bouaflé',
        'Bouaké',
        'Bouna',
        'Boundiali',
        'Buyo',
        'Dabakala',
        'Dabou',
        'Daloa',
        'Danané',
        'Daoukro',
        'Dimbokro',
        'Divo',
        'Duékoué',
        'Ferkessédougou',
        'Fresco',
        'Gagnoa',
        'Grand-Bassam',
        'Grand-Béréby',
        'Grand-Lahou',
        'Guiglo',
        'Guitry',
        'Issia',
        'Jacqueville',
        'Katiola',
        'Kong',
        'Korhogo',
        'Lakota',
        'M\'Bahiakro',
        'Man',
        'Mankono',
        'Méagui',
        'Minignan',
        'Niakaramandougou',
        'Odienné',
        'Oumé',
        'Sakassou',
        'San-Pédro',
        'Sassandra',
        'Séguéla',
        'Sikensi',
        'Sinfra',
        'Soubré',
        'Taabo',
        'Tabou',
        'Tanda',
        'Tiassalé',
        'Tiébissou',
        'Tingréla',
        'Touba',
        'Toulépleu',
        'Toumodi',
        'Vavoua',
        'Yamoussoukro',
        'Zouan-Hounien',
        'Zuénoula',
    ];

    public static function fold(string $v): string
    {
        $v = mb_strtolower(trim(preg_replace('/\s+/', ' ', $v)));
        $v = strtr($v, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', '’' => "'"]);

        return preg_replace('/[\s\-]+/', '-', $v);
    }

    /** Communes du district d'Abidjan : regroupées sous « Abidjan » dans les statistiques. */
    public const ABIDJAN = ['Abobo', 'Adjamé', 'Anyama', 'Attécoubé', 'Bingerville', 'Cocody', 'Koumassi', 'Marcory', 'Plateau', 'Port-Bouët', 'Songon', 'Treichville', 'Yopougon'];

    /** Ville dont dépend une commune (Yopougon → Abidjan), sinon la localité elle-même. */
    public static function cityOf(string $place): string
    {
        return in_array($place, self::ABIDJAN, true) ? 'Abidjan' : $place;
    }

    /** Nom officiel de la localité si elle est connue, sinon null. */
    public static function canonical(string $v): ?string
    {
        static $map = null;
        $map ??= collect(self::CI)->mapWithKeys(fn ($n) => [self::fold($n) => $n])->all();

        return $map[self::fold($v)] ?? null;
    }
}
