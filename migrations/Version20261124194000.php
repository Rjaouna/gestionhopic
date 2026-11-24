<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124194000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Permettre le retrait d’un compteur tout en conservant ses demandes et mouvements.';
    }

    public function up(Schema $schema): void
    {
        // SQLite ignores NULL values for uniqueness, allowing a removed counter's name to be reused.
        $this->addSql('ALTER TABLE counter_type ADD COLUMN listed BOOLEAN DEFAULT 1 CHECK (listed IS NULL OR listed = 1)');
        $this->addSql('DROP INDEX UNIQ_C3538E4E5E237E06');
        $this->addSql('CREATE UNIQUE INDEX counter_type_listed_name ON counter_type (name, listed)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les compteurs retirés peuvent rester référencés et partager le nom d’un nouveau compteur.');
    }
}
