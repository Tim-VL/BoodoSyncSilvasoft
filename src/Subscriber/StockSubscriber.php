<?php

declare(strict_types=1);

namespace BoodoSyncSilvasoft\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Content\Product\ProductDefinition;
use BoodoSyncSilvasoft\Service\StockSyncService;
use Shopware\Core\Framework\Context;
use Psr\Log\LoggerInterface;

class StockSubscriber implements EventSubscriberInterface
{
    /**
     * Constructor with required service dependencies.
     */
    public function __construct(
        private readonly StockSyncService $stockSyncService,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Register event subscriber for all entity write events.
     * Will filter to product entity in the handler.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            EntityWrittenEvent::class => 'onProductWritten',
        ];
    }

    /**
     * Handles product entity write events.
     * When 'stock' changes, pushes stock for the specific product to Silvasoft.
     */
    public function onProductWritten(EntityWrittenEvent $event): void
    {
        // Only handle product entity writes
        if ($event->getEntityName() !== ProductDefinition::ENTITY_NAME) {
            return;
        }

        $context = $event->getContext();
        $productIdsToSync = [];

        // Loop through all write results to identify changed products
        foreach ($event->getWriteResults() as $writeResult) {
            $payload = $writeResult->getPayload();

            // Check if the 'stock' field is present in the change set
            if (array_key_exists('stock', $payload)) {
                $productId = $writeResult->getPrimaryKey();

                // Collect product ID for syncing
                if ($productId !== null) {
                    $productIdsToSync[] = $productId;

                    // Log the change
                    $this->logger->info('Stock change detected for product.', [
                        'productId' => $productId,
                        'newStock' => $payload['stock']
                    ]);
                }
            }
        }

        // If there are products to sync, trigger the stock sync
        if (!empty($productIdsToSync)) {
            try {
                $this->stockSyncService->pushStockToSilvasoft($context, $productIdsToSync);
                $this->logger->info('Stock sync to Silvasoft triggered for changed products.', [
                    'productIds' => $productIdsToSync
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Stock sync to Silvasoft failed.', [
                    'error' => $e->getMessage(),
                    'productIds' => $productIdsToSync
                ]);
            }
        }
    }
}
