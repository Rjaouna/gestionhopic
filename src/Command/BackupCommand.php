<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'tempo:backup', description: 'Créer un instantané cohérent de la base SQLite')]
final readonly class BackupCommand
{
    public function __construct(private Connection $db, #[Autowire('%kernel.project_dir%/var/backups')] private string $directory)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        (new Filesystem())->mkdir($this->directory, 0700);
        $name = 'tempo-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.db';
        $path = $this->directory.'/'.$name;
        $this->db->executeStatement('VACUUM INTO ?', [$path]);
        chmod($path, 0600);
        $io->success('Sauvegarde SQLite créée dans var/backups/'.$name);

        return Command::SUCCESS;
    }
}
