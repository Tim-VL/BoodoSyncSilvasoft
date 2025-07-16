<?php

namespace BoodoSyncSilvasoft\Commands;

use BoodoSyncSilvasoft\Service\MergeGuestAccountService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand('boodo:merge-guest-customers', 'Merge guest customers into the oldest registered customer with the same email.')]
class MergeGuestAccountCommand extends Command
{
    public function __construct(private readonly MergeGuestAccountService $mergeGuestAccountService) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->mergeGuestAccountService->executeMerge($input, $output);
        return Command::SUCCESS;
    }
}
