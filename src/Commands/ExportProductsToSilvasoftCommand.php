<?php

declare(strict_types=1);

namespace BoodoSyncSilvasoft\Commands;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Shopware\Core\Content\Product\ProductEntity;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\Context;
use Psr\Log\LoggerInterface;

#[AsCommand('boodo:synchronize:products', 'Exports all existing products to Silvasoft')]
class ExportProductsToSilvasoftCommand extends Command
{
    private ?string $apiUrl;
    private ?string $apiKey;
    private ?string $apiUser;

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly HttpClientInterface $httpClient,
        private readonly EntityRepository $productRepository,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();

        $this->apiUrl = $this->systemConfigService->get('BoodoSyncSilvasoft.config.apiUrl');
        $this->apiKey = $this->systemConfigService->get('BoodoSyncSilvasoft.config.apiKey');
        $this->apiUser = $this->systemConfigService->get('BoodoSyncSilvasoft.config.apiUser');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->success('Start products export to Silvasoft');

        $context = Context::createDefaultContext();
        $mainProductsArray = [];

        $criteria = new Criteria();
        $criteria->addAssociation('categories');
        $criteria->addAssociation('tax');
        $criteria->addAssociation('prices');
        $criteria->addAssociation('unit');

        /** @var ProductCollection $products */
        $products = $this->productRepository->search($criteria, $context)->getEntities();

        foreach ($products as $product) {
            if ($product->getParentId() === null) {
                $mainProductsArray[$product->getId()] = [
                    'unit'   => $product->getUnit() ? $product->getUnit()->getTranslated()['name'] : '',
                    'priceNet' => $product->getPrice()->first()->getNet(),
                    'tax'    => $product->getTax() ? $product->getTax()->getTaxRate() : null,
                ];
            }
        }

        if ($products->count() === 0) {
            $io->warning('No products found.');
            return Command::SUCCESS;
        }

        $io->progressStart($products->count());

        foreach ($products as $product) {
            /** @var ProductEntity $product */
            $this->sendProductToSilvasoft($product, $mainProductsArray, $io);
            $io->progressAdvance();

            usleep(1400000); // Sleep 1.4 seconds
        }

        $io->progressFinish();
        $io->success('All products successfully exported!');

        return self::SUCCESS;
    }

    private function sendProductToSilvasoft(ProductEntity $product, array $mainProducts, SymfonyStyle $io): void
    {
        $productNumber = $product->getProductNumber();
        $name = $product->getName() ?? 'Unknown Produkt';
        $price = $product->getPrice() ? $product->getPrice()->first()->getNet() : $mainProducts[$product->getParentId()]['priceNet'];
        $ean = $product->getEan() ? $product->getEan() : '';

        $category = $product->getCategories()?->first();
        $categoryName = $category ? $category->getTranslation('name') : 'Uncategorized';

        if (!$product->getUnit() && !$product->getParentId()) {
            $unit = '';
        } elseif ($product->getParentId()) {
            $unit = $product->getUnit() ? $product->getUnit()->getTranslated()['name'] : $mainProducts[$product->getParentId()]['unit'];
        }

        $silvasoftPayload = [
            "ArticleNumber" => $productNumber,
            "NewName" => $name,
            "NewDescription" => $product->getDescription() ?? '',
            "NewSalePrice" => $price,
            "NewVATPercentage" => $product->getTax() ? $product->getTax()->getTaxRate() : $mainProducts[$product->getParentId()]['tax'],
            "EAN" => preg_replace('/\D/', '', $ean), // Only digits
            "CategoryName" => $categoryName
        ];

        $io->writeln("\nPayload for product {$productNumber}:");
        $io->writeln(json_encode($silvasoftPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        try {
            $response = $this->httpClient->request('POST', $this->apiUrl . '/rest/addproduct/', [
                'headers' => [
                    'Accept-Encoding' => 'gzip,deflate',
                    'Content-Type' => 'application/json',
                    'ApiKey' => $this->apiKey,
                    'Username' => $this->apiUser
                ],
                'json' => $silvasoftPayload
            ]);

            if ($response->getStatusCode() !== 200) {
                $message = 'Silvasoft API error for product ' . $productNumber . ': ' . $response->getContent(false);

                // Existing logger
                $this->logger->error($message);

                //  Custom log file for sync errors
                $this->writeSyncErrorLog($message, $silvasoftPayload);

                $io->warning($message);
            } else {
                $this->logger->info('Product successfully sent to Silvasoft: ' . $productNumber);
            }
        } catch (\Exception $e) {
            $message = 'Silvasoft API exception for product ' . $productNumber . ': ' . $e->getMessage();

            // Existing logger
            $this->logger->error($message);

            // 🆕 Custom log file for sync exceptions
            $this->writeSyncErrorLog($message, $silvasoftPayload);
        }
    }

    /**
     *  Write error to var/log/sync-error-YYYY-MM-DD.log
     */
    private function writeSyncErrorLog(string $message, array $payload = []): void
    {
        $logDir = dirname(__DIR__, 2) . '/../../../var/log/silvasoft';
        if (!file_exists($logDir)) {
            mkdir($logDir, 0777, true);
        }

        $date = (new \DateTime())->format('Y-m-d');
        $logPath = $logDir . '/product-sync-' . $date . '.log';

        $timestamp = (new \DateTime())->format('Y-m-d H:i:s');
        $entry = "[$timestamp] $message\n";

        if (!empty($payload)) {
            $entry .= json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        }

        $entry .= str_repeat('-', 80) . "\n";

        file_put_contents($logPath, $entry, FILE_APPEND);
    }
}
