<?php

declare(strict_types=1);

namespace BoodoSyncSilvasoft\Service\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Psr\Log\LoggerInterface;
use BoodoSyncSilvasoft\Service\OrderSyncService;

#[AsMessageHandler(handles: OrderSyncTask::class)]
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
            // Find unsynced orders
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
                    // Skip if already synced by order number
                    $customFields = $order->getCustomFields() ?? [];
                    if (!empty($customFields['silvasoft_ordernumber'])) {
                        $this->logger->info(
                            'Invoice ' . $order->getOrderNumber() . ' already synced with Silvasoft ID: ' . $customFields['silvasoft_ordernumber']
                        );
                        continue;
                    }

                    // Sync order
                    $this->orderSyncService->syncOrder($order, $context);

                    // Mark as synced
                    $customFields['silvasoft_synced'] = true;

                    $this->orderRepository->update([[
                        'id' => $order->getId(),
                        'customFields' => $customFields
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
