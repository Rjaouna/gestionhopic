<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124214500 extends AbstractMigration
{
    private const string SOURCE = 'https://hopic.pro/ebpsynch/items.csv';
    private const string PHOTOS = 'https://hopic.pro/ebpsynch/{unique_id}.png';

    public function getDescription(): string
    {
        return 'Configurer les photos PNG de la source EBP Hopic.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE ebp_connection SET photo_path = ? WHERE id = 1 AND source_url = ? AND photo_path = ''",
            [self::PHOTOS, self::SOURCE]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE ebp_connection SET photo_path = '' WHERE id = 1 AND source_url = ? AND photo_path = ?",
            [self::SOURCE, self::PHOTOS]);
    }
}
