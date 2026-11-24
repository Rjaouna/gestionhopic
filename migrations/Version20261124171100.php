<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124171100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajouter les jours fériés du Maroc pour 2026 et les dates fixes de 2027.';
    }

    public function up(Schema $schema): void
    {
        // Maroc.ma, calendrier des fêtes nationales et religieuses.
        $fixed = [
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
        foreach ([2026, 2027] as $year) {
            foreach ($fixed as $monthDay => $name) {
                $this->addSql('INSERT INTO holiday (day, name) VALUES (?, ?) ON CONFLICT(day) DO NOTHING',
                    [$year.'-'.$monthDay, $name]);
            }
        }

        // Dates grégoriennes annoncées par le ministère des Habous pour 1447 et 1448.
        $confirmed = [
            '2026-03-20' => 'Aïd Al-Fitr (1er jour)',
            '2026-03-21' => 'Aïd Al-Fitr (2e jour)',
            '2026-05-27' => 'Aïd Al-Adha (1er jour)',
            '2026-05-28' => 'Aïd Al-Adha (2e jour)',
            '2026-06-17' => 'Nouvel An hégirien',
            '2026-08-25' => 'Aïd Al-Mawlid (1er jour)',
            '2026-08-26' => 'Aïd Al-Mawlid (2e jour)',
        ];
        foreach ($confirmed as $day => $name) {
            $this->addSql('INSERT INTO holiday (day, name) VALUES (?, ?) ON CONFLICT(day) DO NOTHING', [$day, $name]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les dates du calendrier peuvent être gérées dans les paramètres de l’application.');
    }
}
