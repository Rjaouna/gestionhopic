<?php

namespace App\Command;

use App\Service\Inventory\EbpSynchronization;
use DomainException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'tempo:ebp:synchroniser', description: 'Traiter une synchronisation EBP en attente')]
final readonly class EbpSyncCommand
{
    public function __construct(private EbpSynchronization $sync)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Lancer une nouvelle mise à jour si le catalogue est au repos')] bool $lancer = false,
        #[Option('Importer un export EBP depuis un fichier local')] ?string $fichier = null,
    ): int {
        try {
            if ($fichier !== null) {
                $status = $this->sync->importFile($fichier);
            } else {
                if ($lancer) {
                    $this->sync->enqueue();
                }
                $status = $this->sync->run();
            }
        } catch (DomainException $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }
        if ($status['state'] === 'failed') {
            $io->error($status['message']);

            return Command::FAILURE;
        }
        if ($status['state'] === 'waiting') {
            $io->note($status['message'].' Nouvelle tentative programmée.');
        } elseif ($status['state'] === 'success') {
            $io->success($status['lastCount'].' articles dans le dernier export synchronisé.');
        }

        return Command::SUCCESS;
    }
}
