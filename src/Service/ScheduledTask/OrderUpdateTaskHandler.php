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
class OrderSyncTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        private readonly OrderSyncService $orderSyncService,
        private readonly EntityRepository $orderRepository,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    public static function getHandledMessages(): iterable
    {
        return [OrderSyncTask::class];
    }

    public function run(): void
    {
        $context = Context::createDefaultContext();

        try {
            // Build criteria to find unsynced orders (e.g. via custom field or tag)
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('customFields.silvasoft_synced', false));
            $criteria->addAssociation('lineItems');
            $criteria->addAssociation('billingAddress.country');
            $criteria->addAssociation('deliveries.shippingOrderAddress.country');
            $criteria->addAssociation('transactions.paymentMethod');
            $criteria->addAssociation('orderCustomer.customer');

            $orders = $this->orderRepository->search($criteria, $context);

            if ($orders->count() === 0) {
                $this->logger->info('Silvasoft Order Sync: No unsynced orders found.');
                return;
            }

            foreach ($orders as $order) {
                try {
                    $this->orderSyncService->syncOrder($order, $context);

                    // Optional: mark order as synced
                    $order->setCustomFields(array_merge($order->getCustomFields() ?? [], [
                        'silvasoft_synced' => true
                    ]));

                    $this->orderRepository->update([[
                        'id' => $order->getId(),
                        'customFields' => $order->getCustomFields()
                    ]], $context);

                    $this->logger->info('Silvasoft Order Sync: Order synced.', [
                        'orderNumber' => $order->getOrderNumber()
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->error('Silvasoft Order Sync: Failed to sync order.', [
                        'orderId' => $order->getId(),
                        'error' => $e->getMessage()
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Silvasoft Order Sync: Fatal error.', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
