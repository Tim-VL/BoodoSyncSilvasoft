<?php

declare(strict_types=1);

namespace BoodoSyncSilvasoft\Service\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use \Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\CountAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\CountResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Content\Product\ProductEntity;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

#[AsMessageHandler(handles: OrderUpdateTask::class)]
class OrderUpdateTaskHandler extends ScheduledTaskHandler
{
    private const LIMIT = 100;
    private ?string $apiUrl;
    private ?string $apiKey;
    private ?string $apiUser;
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        private readonly SystemConfigService $systemConfigService,
        private readonly HttpClientInterface $httpClient,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $categoryRepository,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($scheduledTaskRepository);
        $this->apiUrl = $this->systemConfigService->get(key: 'BoodoSyncSilvasoft.config.apiUrl');
        $this->apiKey = $this->systemConfigService->get('BoodoSyncSilvasoft.config.apiKey');
        $this->apiUser = $this->systemConfigService->get('BoodoSyncSilvasoft.config.apiUser');
    }
    public function run(): void
    {
        $context = Context::createDefaultContext();
        $offset = 0;
        $totalShopwareProducts = $this->getShopwareProductCount($context);
        while ($offset < $totalShopwareProducts) {
            $products = $this->fetchProductsFromSilvasoft($offset, self::LIMIT);
            if (empty($products)) {
                break;
            }
            $this->updateShopwareStockAndCategory($products, $context);
            $offset += self::LIMIT;
        }
    }

    private function fetchOrdedsFromSilvasoft(int $offset, int $limit): array
    {
        $maxRetries = 5;
        $retryDelay = 5;
    
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $this->httpClient->request('GET', $this->apiUrl . '/rest/listproducts', [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'ApiKey' => $this->apiKey,
                        'Username' => $this->apiUser
                    ],
                    'query' => [
                        'Limit' => $limit,
                        'Offset' => $offset,
                        'IncludeStockPositions' => true
                    ]
                ]);
    
                return json_decode($response->getContent(), true) ?? [];
    
            } catch (ClientException $e) {
                if ($e->getCode() === 429) {
                    $this->logger->warning(sprintf(
                        'Rate limit reached (429). Attempt %d from %d. Wait %d seconds...',
                        $attempt, $maxRetries, $retryDelay
                    ));
    
                    sleep($retryDelay);
                    continue;
                }
                throw $e;
            }
        }
    
        $this->logger->error('Maximum number of repetitions achieved when retrieving orders with offset ' . $offset);
        return [];
    }
}
