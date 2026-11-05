<?php

namespace App\Modules\Sites;

/** Pictogrammes au trait (24 × 24, couleur du texte) dessinés pour les sites vitrines. */
class SiteIcons
{
    public const PATHS = [
        'utensils' => 'M4 3v7a2 2 0 0 0 4 0V3M6 12v9M17 3c-2 1-3 4-3 7h3M17 3v18',
        'bag' => 'M5 8h14l-1 12H6L5 8zM9 8V6a3 3 0 0 1 6 0v2',
        'scissors' => 'M3 6a3 3 0 1 0 6 0a3 3 0 1 0-6 0M3 18a3 3 0 1 0 6 0a3 3 0 1 0-6 0M8.5 8.5 20 20M8.5 15.5 20 4',
        'heart' => 'M12 20s-7-4.4-9.3-8.8A5.2 5.2 0 0 1 12 6.4a5.2 5.2 0 0 1 9.3 4.8C19 15.6 12 20 12 20z',
        'hammer' => 'M14 4l6 6-3 3-6-6zM11 7l-8 8 3 3 8-8',
        'briefcase' => 'M3 8h18v11H3zM8 8V5h8v3M3 13h18',
        'home' => 'M3 11l9-7 9 7M5 10v10h14V10M10 20v-6h4v6',
        'book' => 'M4 19V5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2zM8 7h7',
        'calendar' => 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4',
        'bed' => 'M3 18V8M3 14h18v4M21 14v-2a3 3 0 0 0-3-3h-8v5M5.5 11.5a1.5 1.5 0 1 0 3 0a1.5 1.5 0 1 0-3 0',
        'truck' => 'M2 6h12v10H2zM14 10h4l3 3v3h-7M4 18a2 2 0 1 0 4 0a2 2 0 1 0-4 0M15 18a2 2 0 1 0 4 0a2 2 0 1 0-4 0',
        'leaf' => 'M5 19c0-9 6-14 15-14 0 9-5 15-14 15M5 19l8-8',
        'cpu' => 'M7 7h10v10H7zM10 10h4v4h-4zM9 3v4M15 3v4M9 17v4M15 17v4M3 9h4M3 15h4M17 9h4M17 15h4',
        'scale' => 'M12 3v18M7 21h10M5 7h14M5 7l-3 6a3 3 0 0 0 6 0zM19 7l-3 6a3 3 0 0 0 6 0z',
        'users' => 'M5 7a4 4 0 1 0 8 0a4 4 0 1 0-8 0M2 21a7 7 0 0 1 14 0M16 3.5a4 4 0 0 1 0 7.5M22 21a7 7 0 0 0-4-6.3',
        'dumbbell' => 'M6 7v10M3 9v6M18 7v10M21 9v6M6 12h12',
        'camera' => 'M3 7h4l2-3h6l2 3h4v13H3zM8 13a4 4 0 1 0 8 0a4 4 0 1 0-8 0',
        'car' => 'M3 13l2-6h14l2 6v5H3zM3 13h18M7 18v2M17 18v2',
        'sparkles' => 'M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8zM19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z',
        'shield' => 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6zM9 12l2 2 4-4',
        'award' => 'M6 9a6 6 0 1 0 12 0a6 6 0 1 0-12 0M8.5 14 7 22l5-3 5 3-1.5-8',
        'clock' => 'M3 12a9 9 0 1 0 18 0a9 9 0 1 0-18 0M12 7v5l3 2',
        'phone' => 'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2',
        'map' => 'M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12zM9.5 9a2.5 2.5 0 1 0 5 0a2.5 2.5 0 1 0-5 0',
        'mail' => 'M3 5h18v14H3zM3 6l9 7 9-7',
        'check' => 'M20 6 9 17l-5-5',
        'star' => 'M12 3l2.8 5.7 6.2.9-4.5 4.4 1 6.2L12 17.3l-5.5 2.9 1-6.2L3 9.6l6.2-.9z',
        'globe' => 'M3 12a9 9 0 1 0 18 0a9 9 0 1 0-18 0M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18',
        'chat' => 'M4 5h16v11H8l-4 4z',
        'gift' => 'M3 9h18v4H3zM5 13v8h14v-8M12 9v12M12 9C10 5 6 5 6 7.5S10 9 12 9zM12 9c2-4 6-4 6-1.5S14 9 12 9z',
        'arrow' => 'M5 12h14M13 6l6 6-6 6',
        'menu' => 'M4 7h16M4 12h16M4 17h16',
    ];

    public static function svg(string $name, int $size = 24, float $stroke = 1.8): string
    {
        $d = self::PATHS[$name] ?? self::PATHS['check'];

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="'.$stroke.'" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="'.$d.'"/></svg>';
    }
}
