<?php

declare(strict_types=1);

namespace BoodoSyncSilvasoft\Commands;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Shopware\Core\Framework\Context;
use BoodoSyncSilvasoft\Service\StockSyncService;

#[AsCommand('boodo:synchronize:stock', 'Synchronizes stock between Shopware and Silvasoft (default: pull from Silvasoft)')]
class SynchronizeStockWithSilvasoftCommand extends Command
{
    public function __construct(
        private readonly StockSyncService $stockSyncService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'direction',
            null,
            InputOption::VALUE_OPTIONAL,
            'Direction of sync: pull (Silvasoft → Shopware) or push (Shopware → Silvasoft)',
            'pull'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createDefaultContext();
        $direction = $input->getOption('direction') ?? 'pull';

        if ($direction === 'pull') {
            $io->text('Pulling stock from Silvasoft and updating Shopware...');
            $this->stockSyncService->pullStockFromSilvasoft($context, $io);
            $io->success('Stock successfully pulled from Silvasoft and updated in Shopware.');
        } elseif ($direction === 'push') {
            $io->text('Pushing stock from Shopware to Silvasoft...');
            $this->stockSyncService->pushStockToSilvasoft($context, $io);
            $io->success('Stock successfully pushed from Shopware to Silvasoft.');
        } else {
            $io->error('Invalid direction. Use "pull" or "push".');
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }
}
