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

    /**
     * Returns the API headers required by the Silvasoft API.
     */
    private function getApiHeaders(): array
    {
        return [
            'Accept-Encoding' => 'gzip,deflate',
            'Content-Type' => 'application/json',
            'ApiKey' => $this->apiKey,
            'Username' => $this->apiUser,
        ];
    }

    /**
     * Decodes a response from Silvasoft. Handles optional gzip encoding.
     */
    private function decodeSilvasoftResponse(string $content): array|string|null
    {
        $decoded = @gzdecode($content) ?: $content;
        return json_decode($decoded, true) ?? $decoded;
    }

    /**
     * Handles customer registration event. Sends customer data to Silvasoft.
     */
    public function onCustomerRegister(CustomerRegisterEvent $event): void{
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

            $data = $this->decodeSilvasoftResponse($response->getContent(false));
            $this->logCustom('Customer successfully synced to Silvasoft.', ['customerId' => $customer->getId()]);
        } catch (\Throwable $e) {
            $this->logCustom('Customer sync failed', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
        }
    }

    /**
     * Handles order placed event. Syncs customer (if needed) and sends invoice to Silvasoft.
     */
    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void{
        $order = $event->getOrder();
        $customer = $order->getOrderCustomer();
        $context = $event->getContext();

        // Load full order with all necessary associations
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

        $billingAddress = $orderFromDb->getBillingAddress();
        $shippingAddress = $order->getDeliveries()?->first()?->getShippingOrderAddress();

        if (!$billingAddress || !$shippingAddress) {
            $this->logCustom('Missing billing or shipping address', ['orderId' => $order->getId()]);
            return;
        }

        $email = strtolower($customer->getEmail());
        $customerNumber = $orderFromDb->getOrderCustomer()?->getCustomer()?->getCustomerNumber() ?? 'GUEST-' . $order->getOrderNumber();

        // Step 1: Check if customer exists in Silvasoft
        $checkCustomerPayload = ["Email" => $email];

        try {
            $checkResponse = $this->httpClient->request('POST', $this->apiUrl . '/rest/checkrelation/', [
                'headers' => $this->getApiHeaders(),
                'json' => $checkCustomerPayload
            ]);

            //$checkData = $this->decodeSilvasoftResponse($checkResponse->getContent(false));
			$data = $this->decodeSilvasoftResponse($response->getContent(false));

            if (isset($checkData['RelationFound']) && $checkData['RelationFound'] === false) {
                // Customer not found - add customer
                $customerPayload = [
                    "Address_City" => $billingAddress->getCity(),
                    "Address_Street" => $billingAddress->getStreet(),
                    "Address_PostalCode" => $billingAddress->getZipcode(),
                    "Address_CountryCode" => $billingAddress->getCountry()?->getIso() ?? '',
                    "IsCustomer" => true,
                    "CustomerNumber" => $customerNumber,
                    "OnExistingRelationEmail" => "ABORT",
                    "OnExistingRelationNumber" => "ABORT",
                    "Relation_Contact" => [[
                        "Email" => $email,
                        "Phone" => $billingAddress->getPhoneNumber() ?? '',
                        "FirstName" => $customer->getFirstName(),
                        "LastName" => $customer->getLastName()
                    ]]
                ];

                $addCustomerResponse = $this->httpClient->request('POST', $this->apiUrl . '/rest/addprivaterelation/', [
                    'headers' => $this->getApiHeaders(),
                    'json' => $customerPayload
                ]);

                $this->decodeSilvasoftResponse($addCustomerResponse->getContent(false));
                $this->logCustom('Customer added to Silvasoft during order sync.', ['customerId' => $customer->getId()]);
            }
        } catch (\Throwable $e) {
            $this->logCustom('Error checking or adding customer to Silvasoft', [
                'error' => $e->getMessage(),
                'payload' => $checkCustomerPayload
            ]);
        }

        // Step 2: Send order as invoice
        $customerComment = $order->getCustomerComment() ?? '';
        $paymentMethod = $order->getTransactions()->first()?->getPaymentMethod()?->getName() ?? '';
        $salesChannel = $orderFromDb->getSalesChannel()?->getTranslation('name') ?? '';
        $formattedOrderDate = $order->getCreatedAt()?->format('Y-m-d') ?? (new \DateTime())->format('Y-m-d');

        $invoicePayload = [
            "CustomerEmail" => $email,
            "InvoiceNotes" =>
                (!empty(trim($customerComment)) ? "<h3>Customer Comment: " . nl2br(htmlspecialchars($customerComment)) . "</h3><br>" : '') .
                "<b>Paymentmethod:</b> " . $paymentMethod . "<br>" .
                "<b>OrderNumber:</b> " . $order->getOrderNumber() . "<br>" .
                "<b>SalesChannel:</b> " . $salesChannel . "<br>" .
                "<b>Order Synced:</b> " . (new \DateTime())->format('c') . "<br>",
            "InvoiceReference" => $order->getOrderNumber(),
            "InvoiceDate" => $formattedOrderDate,
            "Invoice_Contact" => [[
                "ContactType" => "Invoice",
                "Email" => $email,
                "FirstName" => ucwords(strtolower($customer->getFirstName())),
                "LastName" => ucwords(strtolower($customer->getLastName())),
                "DefaultContact" => true
            ]],
            "Invoice_InvoiceLine" => array_values(array_map(function ($lineItem) {
                $price = $lineItem->getPrice();
                $unitPriceGross = $price->getUnitPrice();
                $taxRate = $price->getCalculatedTaxes()->first()?->getTaxRate() ?? 21;
                $unitPriceNet = $unitPriceGross / (1 + ($taxRate / 100));
                $productNumber = $lineItem->getPayload()['productNumber'] ?? 'UNK';

                return [
                    "ProductNumber" => $productNumber,
                    "Quantity" => $lineItem->getQuantity(),
                    "TaxPc" => $taxRate,
                    "UnitPriceExclTax" => round($unitPriceNet, 2),
                    "Description" => $lineItem->getLabel() && trim($lineItem->getLabel()) !== '' ? $lineItem->getLabel() : 'UNK'
                ];
            }, $order->getLineItems()->getElements())),
            "Invoice_Address" => [
                [
                    "Address_Street" => ucwords(strtolower($billingAddress->getStreet() ?? '')),
                    "Address_City" => ucwords(strtolower($billingAddress->getCity() ?? '')),
                    "Address_PostalCode" => $billingAddress->getZipcode() ?? '',
                    "Address_CountryCode" => strtoupper($billingAddress->getCountry()?->getIso() ?? 'unknown'),
                    "Address_Type" => "InvoiceAddress"
                ],
                [
                    "Address_Street" => ucwords(strtolower($shippingAddress->getStreet() ?? '')),
                    "Address_City" => ucwords(strtolower($shippingAddress->getCity() ?? '')),
                    "Address_PostalCode" => $shippingAddress->getZipcode() ?? '',
                    "Address_CountryCode" => strtoupper($shippingAddress->getCountry()?->getIso() ?? 'unknown'),
                    "Address_Type" => "ShippingAddress"
                ]
            ]
        ];

        try {
            $response = $this->httpClient->request('POST', $this->apiUrl . '/rest/addsalesinvoice/', [
                'headers' => $this->getApiHeaders(),
                'json' => $invoicePayload
            ]);

            $this->decodeSilvasoftResponse($response->getContent(false));
            $this->logCustom('Order successfully synced to Silvasoft.', ['orderId' => $order->getId()]);
        } catch (\Throwable $e) {
            $this->logCustom('Order sync to Silvasoft failed', [
                'error' => $e->getMessage(),
                'payload' => $invoicePayload
            ]);
        }
    }

	/**
     * Product Change/Add
     */
    public function onProductWritten(EntityWrittenEvent $event): void {
        foreach ($event->getWriteResults() as $writeResult) {
            $payload = $writeResult->getPayload();
            // Check if it is a new product
            if (!isset($payload['id'])) continue;

            $productId = $payload['id'];
            $product = $this->getProductById($productId, $event->getContext());

            if (!$product) continue;

            $productNumber = $product->getProductNumber();
            $name = $product->getTranslation('name');
            $price = $product->getPrice()->first()->getNet();
           // $categoryName = $this->fetchCategoryName($product);
            $ean = $product->getEan() ? $product->getEan() : '';

            $unit = $product->getUnit() ? $product->getUnit()->getTranslated()['name'] : '';
            $vat = $product->getTax() ? $product->getTax()->getTaxRate() : 21;
            $description = $product->getTranslated()['description'] ? $product->getTranslated()['description'] : '';

            $silvasoftPayload = [
                "ArticleNumber" => $productNumber,
                "NewName" => $name,
                "CategoryName" => 'NEW__ITEMS',
                // "CategoryName" => $this->fetchCategoryName($product) ?? 'UNKNOWN',
                "EAN" => $ean,
                "NewSalePrice" => $price,
                "NewDescription" => $description,
                "NewUnit" => $unit,
                "NewVATPercentage" => $vat,
            ];

            $this->logCustom('Sending product payload to Silvasoft', ['payload' => $silvasoftPayload]);
			

            try {
                $url = $writeResult->getOperation() === 'insert'
                    ? '/rest/addproduct/'
                    : '/rest/updateproduct/';

                $method = $writeResult->getOperation() === 'insert' ? 'POST' : 'PUT';

                $response = $this->httpClient->request($method, $this->apiUrl . $url, [
                    'headers' => $this->getApiHeaders(),
                    'json' => $silvasoftPayload
                ]);

                $content = $response->getContent(false);
                $decoded = @gzdecode($content) ?: $content;
                $data = json_decode($decoded, true);

				$this->logCustom('Product successfully synced to Silvasoft.', ['productNumber' => $productNumber]);
				/*
                $this->logCustom('Silvasoft product API response', [
                    'status' => $response->getStatusCode(),
                    'response' => $data ?? $decoded
				
                ]);
				*/
            } catch (\Throwable $e) {
                $this->logCustom('Product sync failed', ['error' => $e->getMessage()]);
            }
        }
    }


	/**
     * Retrieves a product by ID with tax, price and unit associations.
     */
    private function getProductById(string $productId, Context $context): ?ProductEntity{
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $productId));
        $criteria->addAssociation('tax');
        $criteria->addAssociation('prices');
        $criteria->addAssociation('unit');

        return $this->productRepository->search($criteria, $context)->first();
    }

    /**
     * Logs to /var/log/silvasoft/task-sync-YYYY-MM-DD.log
     */
    private function logCustom(string $message, array $context = []): void{
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
