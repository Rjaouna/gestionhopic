<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124201500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Configurer l’acquisition des congés payés et mémoriser les périodes déjà prises en compte.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE paid_leave_rule (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            monthly_units INTEGER NOT NULL,
            waiting_months INTEGER NOT NULL,
            automatic BOOLEAN NOT NULL,
            last_run_on VARCHAR(10) DEFAULT NULL,
            counter_type_id INTEGER DEFAULT NULL,
            CONSTRAINT FK_4219C9354B27D0B2 FOREIGN KEY (counter_type_id) REFERENCES counter_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('CREATE INDEX IDX_PAID_LEAVE_RULE_COUNTER ON paid_leave_rule (counter_type_id)');
        $this->addSql("INSERT INTO paid_leave_rule (id, counter_type_id, monthly_units, waiting_months, automatic)
            VALUES (1, (SELECT id FROM counter_type WHERE name = 'Congés payés' AND listed = 1 AND active = 1), 3, 6, 1)");
        $this->addSql('ALTER TABLE employee ADD COLUMN paid_leave_through VARCHAR(10) DEFAULT NULL');
        $this->addSql('UPDATE employee SET paid_leave_through = ? WHERE hired_on IS NOT NULL',
            [(new \DateTimeImmutable('today'))->format('Y-m-d')]);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les crédits déjà effectués sont enregistrés dans l’historique des soldes.');
    }
}
