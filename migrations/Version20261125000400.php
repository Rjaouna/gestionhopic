<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enregistrer l’annulation du délai de correction des affectations d’inventaire.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inventory_assignment ADD COLUMN review_skipped_at INTEGER DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inventory_assignment DROP COLUMN review_skipped_at');
    }
}
