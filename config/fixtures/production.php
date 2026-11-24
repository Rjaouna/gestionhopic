<?php

declare(strict_types=1);

$holidays = [
    '01-01' => 'Jour de l’An',
    '01-11' => 'Manifeste de l’Indépendance',
    '01-14' => 'Nouvel An amazigh',
    '05-01' => 'Fête du Travail',
    '07-30' => 'Fête du Trône',
    '08-14' => 'Allégeance de Oued Eddahab',
    '08-20' => 'Révolution du Roi et du Peuple',
    '08-21' => 'Fête de la Jeunesse',
    '10-31' => 'Fête de l’Unité',
    '11-06' => 'Anniversaire de la Marche verte',
    '11-18' => 'Fête de l’Indépendance',
];

$calendar = [];
foreach ([2026, 2027] as $year) {
    foreach ($holidays as $day => $name) {
        $calendar[$year.'-'.$day] = $name;
    }
}
$calendar += [
    '2026-03-20' => 'Aïd Al-Fitr (1er jour)',
    '2026-03-21' => 'Aïd Al-Fitr (2e jour)',
    '2026-05-27' => 'Aïd Al-Adha (1er jour)',
    '2026-05-28' => 'Aïd Al-Adha (2e jour)',
    '2026-06-17' => 'Nouvel An hégirien',
    '2026-08-25' => 'Aïd Al-Mawlid (1er jour)',
    '2026-08-26' => 'Aïd Al-Mawlid (2e jour)',
];
ksort($calendar);

return [
    'admin' => ['email' => 'hamza-benamour@hotmail.com', 'firstName' => 'Hamza', 'lastName' => 'Benamour'],
    'appearance' => ['name' => 'Hopic', 'suffix' => 'RH', 'theme' => 'lagoon'],
    'schedule' => ['name' => 'Du lundi au samedi', 'days' => ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi']],
    'departments' => [
        ['name' => 'Ressources humaines', 'color' => '#137b66'],
        ['name' => 'Design', 'color' => '#8a70c9'],
        ['name' => 'Produit', 'color' => '#507cb5'],
        ['name' => 'Commercial', 'color' => '#c48a3d'],
        ['name' => 'Opérations', 'color' => '#548c7a'],
        ['name' => 'Finance', 'color' => '#b26784'],
    ],
    'counters' => [
        ['name' => 'Congés payés', 'color' => '#16846a'],
        ['name' => 'RTT', 'color' => '#9378d2'],
        ['name' => 'Récupération', 'color' => '#4c8ac9'],
    ],
    'leaveTypes' => [
        ['name' => 'Congés payés', 'tone' => 'mint', 'icon' => 'sun', 'counter' => 'Congés payés', 'allow_attachment' => 0],
        ['name' => 'RTT', 'tone' => 'violet', 'icon' => 'hourglass-split', 'counter' => 'RTT', 'allow_attachment' => 0],
        ['name' => 'Arrêt maladie', 'tone' => 'coral', 'icon' => 'heart-pulse',
            'requires_approval' => 0, 'requires_attachment' => 1, 'private_reason' => 1],
        ['name' => 'Congé exceptionnel', 'tone' => 'amber', 'icon' => 'stars'],
        ['name' => 'Congé sans solde', 'tone' => 'blue', 'icon' => 'calendar3'],
        ['name' => 'Récupération', 'tone' => 'blue', 'icon' => 'clock-history', 'counter' => 'Récupération', 'allow_attachment' => 0],
    ],
    'holidays' => $calendar,
];
