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

#[AsMessageHandler(handles: StockUpdateTask::class)]
class StockUpdateTaskHandler extends ScheduledTaskHandler
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

    private function fetchProductsFromSilvasoft(int $offset, int $limit): array
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
    
        $this->logger->error('Maximum number of repetitions achieved when retrieving products with offset ' . $offset);
        return [];
    }
    

    private function updateShopwareStockAndCategory(array $silvasoftProducts, Context $context): void
    {
        $productNumbers = [];
        $stockCategoryData = [];

        $offset = 0;
        foreach ($silvasoftProducts as $product) {
            if (!empty($product['ArticleNumber']) && isset($product['StockQty'])) {
                $productNumbers[] = $product['ArticleNumber'];
                // $stockData[$product['ArticleNumber']] = $product['StockQty'];
                $stockCategoryData[$product['ArticleNumber']] = [
                    'StockQty' => $product['StockQty'],
                    'category_tree' => isset($product['ProductResponse_CustomField']) &&
                        isset($product['ProductResponse_CustomField'][0]) &&
                        $product['ProductResponse_CustomField'][0]['Label'] === "shopware_category"
                        ? $product['ProductResponse_CustomField'][0] : null
                ];
            }
        }
        $this->logger->error('Silvasoft Products numbers Data: ' . json_encode($productNumbers, JSON_PRETTY_PRINT));
        if (empty($productNumbers)) {
            return;
        }

        // Produkte aus Shopware anhand der Artikelnummern abrufen
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('productNumber', $productNumbers));
        $products = $this->productRepository->search($criteria, $context)->getEntities();

        if ($products->count() === 0) {
            return;
        }

        // Bestand für alle gefundenen Produkte aktualisieren
        $updates = [];
        /** @var ProductEntity $shopwareProduct */
        foreach ($products as $shopwareProduct) {
            $productNumber = $shopwareProduct->getProductNumber();
            if (isset($stockCategoryData[$productNumber])) {
                $updateItem = [
                    'id' => $shopwareProduct->getId(),
                    'stock' => $stockCategoryData[$productNumber]['StockQty']
                ];

                if (
                    isset($stockCategoryData[$productNumber]['category_tree']) &&
                    isset($stockCategoryData[$productNumber]['category_tree']['StringValue'])
                ) {
                    $this->importCategoryTree(
                        $stockCategoryData[$productNumber]['category_tree']['StringValue'],
                        $shopwareProduct->getId(),
                        $updateItem
                    );
                }
                $updates[] = $updateItem;
            }
        }

        if (!empty($updates)) {
            $this->productRepository->upsert($updates, $context);
        }
    }

    private function getShopwareProductCount(Context $context): int
    {
        $criteria = new Criteria();
        $criteria->addAggregation(new CountAggregation('totalProducts', 'id'));

        $result = $this->productRepository->search($criteria, $context);
        /** @var CountResult|null $count */
        $count = $result->getAggregations()->get('totalProducts');

        return $count ? $count->getCount() : 0;
    }

    public function importCategoryTree(string $categoryTree, string $productId, array &$updateItem): void
    {
        if (!empty($categoryTree)) {
            $categories = explode('>', $categoryTree);
            $categories = array_map('trim', $categories);

            $parentId = null;
            $currentCategoryId = null;

            foreach ($categories as $categoryName) {
                $currentCategoryId = $this->getOrCreateCategory($categoryName, $parentId);
                $parentId = $currentCategoryId;
            }

            if ($currentCategoryId) {
                $updateItem['id'] = $productId;
                $updateItem['categories'] = [
                    ['id' => $currentCategoryId]
                ];

            }
        }
    }

    private function getOrCreateCategory(string $name, ?string $parentId): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $name));
        $criteria->addFilter(new EqualsFilter('parentId', $parentId));
        $criteria->addAssociation('parent');

        $existingCategory = $this->categoryRepository->search($criteria, Context::createDefaultContext())->first();

        if ($existingCategory) {
            return $existingCategory->getId();
        }

        $newCategoryId = Uuid::randomHex();

        $this->categoryRepository->create([
            [
                'id' => $newCategoryId,
                'name' => $name,
                'parentId' => $parentId,
                'active' => true,
            ]
        ], Context::createDefaultContext());

        return $newCategoryId;
    }

    private function assignProductToCategory(string $productId, string $categoryId): void
    {
        $this->productRepository->upsert([
            [
                'id' => $productId,
                'categories' => [
                    ['id' => $categoryId]
                ]
            ]
        ], Context::createDefaultContext());
    }
}
