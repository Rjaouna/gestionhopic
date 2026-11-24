<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124171000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adopter un horaire commun et conserver les jours travaillés des demandes existantes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE leave_request ADD COLUMN workdays INTEGER DEFAULT 31 NOT NULL');
        $this->addSql('UPDATE leave_request SET workdays = COALESCE((
            SELECT s.days FROM employee e JOIN work_schedule s ON s.id = e.schedule_id
            WHERE e.id = leave_request.employee_id
        ), 31)');

        $this->addSql("INSERT INTO work_schedule (name, days)
            SELECT 'Du lundi au vendredi', 31 WHERE NOT EXISTS (SELECT 1 FROM work_schedule)");
        $this->addSql('CREATE TEMP TABLE retained_work_schedule AS
            SELECT s.id FROM work_schedule s
            LEFT JOIN employee e ON e.schedule_id = s.id AND e.active = 1 AND e.archived = 0
            GROUP BY s.id ORDER BY COUNT(e.id) DESC, (s.days = 31) DESC, s.id LIMIT 1');
        $this->addSql('UPDATE employee SET schedule_id = (SELECT id FROM retained_work_schedule)
            WHERE schedule_id IS NOT NULL AND schedule_id <> (SELECT id FROM retained_work_schedule)');
        $this->addSql('DELETE FROM work_schedule WHERE id <> (SELECT id FROM retained_work_schedule)');
        $this->addSql('DROP TABLE retained_work_schedule');

        $this->addSql("CREATE TRIGGER work_schedule_single_insert BEFORE INSERT ON work_schedule
            WHEN EXISTS (SELECT 1 FROM work_schedule)
            BEGIN SELECT RAISE(ABORT, 'Un seul horaire de travail est autorisé.'); END");
        $this->addSql("CREATE TRIGGER work_schedule_single_delete BEFORE DELETE ON work_schedule
            BEGIN SELECT RAISE(ABORT, 'L’horaire de travail est obligatoire.'); END");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('La fusion des anciens horaires est restaurable depuis la sauvegarde SQLite.');
    }
}
