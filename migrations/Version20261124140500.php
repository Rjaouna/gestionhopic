<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124140500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Configurer les justificatifs autorisés par type d’absence.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE leave_type ADD COLUMN allow_attachment BOOLEAN DEFAULT 1 NOT NULL');
        $this->addSql("UPDATE leave_type SET allow_attachment = 0
            WHERE requires_attachment = 0 AND name IN ('Congés payés', 'RTT', 'Récupération')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE leave_type DROP COLUMN allow_attachment');
    }
}
