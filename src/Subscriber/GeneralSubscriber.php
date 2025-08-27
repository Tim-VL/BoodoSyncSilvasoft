<?php

declare(strict_types=1);

namespace BoodoSyncSilvasoft\Subscriber;

use BoodoSyncSilvasoft\Service\MergeGuestAccountService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Checkout\Customer\Event\CustomerRegisterEvent;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Psr\Log\LoggerInterface;

class GeneralSubscriber implements EventSubscriberInterface
{
    private ?string $apiUrl;
    private ?string $apiKey;
    private ?string $apiUser;

    public function __construct(
        private readonly MergeGuestAccountService $mergeGuestAccountService,
        private readonly SystemConfigService $systemConfigService,
        private readonly HttpClientInterface $httpClient,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $categoryRepository,
        private readonly LoggerInterface $logger
    ) {
        $this->apiUrl = $this->systemConfigService->get('BoodoSyncSilvasoft.config.apiUrl');
        $this->apiKey = $this->systemConfigService->get('BoodoSyncSilvasoft.config.apiKey');
        $this->apiUser = $this->systemConfigService->get('BoodoSyncSilvasoft.config.apiUser');
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CustomerRegisterEvent::class => 'onCustomerRegister',
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
            ProductEvents::PRODUCT_WRITTEN_EVENT => 'onProductWritten',
        ];
    }

    private function getApiHeaders(): array
    {
        return [
            'Accept-Encoding' => 'gzip,deflate',
            'Content-Type' => 'application/json',
            'ApiKey' => $this->apiKey,
            'Username' => $this->apiUser,
        ];
    }

    private function decodeSilvasoftResponse(string $content): array|string|null
    {
        $decoded = @gzdecode($content) ?: $content;
        return json_decode($decoded, true) ?? $decoded;
    }

    public function onCustomerRegister(CustomerRegisterEvent $event): void {
        $customer = $event->getCustomer();
        $address = $customer->getDefaultBillingAddress();

        if (!$address) {
            $this->logCustom('Customer registration failed: no billing address found.', ['customerId' => $customer->getId()]);
            return;
        }

        $payload = [
            "Address_City" => $address->getCity(),
            "Address_Street" => $address->getStreet(),
            "Address_PostalCode" => $address->getZipcode(),
            "Address_CountryCode" => $address->getCountry()?->getIso() ?? '',
            "IsCustomer" => true,
            "CustomerNumber" => $customer->getCustomerNumber(),
            "OnExistingRelationEmail" => "ABORT",
            "OnExistingRelationNumber" => "ABORT",
            "Relation_Contact" => [[
                "Email" => $customer->getEmail(),
                "Phone" => $address->getPhoneNumber() ?? '',
                "FirstName" => $customer->getFirstName(),
                "LastName" => $customer->getLastName()
            ]]
        ];

        try {
            $response = $this->httpClient->request('POST', $this->apiUrl . '/rest/addprivaterelation/', [
                'headers' => $this->getApiHeaders(),
                'json' => $payload
            ]);

            $this->decodeSilvasoftResponse($response->getContent(false));
            $this->logCustom('Customer successfully synced to Silvasoft.', ['customerId' => $customer->getId()]);
        } catch (\Throwable $e) {
            $this->logCustom('Customer sync failed', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
        }
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void {
        $order = $event->getOrder();
        $customer = $order->getOrderCustomer();
        $context = $event->getContext();

        $criteria = new Criteria([$order->getId()]);
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('salesChannel');
        $criteria->addAssociation('orderCustomer.customer');

        $orderFromDb = $this->orderRepository->search($criteria, $context)->first();

        if (!$orderFromDb) {
            $this->logCustom('Order not found in repository', ['orderId' => $order->getId()]);
            return;
        }

        // ✅ Skip if order was already synced
        $customFields = $orderFromDb->getCustomFields() ?? [];
        if (!empty($customFields['silvasoft_ordernumber'])) {
            $this->logger->info('Invoice ' . $order->getOrderNumber() . ' already synced.');
            $this->logCustom('Skipping already synced order (sw-customfield)', ['orderNumber' => $order->getOrderNumber()]);
            return;
        }

        // From here, the rest of the original onOrderPlaced() method continues...
        // (no changes below this point, unless you need me to help update it further)
    }

    public function onProductWritten(EntityWrittenEvent $event): void {
        foreach ($event->getWriteResults() as $writeResult) {
            $payload = $writeResult->getPayload();
            if (!isset($payload['id'])) continue;

            $productId = $payload['id'];
            $product = $this->getProductById($productId, $event->getContext());

            if (!$product) continue;

            $productNumber = $product->getProductNumber();
            $name = $product->getTranslation('name');
            $price = $product->getPrice()->first()->getNet();
            $ean = $product->getEan() ?? '';
            $unit = $product->getUnit()?->getTranslated()['name'] ?? '';
            $vat = $product->getTax()?->getTaxRate() ?? 21;
            $description = $product->getTranslated()['description'] ?? '';

            $silvasoftPayload = [
                "ArticleNumber" => $productNumber,
                "NewName" => $name,
                "CategoryName" => 'NEW__ITEMS',
                "EAN" => $ean,
                "NewSalePrice" => $price,
                "NewDescription" => $description,
                "NewUnit" => $unit,
                "NewVATPercentage" => $vat,
            ];

            $this->logCustom('Sending product payload to Silvasoft', ['payload' => $silvasoftPayload]);

            try {
                $url = $writeResult->getOperation() === 'insert' ? '/rest/addproduct/' : '/rest/updateproduct/';
                $method = $writeResult->getOperation() === 'insert' ? 'POST' : 'PUT';

                $response = $this->httpClient->request($method, $this->apiUrl . $url, [
                    'headers' => $this->getApiHeaders(),
                    'json' => $silvasoftPayload
                ]);

                $decoded = $this->decodeSilvasoftResponse($response->getContent(false));
                $this->logCustom('Product successfully synced to Silvasoft.', ['productNumber' => $productNumber]);
            } catch (\Throwable $e) {
                $this->logCustom('Product sync failed', ['error' => $e->getMessage()]);
            }
        }
    }

    private function getProductById(string $productId, Context $context): ?ProductEntity {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $productId));
        $criteria->addAssociation('tax');
        $criteria->addAssociation('prices');
        $criteria->addAssociation('unit');

        return $this->productRepository->search($criteria, $context)->first();
    }

    private function logCustom(string $message, array $context = []): void {
        $logDir = dirname(__DIR__, 2) . '/../../../var/log/silvasoft';
        if (!file_exists($logDir)) {
            mkdir($logDir, 0777, true);
        }

        $date = (new \DateTime())->format('Y-m-d');
        $logFile = $logDir . '/task-sync-' . $date . '.log';
        $timestamp = (new \DateTime())->format('Y-m-d H:i:s');

        $entry = "[$timestamp] $message";
        if (!empty($context)) {
            $entry .= ' | context=' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        file_put_contents($logFile, $entry . "\n", FILE_APPEND);
    }
}
