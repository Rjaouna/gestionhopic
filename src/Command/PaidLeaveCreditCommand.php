<?php

namespace App\Command;

use App\Service\PaidLeaveAccrual;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'tempo:conges:crediter', description: 'Créditer les congés payés arrivés à échéance')]
final readonly class PaidLeaveCreditCommand
{
    public function __construct(private PaidLeaveAccrual $accrual)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->success('Nombre de collaborateurs crédités : '.$this->accrual->creditDue());

        return Command::SUCCESS;
    }
}
