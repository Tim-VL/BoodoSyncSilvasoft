<?php

declare(strict_types=1);

namespace BoodoSyncSilvasoft\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Symfony\Component\Console\Style\SymfonyStyle;
use Shopware\Core\Framework\Pricing\Price;

class StockSyncService
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
        $this->apiUrl = $this->systemConfigService->get('BoodoSyncSilvasoft.config.apiUrl');
        $this->apiKey = $this->systemConfigService->get('BoodoSyncSilvasoft.config.apiKey');
        $this->apiUser = $this->systemConfigService->get('BoodoSyncSilvasoft.config.apiUser');
    }

    /**
     * Pullt den Lagerbestand aus Silvasoft und aktualisiert Shopware
     */
    public function pullStockFromSilvasoft(Context $context, SymfonyStyle $io): void
    {
        $limit = 100;
        $offset = 0;
        $updatedCount = 0;
        do {
            $url = rtrim($this->apiUrl, '/').'/rest/listproducts?Limit=' . $limit . '&Offset=' . $offset;
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'ApiKey' => $this->apiKey,
                    'Username' => $this->apiUser
                ],
            ]);
            $statusCode = $response->getStatusCode();
            $contentType = $response->getHeaders(false)['content-type'][0] ?? '';
            $body = $response->getContent(false);
            if ($statusCode !== 200 || stripos($contentType, 'application/json') === false) {
                $io->error("Silvasoft API-Fehler ($statusCode): " . substr($body, 0, 400));
                return;
            }
            try {
                $data = $response->toArray(false);
            } catch (\Throwable $e) {
                $io->error('Fehler beim Parsen der Silvasoft-Antwort: ' . $e->getMessage());
                $io->writeln('Antwort war: ' . substr($body, 0, 400));
                return;
            }
            if (!is_array($data) || count($data) === 0) {
                break;
            }
            // 1. Alle Artikelnummern sammeln
            $articleNumbers = [];
            foreach ($data as $item) {
                if (isset($item['ArticleNumber']) && array_key_exists('StockQty', $item)) {
                    $articleNumbers[] = $item['ArticleNumber'];
                }
            }
            if (empty($articleNumbers)) {
                $offset += $limit;
                usleep(600000); // Rate-Limit
                continue;
            }
            // 2. Alle Produkte auf einmal holen
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsAnyFilter('productNumber', $articleNumbers));
            $products = $this->productRepository->search($criteria, $context)->getEntities();
            // 3. Produkt-Mapping
            $productMap = [];
            foreach ($products as $product) {
                $productMap[$product->getProductNumber()] = $product;
            }
            // 4. Updates vorbereiten
            $updates = [];
            foreach ($data as $item) {
                $article = $item['ArticleNumber'];
                if (isset($productMap[$article])) {
                    $updates[] = [
                        'id' => $productMap[$article]->getId(),
                        'stock' => (int)$item['StockQty'],
                        // 'price' => (int)$item['SalePrice'], // add price
                    ];
                    $updatedCount++;
                    $this->logger->info('[Silvasoft Sync] Pull: article '.$article.' → stock '.(int)$item['StockQty']);
                    $io->writeln("Updated: $article stock " . (int)$item['StockQty']);
                }
            }
            // 5. Updates als Batch ausführen
            if ($updates) {
                $this->productRepository->update($updates, $context);
            }
            $offset += $limit;
            // Rate-Limit: max. 2 Requests/Sekunde
            usleep(600000); // 0.6 Sekunde Pause
        } while (count($data) === $limit);
        $io->success("Fertig. Aktualisierte Produkte: $updatedCount");
    }

    /**
     * Pusht den Lagerbestand von Shopware zu Silvasoft
     */
    public function pushStockToSilvasoft(Context $context, \Symfony\Component\Console\Style\SymfonyStyle $io): void
    {
        $criteria = new Criteria();
        $products = $this->productRepository->search($criteria, $context);
        foreach ($products as $product) {
            $payload = [
                'ArticleNumber' => $product->getProductNumber(),
                'NewStockQty' => $product->getStock(),
                'NewSalePrice' => $product->getPrice()->first()?->getNet(), // Net or Gross price to sync
                'StockUpdateMode' => 'Absolute'
            ];
            // Logging/Debug-Ausgabe
            $this->logger->info('[Silvasoft Sync] Stock-Update-Payload', $payload);
            $io->writeln("\nUpdate payload for article " . $product->getProductNumber() . ":\n" . json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            try {
                $this->httpClient->request('PUT', rtrim($this->apiUrl, '/').'/rest/updateproduct/', [
                    'headers' => [
                        'Accept-Encoding' => 'gzip,deflate',
                        'Content-Type' => 'application/json',
                        'ApiKey' => $this->apiKey,
                        'Username' => $this->apiUser
                    ],
                    'json' => $payload,
                ]);
            // Rate-Limit: max. 2 Requests/Sekunde
            usleep(600000); // 0.6 Sekunde Pause
            } catch (\Exception $e) {
                $this->logger->error('[Silvasoft Sync] Error updating stock for '.$product->getProductNumber().': '.$e->getMessage());
            }
        }
    }
}
